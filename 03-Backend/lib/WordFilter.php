<?php
declare(strict_types=1);

namespace Suedsalat;

/**
 * Einfacher Wortfilter gegen offensichtliche Beleidigungen in oeffentlichen Beitraegen
 * (Rezensionen, Kommentare, Spitznamen) - Apple verlangt so etwas fuer Apps mit Nutzerbeitraegen.
 *
 * Bewusst eng gefasst: nur eindeutige Beschimpfungen und Herabwuerdigungen, keine Woerter, die in
 * einer Film- oder Locationkritik normal vorkommen ("Nazi" bei Kriegsfilmen, "Idiot" als Filmfigur,
 * "Bastard" in Filmtiteln, "Spastik" als Krankheit). Was durchrutscht, faengt das Melden ab.
 * Verglichen wird wortweise, damit "Tischlampe" nicht an "schlampe" haengen bleibt.
 */
final class WordFilter
{
    /** Ein Wort, das mit einem dieser Staemme BEGINNT, ist eine Beleidigung ("Hurensoehne"). */
    private const STEMS = [
        'arschloch', 'arschgeige', 'wichser', 'fotze', 'hurensohn', 'hurensoehn', 'huhrensohn', 'missgeburt',
        'schlampe', 'nutte', 'schwuchtel', 'kanake', 'neger', 'drecksau', 'drecksack', 'hurenkind',
        'mistkerl', 'miststueck', 'dreckshure', 'kinderficker', 'judensau', 'untermensch',
        'vollidiot', 'volltrottel',
    ];

    /** Nur als GANZES Wort ("Spastik" und "Huren-" als Wortteil bleiben erlaubt). */
    private const WORDS = ['spast', 'spasti', 'spacko', 'hure', 'huren'];

    /** Zwei aufeinanderfolgende Woerter, zusammengeschrieben verglichen ("fick dich"). */
    private const PHRASES = ['fickdich', 'fickteuch', 'verpissdich', 'haltsmaul'];

    public static function containsInsult(string $text): bool
    {
        $words = self::words($text);
        foreach ($words as $i => $word) {
            if (in_array($word, self::WORDS, true)) {
                return true;
            }
            foreach (self::STEMS as $stem) {
                if (str_starts_with($word, $stem)) {
                    return true;
                }
            }
            if (isset($words[$i + 1]) && in_array($word . $words[$i + 1], self::PHRASES, true)) {
                return true;
            }
            if (in_array($word, self::PHRASES, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Text als Liste vereinfachter Woerter: klein, ae/oe/ue/ss, typische Ersetzungen zurueckgedreht
     * (4 -> a, 3 -> e, @ -> a ...), und Folgen EINZELNER Buchstaben zusammengezogen
     * ("A r s c h l o c h" -> "arschloch") - normale Woerter bleiben getrennt.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $t = mb_strtolower(normalize_input($text), 'UTF-8');
        $t = strtr($t, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', '0' => 'o', '1' => 'i', '3' => 'e',
            '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's']);
        $raw = preg_split('/[^a-z]+/', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $words = [];
        $letters = '';
        foreach ($raw as $w) {
            if (strlen($w) === 1) {
                $letters .= $w;
                continue;
            }
            if (strlen($letters) > 1) {
                $words[] = $letters;
            }
            $letters = '';
            $words[] = $w;
        }
        if (strlen($letters) > 1) {
            $words[] = $letters;
        }
        return $words;
    }
}
