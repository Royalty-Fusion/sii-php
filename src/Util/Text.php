<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Util;

/**
 * Utilidades de texto para cumplir con las reglas del SII:
 * - los XML se envían en ISO-8859-1, por lo que se eliminan/transliteran caracteres fuera de Latin-1;
 * - se eliminan caracteres de control;
 * - se escapan los 5 caracteres especiales de XML (&, <, >, ", ').
 */
final class Text
{
    private const REPLACEMENTS = [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"',
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{2015}" => '-', "\u{2212}" => '-',
        "\u{2026}" => '...', "\u{2022}" => '-', "\u{00A0}" => ' ', "\u{2007}" => ' ',
        "\u{202F}" => ' ', "\u{2009}" => ' ', "\u{200B}" => '', "\u{FEFF}" => '',
        "\u{20AC}" => 'EUR', "\u{2122}" => 'TM', "\u{2116}" => 'No',
    ];

    /** Deja el texto en una forma segura para ISO-8859-1 y sin caracteres de control. */
    public static function sanitize(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            // Se asume que llega en ISO-8859-1 / Windows-1252 desde sistemas antiguos.
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }
        $value = strtr($value, self::REPLACEMENTS);
        // Elimina caracteres de control excepto tabulación y saltos de línea.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}]/u', '', $value);
        $value = str_replace("\r\n", "\n", $value);

        $out = '';
        foreach (mb_str_split($value, 1, 'UTF-8') as $char) {
            $cp = mb_ord($char, 'UTF-8');
            if ($cp !== false && $cp <= 0xFF) {
                $out .= $char;
                continue;
            }
            if (preg_match('/[\p{L}\p{N}]/u', $char) !== 1) {
                continue; // símbolos, emojis, etc.: no representables en ISO-8859-1
            }
            $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $char);
            $out .= ($translit === false || $translit === '?') ? '' : $translit;
        }

        return trim($out);
    }

    public static function truncate(string $value, int $length): string
    {
        return mb_strlen($value, 'UTF-8') > $length ? rtrim(mb_substr($value, 0, $length, 'UTF-8')) : $value;
    }

    public static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    /** Escapa &, <, >, " y ' tal como lo exige el SII. */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_XML1 | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Convierte UTF-8 a ISO-8859-1 (los caracteres fuera de rango ya fueron saneados). */
    public static function toLatin1(string $utf8): string
    {
        return mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');
    }

    public static function toUtf8(string $latin1): string
    {
        return mb_convert_encoding($latin1, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Formatea un número decimal sin notación científica y sin ceros sobrantes.
     */
    public static function decimal(float|int|string $value, int $decimals): string
    {
        if (\is_int($value)) {
            return (string) $value;
        }
        $formatted = number_format(round((float) $value, $decimals), $decimals, '.', '');
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '-0' ? '0' : $formatted;
    }
}
