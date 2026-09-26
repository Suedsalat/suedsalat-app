<?php
declare(strict_types=1);

namespace Suedsalat;

/**
 * Laenge einer MP3-Datei ohne Zusatzbibliothek: ID3-Kopf ueberspringen, ersten MPEG-Frame lesen.
 * Mit Xing/Info- oder VBRI-Kopf (variable Bitrate) ueber die Frame-Anzahl, sonst ueber
 * Dateigroesse und Bitrate (konstante Bitrate). Fuer <itunes:duration> neuer Folgen.
 */
final class Mp3Info
{
    private const BITRATES = [
        // [MPEG1 Layer III, MPEG2/2.5 Layer III] in kbit/s, Index 0 und 15 ungueltig
        1 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0],
        2 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0],
    ];
    private const SAMPLE_RATES = [
        3 => [44100, 48000, 32000], // MPEG1
        2 => [22050, 24000, 16000], // MPEG2
        0 => [11025, 12000, 8000],  // MPEG2.5
    ];

    /** Laenge in Sekunden oder null, wenn die Datei keine lesbare MP3 ist. */
    public static function durationSeconds(string $path): ?int
    {
        $size = @filesize($path);
        $fh = @fopen($path, 'rb');
        if ($size === false || $fh === false) {
            return null;
        }
        $head = (string) fread($fh, 10);
        $offset = 0;
        if (strncmp($head, 'ID3', 3) === 0 && strlen($head) === 10) {
            // Syncsafe-Groesse: 4 x 7 Bit
            $offset = 10 + ((ord($head[6]) & 0x7F) << 21 | (ord($head[7]) & 0x7F) << 14 | (ord($head[8]) & 0x7F) << 7 | (ord($head[9]) & 0x7F));
        }
        fseek($fh, $offset);
        $buf = (string) fread($fh, 65536);
        fclose($fh);

        for ($i = 0, $n = strlen($buf) - 4; $i < $n; $i++) {
            if (ord($buf[$i]) !== 0xFF || (ord($buf[$i + 1]) & 0xE0) !== 0xE0) {
                continue;
            }
            $b1 = ord($buf[$i + 1]);
            $b2 = ord($buf[$i + 2]);
            $b3 = ord($buf[$i + 3]);
            $version = ($b1 >> 3) & 0x03;   // 3 = MPEG1, 2 = MPEG2, 0 = MPEG2.5
            $layer = ($b1 >> 1) & 0x03;     // 1 = Layer III
            $bitrateIndex = ($b2 >> 4) & 0x0F;
            $rateIndex = ($b2 >> 2) & 0x03;
            if ($version === 1 || $layer !== 1 || $bitrateIndex === 0 || $bitrateIndex === 15 || $rateIndex === 3) {
                continue;
            }
            $sampleRate = self::SAMPLE_RATES[$version][$rateIndex];
            $bitrate = self::BITRATES[$version === 3 ? 1 : 2][$bitrateIndex] * 1000;
            $samplesPerFrame = $version === 3 ? 1152 : 576;
            $mono = (($b3 >> 6) & 0x03) === 3;

            // Xing/Info-Kopf steht hinter den Seiteninformationen des ersten Frames.
            $sideInfo = $version === 3 ? ($mono ? 17 : 32) : ($mono ? 9 : 17);
            $xing = substr($buf, $i + 4 + $sideInfo, 12);
            if ((str_starts_with($xing, 'Xing') || str_starts_with($xing, 'Info')) && (ord($xing[7]) & 0x01)) {
                $frames = unpack('N', substr($xing, 8, 4))[1];
                return (int) round($frames * $samplesPerFrame / $sampleRate);
            }
            $vbri = substr($buf, $i + 36, 18);
            if (str_starts_with($vbri, 'VBRI')) {
                $frames = unpack('N', substr($vbri, 14, 4))[1];
                return (int) round($frames * $samplesPerFrame / $sampleRate);
            }
            return (int) round(($size - $offset - $i) * 8 / $bitrate);
        }
        return null;
    }

    /** Wie im Feed ueblich: "46:05" bzw. "1:02:03". */
    public static function format(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
    }
}
