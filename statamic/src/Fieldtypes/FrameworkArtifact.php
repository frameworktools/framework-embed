<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed\Fieldtypes;

use Framework\Embed\Document;
use Framework\Embed\EmbedException;
use FrameworkCloud\StatamicEmbed\Embedder;
use FrameworkCloud\StatamicEmbed\Rules\ArtifactRule;
use Statamic\Fields\Fieldtype;

/**
 * A Framework artifact with a stored copy of its embed document.
 *
 * The control panel shows a plain URL input (Statamic's text component), so
 * the addon needs no Vue build. Saving a new URL fetches the artifact and
 * stores, in the entry:
 *
 *   url, embed_url (the pinned version URL), document (the embed document
 *   JSON as sent), fetched_at, removed
 *
 * Saving with the same URL keeps the stored copy. "Fetch again" and "Use
 * newest version" are entry actions that ask for confirmation.
 */
class FrameworkArtifact extends Fieldtype
{
    protected static $handle = 'framework_artifact';

    protected static $title = 'Framework artifact';

    protected $component = 'text';

    protected $icon = 'chart-monitoring-indicator';

    protected $categories = ['media', 'relationship'];

    /** The value stored before this save, when editing an existing entry. */
    private function previous(): ?array
    {
        $parent = $this->field()?->parent();
        if (! is_object($parent) || ! method_exists($parent, 'get')) {
            return null;
        }
        $value = $parent->get($this->field()->handle());

        return is_array($value) ? $value : null;
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [new ArtifactRule($this->previous())]);
    }

    /** Stored value to control panel: the URL. */
    public function preProcess($data)
    {
        return is_array($data) ? (string) ($data['url'] ?? '') : (string) ($data ?? '');
    }

    public function preProcessIndex($data)
    {
        if (! is_array($data)) {
            return (string) ($data ?? '');
        }
        $doc = is_string($data['document'] ?? null) ? Document::decode($data['document']) : null;

        return $doc !== null && is_string($doc['title'] ?? null) ? $doc['title'] : (string) ($data['url'] ?? '');
    }

    /** Control panel to stored value: keep the copy for the same URL, fetch for a new one. */
    public function process($data)
    {
        $url = trim(is_array($data) ? (string) ($data['url'] ?? '') : (string) ($data ?? ''));
        if ($url === '') {
            return null;
        }
        $previous = $this->previous();
        if ($previous !== null && ($previous['url'] ?? null) === $url) {
            return $previous;
        }
        try {
            return app(Embedder::class)->fetchForStorage($url);
        } catch (EmbedException) {
            // Validation reports the failure; without it, keep the URL so the field renders a link.
            return ['url' => $url, 'embed_url' => null, 'document' => null, 'fetched_at' => null, 'removed' => false];
        }
    }

    /**
     * {{ field }} gives the rendered figure; {{ field:url }}, {{ field:title }},
     * {{ field:version }}, {{ field:latest_version }}, and {{ field:fetched_at }}
     * give its parts.
     */
    public function augment($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $doc = is_array($value) && is_string($value['document'] ?? null) ? Document::decode($value['document']) : null;

        return [
            'html' => app(Embedder::class)->renderStored($value),
            'url' => is_array($value) ? ($value['url'] ?? null) : $value,
            'embed_url' => is_array($value) ? ($value['embed_url'] ?? null) : null,
            'title' => $doc['title'] ?? null,
            'version' => $doc['version'] ?? null,
            'latest_version' => $doc['latest_version'] ?? null,
            'fetched_at' => is_array($value) ? ($value['fetched_at'] ?? null) : null,
            'removed' => is_array($value) && ! empty($value['removed']),
        ];
    }
}
