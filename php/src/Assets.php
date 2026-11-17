<?php

declare(strict_types=1);

namespace Framework\Embed;

/**
 * The stylesheet and scripts built from the js package, copied into assets/
 * so plugins can enqueue them: WordPress wp_enqueue_style('framework-embed', ..., Assets::version()).
 */
final class Assets
{
    public static function directory(): string
    {
        return dirname(__DIR__) . '/assets';
    }

    public static function css(): string
    {
        return self::directory() . '/framework-embed.css';
    }

    /** The script-tag build: defines <framework-artifact> and draws charts. */
    public static function script(): string
    {
        return self::directory() . '/framework-embed.iife.js';
    }

    /** The ES module build. */
    public static function module(): string
    {
        return self::directory() . '/framework-embed.js';
    }

    /** A version string for cache busting, from the built files. */
    public static function version(): string
    {
        $hash = '';
        foreach ([self::css(), self::script()] as $file) {
            $hash .= is_file($file) ? (string) md5_file($file) : '';
        }

        return substr(md5($hash), 0, 12);
    }
}
