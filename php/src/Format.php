<?php

declare(strict_types=1);

namespace Framework\Embed;

/**
 * Number and value formatting. SPEC.md, "Number formatting", defines the rules;
 * js/src/format.js implements the same ones. Rounding works on the shortest
 * round-trip decimal form of a float, so both languages agree digit for digit.
 */
final class Format
{
    public const EMPTY = "\u{2014}";

    private const P_VALUE_NAMES = ['p_value', 'p.value', 'p-value', 'pvalue', 'pr(>|t|)', 'pr(>|z|)', 'pr(>chisq)', 'p>|t|', 'p>|z|'];

    public static function isNumber(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    public static function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
    }

    public static function isPValueName(mixed $name): bool
    {
        return is_string($name) && in_array(strtolower($name), self::P_VALUE_NAMES, true);
    }

    public static function asciiCapitalize(string $value): string
    {
        return preg_match('/^[a-z]/', $value) === 1 ? ucfirst($value) : $value;
    }

    /** @return array{0: string, 1: int} The shortest round-trip digits of |x| and the exponent of the first digit. */
    private static function decompose(float $x): array
    {
        $x = abs($x);
        for ($precision = 0; $precision <= 16; $precision++) {
            // %e follows LC_NUMERIC, so normalize the decimal separator.
            $candidate = preg_replace('/[^0-9e+\-]/', '.', sprintf('%.' . $precision . 'e', $x));
            if ((float) $candidate === $x) {
                break;
            }
        }
        [$mantissa, $exponent] = explode('e', $candidate);
        $digits = rtrim(str_replace('.', '', $mantissa), '0');

        return [$digits === '' ? '0' : $digits, (int) $exponent];
    }

    private static function increment(string $digits): string
    {
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] === '9') {
                $digits[$i] = '0';
            } else {
                $digits[$i] = (string) ((int) $digits[$i] + 1);

                return $digits;
            }
        }

        return '1' . $digits;
    }

    private static function fixedDigits(float $x, int $places): string
    {
        if ($x == 0.0) {
            $whole = '0';
        } else {
            [$digits, $exp] = self::decompose($x);
            $keep = $exp + 1 + $places;
            if ($keep < 0) {
                $whole = '0';
            } elseif ($keep === 0) {
                $whole = $digits[0] >= '5' ? '1' : '0';
            } else {
                $head = strlen($digits) >= $keep ? substr($digits, 0, $keep) : $digits . str_repeat('0', $keep - strlen($digits));
                if (strlen($digits) > $keep && $digits[$keep] >= '5') {
                    $head = self::increment($head);
                }
                $whole = ltrim($head, '0');
                $whole = $whole === '' ? '0' : $whole;
            }
        }
        if ($places === 0) {
            return $whole;
        }
        $whole = str_pad($whole, $places + 1, '0', STR_PAD_LEFT);

        return substr($whole, 0, -$places) . '.' . substr($whole, -$places);
    }

    private static function group(string $integer): string
    {
        if (strlen($integer) < 5) {
            return $integer;
        }
        $out = '';
        $length = strlen($integer);
        for ($i = 0; $i < $length; $i++) {
            if ($i > 0 && ($length - $i) % 3 === 0) {
                $out .= ',';
            }
            $out .= $integer[$i];
        }

        return $out;
    }

    public static function fixed(int|float $x, int $places = 3): string
    {
        $x = (float) $x;
        if (! is_finite($x)) {
            return self::EMPTY;
        }
        $parts = explode('.', self::fixedDigits($x, $places));
        $body = count($parts) === 1 ? self::group($parts[0]) : self::group($parts[0]) . '.' . $parts[1];

        return $x < 0 && preg_match('/[1-9]/', $body) === 1 ? '-' . $body : $body;
    }

    public static function scientific(int|float $x, int $significant = 3): string
    {
        $x = (float) $x;
        if (! is_finite($x)) {
            return self::EMPTY;
        }
        if ($x == 0.0) {
            return '0.' . str_repeat('0', $significant - 1) . 'e+0';
        }
        [$digits, $exp] = self::decompose($x);
        $head = strlen($digits) >= $significant ? substr($digits, 0, $significant) : $digits . str_repeat('0', $significant - strlen($digits));
        if (strlen($digits) > $significant && $digits[$significant] >= '5') {
            $head = self::increment($head);
        }
        if (strlen($head) > $significant) {
            $head = substr($head, 0, $significant);
            $exp++;
        }
        $mantissa = $significant > 1 ? $head[0] . '.' . substr($head, 1) : $head;

        return ($x < 0 ? '-' : '') . $mantissa . 'e' . ($exp < 0 ? '-' : '+') . abs($exp);
    }

    /** Decimal places for data tables. */
    public const DATA_PLACES = 3;

    /** Decimal places for model and test results. */
    public const RESULT_PLACES = 2;

    /**
     * A number in a table cell, statistic, or metric. $decimal forces $places decimals for whole numbers.
     * No scientific notation: large numbers are written in full, and a nonzero number that rounds to
     * zero is written as "< 0.01" (or "> -0.01"), with $places decimals.
     */
    public static function number(mixed $x, bool $decimal = false, int $places = self::DATA_PLACES): string
    {
        if (! self::isNumber($x)) {
            return self::EMPTY;
        }
        if (! $decimal && self::isInteger($x)) {
            return self::fixed($x, 0);
        }
        $fixed = self::fixed($x, $places);
        if ($x != 0 && preg_match('/[1-9]/', $fixed) !== 1) {
            $smallest = '0.' . str_repeat('0', $places - 1) . '1';

            return $x > 0 ? '< ' . $smallest : '> -' . $smallest;
        }

        return $fixed;
    }

    public static function pValue(mixed $p): string
    {
        if (! self::isNumber($p)) {
            return self::value($p);
        }

        return $p < 0.001 ? '< 0.001' : self::fixed($p, 3);
    }

    public static function percent(mixed $x): string
    {
        if (! self::isNumber($x)) {
            return self::value($x);
        }

        $percent = (float) $x * 100;

        return is_finite($percent) ? preg_replace('/\.0$/', '', self::fixed($percent, 1)) . '%' : self::EMPTY;
    }

    public static function value(mixed $value, bool $decimal = false, int $places = self::DATA_PLACES): string
    {
        return match (true) {
            $value === null => self::EMPTY,
            is_bool($value) => $value ? 'Yes' : 'No',
            is_int($value), is_float($value) => self::number($value, $decimal, $places),
            is_string($value) => $value,
            default => self::EMPTY,
        };
    }

    /** ECMAScript Number::toString for a finite float, as JSON.stringify writes it. */
    public static function jsNumber(int|float $x): string
    {
        if (is_int($x) && abs($x) <= 9007199254740992) {
            return (string) $x;
        }
        $x = (float) $x;
        if (! is_finite($x)) {
            return 'null';
        }
        if ($x == 0.0) {
            return '0';
        }
        [$digits, $exp] = self::decompose($x);
        $k = strlen($digits);
        $n = $exp + 1;
        if ($k <= $n && $n <= 21) {
            $out = $digits . str_repeat('0', $n - $k);
        } elseif (0 < $n && $n <= 21) {
            $out = substr($digits, 0, $n) . '.' . substr($digits, $n);
        } elseif (-6 < $n && $n <= 0) {
            $out = '0.' . str_repeat('0', -$n) . $digits;
        } else {
            $e = $n - 1;
            $out = ($k === 1 ? $digits : $digits[0] . '.' . substr($digits, 1)) . 'e' . ($e < 0 ? '-' : '+') . abs($e);
        }

        return ($x < 0 ? '-' : '') . $out;
    }

    private const LN2_HI = [6.93147180369123816490e-01, -6.93147180369123816490e-01];

    private const LN2_LO = [1.90821492927058770002e-10, -1.90821492927058770002e-10];

    private static function pow2(int $k): float
    {
        $result = 1.0;
        $base = $k < 0 ? 0.5 : 2.0;
        $n = abs($k);
        while ($n > 0) {
            if ($n % 2 === 1) {
                $result *= $base;
            }
            $base *= $base;
            $n = intdiv($n, 2);
        }

        return $result;
    }

    /** fdlibm's exp with only IEEE basic operations, so it matches the JS renderer bit for bit. */
    public static function exp(int|float $x): float
    {
        $x = (float) $x;
        if (! is_finite($x)) {
            return NAN;
        }
        if ($x > 7.09782712893383973096e+02) {
            return INF;
        }
        if ($x < -7.45133219101941108420e+02) {
            return 0.0;
        }
        $sign = $x < 0 ? 1 : 0;
        $size = abs($x);
        $hi = 0.0;
        $lo = 0.0;
        $k = 0;
        if ($size > 0.5 * self::LN2_HI[0]) {
            if ($size < 1.5 * self::LN2_HI[0]) {
                $hi = $x - self::LN2_HI[$sign];
                $lo = self::LN2_LO[$sign];
                $k = 1 - $sign - $sign;
            } else {
                $k = (int) (1.44269504088896338700e+00 * $x + ($sign ? -0.5 : 0.5));
                $hi = $x - $k * self::LN2_HI[0];
                $lo = $k * self::LN2_LO[0];
            }
            $x = $hi - $lo;
        } elseif ($size < 3.725290298461914e-9) {
            return 1 + $x;
        }
        $t = $x * $x;
        $c = $x - $t * (1.66666666666666019037e-01 + $t * (-2.77777777770155933842e-03 + $t * (6.61375632143793436117e-05
            + $t * (-1.65339022054652515390e-06 + $t * 4.13813679705723846039e-08))));
        if ($k === 0) {
            return 1 - (($x * $c) / ($c - 2) - $x);
        }
        $y = 1 - (($lo - ($x * $c) / (2 - $c)) - $hi);

        return $k >= -1021 ? $y * self::pow2($k) : $y * self::pow2($k + 1000) * self::pow2(-1000);
    }
}
