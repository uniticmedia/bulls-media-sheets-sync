<?php

namespace App\Services\Sheets;

interface SheetsClient
{
    /**
     * Kthen cdo rresht (perfshire header-in) si liste e qelizave te listes.
     * Numrat jane numra tamam, datat jane nr serie (dite psh qe nga 1899-12-30).
     *
     * @return array<int, array<int, mixed>>
     *
     * @throws \RuntimeException me mesazh kur tab-i nuk lexohet
     */
    public function values(string $spreadsheetId, string $tab): array;
}