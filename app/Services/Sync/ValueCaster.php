<?php

namespace App\Services\Sync;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Kthen vlerat e cell-ave nga Sheets ne format qe i pershtatet kolones ne databaze.
 *
 * Therrasim API me UNFORMATTED_VALUE + SERIAL_NUMBER, keshtu numrat vijne si numra
 * dhe datat si serial (dite qe nga 1899-12-30). Nuk varet nga gjuha e sheet-it.
 * Nese data eshte shkruar si tekst, e bejme PARSED prap.
 */
final class ValueCaster
{
    public const TYPES = ['string', 'text', 'integer', 'decimal', 'boolean', 'date', 'datetime'];

    private const MAX_SERIAL = 2958465; // 9999-12-31

    /**
     * @throws InvalidArgumentException when the value cannot be converted
     */
    public function cast(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }
        }

        return match ($type) {
            'string', 'text' => $this->toString($value),
            'integer' => $this->toInteger($value),
            'decimal' => $this->toDecimal($value),
            'boolean' => $this->toBoolean($value),
            'date' => $this->toDateTime($value)->format('Y-m-d'),
            'datetime' => $this->toDateTime($value)->format('Y-m-d H:i:s'),
            default => throw new InvalidArgumentException("Unknown data type '{$type}'."),
        };
    }

    private function toString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_float($value)) {
            return $this->floatToString($value);
        }

        return (string) $value;
    }

    private function toInteger(mixed $value): int
    {
        if (is_bool($value)) {
            throw new InvalidArgumentException('a boolean is not a whole number');
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (floor($value) !== $value) {
                throw new InvalidArgumentException("'{$this->floatToString($value)}' is not a whole number");
            }

            return (int) $value;
        }

        $clean = preg_replace('/[\s,]/', '', (string) $value) ?? '';

        if (! preg_match('/^[+-]?\d+(\.0+)?$/', $clean)) {
            throw new InvalidArgumentException("'{$this->shorten($value)}' is not a whole number");
        }

        return (int) $clean;
    }

/**
 * E kthejme si string numerik qe te mos humbim precizion para se databaza ta rregulloje.
 */
    private function toDecimal(mixed $value): string
    {
        if (is_bool($value)) {
            throw new InvalidArgumentException('a boolean is not a number');
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return $this->floatToString($value);
        }

        return $this->normalizeNumberString((string) $value);
    }

    private function normalizeNumberString(string $raw): string
    {
        // Le vetem shifrat, ndaresit dhe shenjen... heq monedhat dhe hapesirat (psh "$ 1,234.50" ose "12 €").
        $s = preg_replace('/[^\d.,+\-]/u', '', $raw) ?? '';

        if ($s === '' || ! preg_match('/\d/', $s)) {
            throw new InvalidArgumentException("'{$this->shorten($raw)}' is not a number");
        }

        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            // Ai qe del i fundit eshte ndaresi i pikes (decimal).
            $decimal = strrpos($s, ',') > strrpos($s, '.') ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $s = str_replace($thousands, '', $s);
            $s = str_replace($decimal, '.', $s);
        } elseif ($hasComma) {
            // "1,234" / "1,234,567" jane ndares te mijerave; "12,5" / "3,75" jane presje decimale.
            $s = preg_match('/^[+-]?\d{1,3}(,\d{3})+$/', $s)
                ? str_replace(',', '', $s)
                : str_replace(',', '.', $s);
        } elseif ($hasDot && preg_match('/^[+-]?\d{1,3}(\.\d{3}){2,}$/', $s)) {
            $s = str_replace('.', '', $s); // "1.234.567"
        }

        if (! preg_match('/^[+-]?(\d+(\.\d+)?|\.\d+)$/', $s)) {
            throw new InvalidArgumentException("'{$this->shorten($raw)}' is not a number");
        }

        return $s;
    }

    private function toBoolean(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) || is_float($value)) {
            if ($value == 1) {
                return 1;
            }

            if ($value == 0) {
                return 0;
            }

            throw new InvalidArgumentException("'{$this->floatToString((float) $value)}' is not a boolean");
        }

        return match (mb_strtolower((string) $value)) {
            'true', 'yes', 'y', '1', 'on', 'x' => 1,
            'false', 'no', 'n', '0', 'off' => 0,
            default => throw new InvalidArgumentException("'{$this->shorten($value)}' is not a boolean"),
        };
    }

    private function toDateTime(mixed $value): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        if (is_int($value) || is_float($value)) {
            if ($value < 0 || $value > self::MAX_SERIAL) {
                throw new InvalidArgumentException("'{$this->floatToString((float) $value)}' is not a valid date serial number");
            }

            $seconds = (int) round($value * 86400);

            return (new DateTimeImmutable('1899-12-30 00:00:00', $utc))->modify(sprintf('%+d seconds', $seconds));
        }

        if (is_bool($value)) {
            throw new InvalidArgumentException('a boolean is not a date');
        }

        $text = (string) $value;

        try {
            $date = new DateTimeImmutable($text, $utc);
        } catch (\Exception) {
            throw new InvalidArgumentException("'{$this->shorten($text)}' is not a recognised date");
        }

        $errors = DateTimeImmutable::getLastErrors();

        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException("'{$this->shorten($text)}' is not a valid date");
        }

        return $date;
    }

    private function floatToString(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');

        return ($formatted === '' || $formatted === '-') ? '0' : $formatted;
    }

    private function shorten(mixed $value): string
    {
        $text = (string) $value;

        return mb_strlen($text) > 40 ? mb_substr($text, 0, 40).'…' : $text;
    }
}