<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed\Modifiers;

use FrameworkCloud\StatamicEmbed\Embedder;
use Statamic\Modifiers\Modifier;

/**
 * {{ content | framework_embeds }}: turns each paragraph that holds only a
 * Framework artifact URL into the artifact. Works on rendered Bard and
 * Markdown HTML.
 */
class FrameworkEmbeds extends Modifier
{
    protected static $handle = 'framework_embeds';

    public function index($value, $params, $context)
    {
        if (! is_string($value) && ! (is_object($value) && method_exists($value, '__toString'))) {
            return $value;
        }

        return app(Embedder::class)->convertParagraphs((string) $value);
    }
}
