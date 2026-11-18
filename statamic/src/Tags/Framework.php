<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed\Tags;

use Framework\Embed\Assets;
use FrameworkCloud\StatamicEmbed\Embedder;
use Statamic\Tags\Tags;

/**
 * {{ framework:artifact url="…" }}, {{ framework:styles }}, and {{ framework:scripts }}.
 */
class Framework extends Tags
{
    protected static $handle = 'framework';

    /**
     * {{ framework:artifact field="handle" }} or :from="handle" renders a
     * framework_artifact field's stored copy. {{ framework:artifact url="…" }}
     * renders a URL through the live cache.
     */
    public function artifact(): string
    {
        $embedder = app(Embedder::class);
        if ($this->params->has('field')) {
            $value = $this->context->get($this->params->get('field'));

            return $embedder->renderStored($value instanceof \Statamic\Fields\Value ? $value->raw() : $value);
        }
        if ($this->params->has('from')) {
            $value = $this->params->get('from');
            $value = $value instanceof \Statamic\Fields\Value ? $value->raw() : $value;
            if (is_array($value) && isset($value['html']) && ! isset($value['document'])) {
                return (string) $value['html'];
            }

            return $embedder->renderStored($value);
        }

        return $embedder->render((string) $this->params->get('url', ''));
    }

    /** The stylesheet link. Put it in the layout's <head>. */
    public function styles(): string
    {
        return '<link rel="stylesheet" href="' . e(self::assetUrl('framework-embed.css')) . '">';
    }

    /**
     * The script that draws Vega-Lite charts and opens variable tips on hover,
     * deferred. Put it before </body>. Pass only_if_charts="true" to print it
     * only when a chart or a variable tip was rendered earlier in the same request.
     */
    public function scripts(): string
    {
        if ($this->params->bool('only_if_charts', false) && ! app(Embedder::class)->sawChart()) {
            return '';
        }

        return '<script src="' . e(self::assetUrl('framework-embed.iife.js')) . '" defer></script>';
    }

    /** A published asset's URL, with a version for cache busting. */
    public static function assetUrl(string $file): string
    {
        return asset('vendor/statamic-embed/' . $file) . '?v=' . Assets::version();
    }
}
