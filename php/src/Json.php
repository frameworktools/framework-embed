<?php

declare(strict_types=1);

namespace Framework\Embed;

use stdClass;

/**
 * JSON written exactly as JavaScript's JSON.stringify writes it, then made safe
 * for a <script type="application/json"> element. The JS renderer uses
 * JSON.stringify, so both renderers emit the same bytes.
 */
final class Json
{
    public static function forScript(mixed $value): string
    {
        // Each of these becomes a JSON \u escape, so no HTML parser sees them.
        $replacements = [];
        foreach (['<' => 0x3C, '>' => 0x3E, '&' => 0x26, "\xE2\x80\xA8" => 0x2028, "\xE2\x80\xA9" => 0x2029] as $character => $code) {
            $replacements[$character] = sprintf('%su%04x', '\\', $code);
        }

        return strtr(self::stringify($value), $replacements);
    }

    public static function stringify(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return Format::jsNumber($value);
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if (is_array($value) && array_is_list($value)) {
            return '[' . implode(',', array_map(self::stringify(...), $value)) . ']';
        }
        if (is_array($value) || $value instanceof stdClass) {
            $entries = [];
            foreach (self::orderedKeys(is_array($value) ? $value : get_object_vars($value)) as [$key, $item]) {
                $entries[] = self::string($key) . ':' . self::stringify($item);
            }

            return '{' . implode(',', $entries) . '}';
        }

        return 'null';
    }

    /** JavaScript property order: array-index keys ascending, then the rest in insertion order. */
    private static function orderedKeys(array $object): array
    {
        $indexes = [];
        $names = [];
        foreach ($object as $key => $item) {
            $key = (string) $key;
            if (preg_match('/^(0|[1-9][0-9]{0,9})$/D', $key) === 1 && (int) $key < 4294967295) {
                $indexes[] = [$key, $item];
            } else {
                $names[] = [$key, $item];
            }
        }
        usort($indexes, fn (array $a, array $b) => (int) $a[0] <=> (int) $b[0]);

        return [...$indexes, ...$names];
    }

    private static function string(string $value): string
    {
        $escaped = preg_replace_callback('/[\x00-\x1f"\\\\]/', static fn (array $m) => match ($m[0]) {
            '"' => '\"', '\\' => '\\\\', "\x08" => '\b', "\f" => '\f', "\n" => '\n', "\r" => '\r', "\t" => '\t',
            default => sprintf('\u%04x', ord($m[0])),
        }, $value);

        return '"' . $escaped . '"';
    }
}
