<?php

namespace App\Services\Sync;

/**
 * Gjen kolonat e konfiguruara ne header-in e sheet-it sipas emrit (jo pozicionit).
 * Keshtu edhe nese kolonat jane ne rend tjeter ose ka kolona ekstra, prap funksionon.
 * Krahasimi eshte case-insensitive dhe injoron pika/hapesira.
 * psh "Product Name", "product_name" dhe "PRODUCT-NAME" jane i njejti header.
 */
final class HeaderMatcher
{
    public static function normalize(mixed $header): string
    {
        $value = mb_strtolower(trim((string) $header));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

        return trim($value);
    }

    /**
     * @param  array<int, mixed>  $headerRow
     * @return array<string, int> emri i header-it (i normalizuar) => indeksi i kolones (0-based). Nese ka dyfishime, merr te parin
     */
    public static function index(array $headerRow): array
    {
        $map = [];

        foreach (array_values($headerRow) as $position => $header) {
            $name = self::normalize($header);

            if ($name !== '' && ! isset($map[$name])) {
                $map[$name] = $position;
            }
        }

        return $map;
    }
}