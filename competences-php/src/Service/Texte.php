<?php
declare(strict_types=1);

namespace App\Service;

final class Texte
{
    private const ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
    ];

    /** Minuscules, sans accents latins (les lettres arabes sont conservées) */
    public static function normaliser(?string $t): string
    {
        $t = mb_strtolower((string) $t, 'UTF-8');
        $t = strtr($t, self::ACCENTS);
        return str_replace(['’', '`'], "'", $t);
    }

    /** Clé de rapprochement des élèves (nom + prénom) */
    public static function cleEleve(?string $nom, ?string $prenom): string
    {
        return trim(preg_replace('/\s+/u', ' ', self::normaliser(($nom ?? '') . ' ' . ($prenom ?? ''))));
    }

    /** Liste unique, nettoyée, à partir d'un tableau ou d'un texte séparé par , ; ou retour ligne */
    public static function liste(mixed $v): array
    {
        if ($v === null || $v === '') {
            return [];
        }
        $arr = is_array($v) ? $v : preg_split('/[\n;,]+/u', (string) $v);
        $out = [];
        foreach ($arr as $s) {
            $s = trim((string) $s);
            if ($s !== '' && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    public static function nombre(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = preg_replace('/[^0-9.\-]/', '', str_replace(',', '.', (string) $v));
        return is_numeric($s) ? (float) $s : null;
    }
}
