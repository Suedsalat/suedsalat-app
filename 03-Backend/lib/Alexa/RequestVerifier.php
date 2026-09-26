<?php
declare(strict_types=1);

namespace Suedsalat\Alexa;

/**
 * Prueft, ob eine Anfrage wirklich von Amazon Alexa kommt - Pflicht fuer selbst betriebene Skills
 * (Amazon lehnt den Skill in der Pruefung sonst ab):
 *  1. Die Zertifikats-Adresse (Kopfzeile SignatureCertChainUrl) zeigt auf Amazons S3-Ablage.
 *  2. Das Zertifikat ist gueltig, gehoert zu echo-api.amazon.com und fuehrt zu einer vertrauenswuerdigen
 *     Zertifizierungsstelle.
 *  3. Die Signatur (Kopfzeile Signature-256, SHA-256) passt zum unveraenderten Anfragetext.
 *  4. Der Zeitstempel ist hoechstens 150 Sekunden alt (Schutz vor wieder eingespielten Anfragen).
 *  5. Die Anfrage gilt unserem Skill (ALEXA_SKILL_ID).
 */
final class RequestVerifier
{
    public const MAX_AGE_SECONDS = 150;

    /** @var callable(string):?string  laedt die Zertifikatskette (PEM) von einer Adresse */
    private $fetch;

    /**
     * @param string|null $caFile  Vertrauenswuerdige Wurzelzertifikate (null = die des Servers)
     * @param callable|null $fetch Fuer Tests austauschbar; Standard: herunterladen und zwischenspeichern
     */
    public function __construct(private readonly ?string $caFile = null, ?callable $fetch = null, private readonly ?int $now = null)
    {
        $this->fetch = $fetch ?? [self::class, 'download'];
    }

    /** Fehlertext oder null, wenn alles stimmt. */
    public function verify(string $body, string $certUrl, string $signature256, string $skillId): ?string
    {
        if (!self::isValidCertUrl($certUrl)) {
            return 'Zertifikats-Adresse ungültig';
        }
        $pem = ($this->fetch)($certUrl);
        if ($pem === null || $pem === '') {
            return 'Zertifikat nicht ladbar';
        }
        $problem = $this->checkCertificate($pem);
        if ($problem !== null) {
            return $problem;
        }
        $signature = base64_decode($signature256, true);
        $publicKey = openssl_pkey_get_public(self::firstCert($pem));
        if ($signature === false || $publicKey === false || openssl_verify($body, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            return 'Signatur passt nicht';
        }
        $request = json_decode($body, true);
        $timestamp = strtotime((string) ($request['request']['timestamp'] ?? ''));
        if ($timestamp === false || abs(($this->now ?? time()) - $timestamp) > self::MAX_AGE_SECONDS) {
            return 'Zeitstempel zu alt';
        }
        $appId = $request['session']['application']['applicationId']
            ?? $request['context']['System']['application']['applicationId'] ?? null;
        if ($skillId === '' || $appId !== $skillId) {
            return 'Anfrage gilt einem anderen Skill';
        }
        return null;
    }

    /** Regeln von Amazon: https, s3.amazonaws.com, Pfad /echo.api/, Port 443. */
    public static function isValidCertUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== 's3.amazonaws.com'
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }
        // Pfad normalisieren ("/echo.api/../x" darf nicht durchrutschen).
        $segments = [];
        foreach (explode('/', $parts['path'] ?? '') as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }
        return ($segments[0] ?? '') === 'echo.api';
    }

    private function checkCertificate(string $pem): ?string
    {
        $leaf = self::firstCert($pem);
        $info = openssl_x509_parse($leaf);
        if ($info === false) {
            return 'Zertifikat unlesbar';
        }
        $now = $this->now ?? time();
        if ($now < $info['validFrom_time_t'] || $now > $info['validTo_time_t']) {
            return 'Zertifikat abgelaufen oder noch nicht gültig';
        }
        $san = (string) ($info['extensions']['subjectAltName'] ?? '');
        if (!preg_match('/(^|,\s*)DNS:echo-api\.amazon\.com(\s*,|$)/', $san)) {
            return 'Zertifikat gehört nicht zu echo-api.amazon.com';
        }
        // Kette bis zur vertrauenswuerdigen Wurzel: Zwischenzertifikate aus der geladenen Datei.
        $chainFile = tempnam(sys_get_temp_dir(), 'alexa');
        file_put_contents($chainFile, $pem);
        $cainfo = $this->caFile !== null ? [$this->caFile] : self::systemCa();
        $ok = openssl_x509_checkpurpose($leaf, X509_PURPOSE_ANY, $cainfo, $chainFile);
        @unlink($chainFile);
        return $ok === true ? null : 'Zertifikatskette nicht vertrauenswürdig';
    }

    /** @return list<string> */
    private static function systemCa(): array
    {
        $locations = openssl_get_cert_locations();
        return array_values(array_filter([$locations['default_cert_file'] ?? null, $locations['default_cert_dir'] ?? null],
            static fn ($p) => is_string($p) && file_exists($p)));
    }

    private static function firstCert(string $pem): string
    {
        return preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m) ? $m[0] : '';
    }

    /** Zertifikat laden und einen Tag zwischenspeichern (Amazon wechselt es selten). */
    public static function download(string $url): ?string
    {
        $cache = sys_get_temp_dir() . '/suedsalat-alexa-' . sha1($url) . '.pem';
        if (is_file($cache) && filemtime($cache) > time() - 86400) {
            return (string) file_get_contents($cache);
        }
        $pem = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
        if ($pem === false || !str_contains($pem, 'BEGIN CERTIFICATE')) {
            return null;
        }
        @file_put_contents($cache, $pem);
        return $pem;
    }
}
