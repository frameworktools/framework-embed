<?php

declare(strict_types=1);

namespace Framework\Embed;

use JsonException;

/** Decoding of framework.embed/v1 JSON. */
final class Document
{
    public const SCHEMA = 'framework.embed/v1';

    /**
     * The document as an array, with `spec` decoded as objects so empty JSON
     * objects in a Vega-Lite specification survive. Null for anything else.
     */
    public static function decode(string $json): ?array
    {
        try {
            $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($doc) || array_is_list($doc) || ($doc['schema'] ?? null) !== self::SCHEMA) {
                return null;
            }
            if (is_array($doc['spec'] ?? null)) {
                $doc['spec'] = json_decode($json, false, 512, JSON_THROW_ON_ERROR)->spec;
            }
        } catch (JsonException) {
            return null;
        }

        return $doc;
    }
}
