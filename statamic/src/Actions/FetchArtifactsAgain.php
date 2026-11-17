<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed\Actions;

use Framework\Embed\EmbedException;
use FrameworkCloud\StatamicEmbed\Embedder;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;

/**
 * Re-fetches every Framework artifact field in the selected entries, at the
 * pinned version, and replaces the stored copies after confirmation.
 */
class FetchArtifactsAgain extends Action
{
    protected static $title = 'Fetch stored Framework artifact versions again';

    /** Fetch the newest version instead of the pinned one. */
    protected bool $newest = false;

    public function visibleTo($item)
    {
        return $item instanceof Entry && static::artifactFields($item) !== [];
    }

    public function authorize($user, $item)
    {
        return $user->can('edit', $item);
    }

    public function confirmationText()
    {
        return $this->newest
            ? 'Fetch the newest version of each Framework artifact in this entry and replace its stored copy?|Fetch the newest version of each Framework artifact in these :count entries and replace their stored copies?'
            : 'Fetch the stored version of each Framework artifact in this entry again and replace its stored copy?|Fetch the stored version of each Framework artifact in these :count entries again and replace their stored copies?';
    }

    /** @return list<string> Handles of framework_artifact fields that hold a URL. */
    public static function artifactFields(Entry $entry): array
    {
        return $entry->blueprint()->fields()->all()
            ->filter(fn ($field) => $field->type() === 'framework_artifact' && is_array($entry->get($field->handle())))
            ->keys()->values()->all();
    }

    public function run($items, $values)
    {
        $embedder = app(Embedder::class);
        $fetched = 0;
        $failed = [];
        foreach ($items as $entry) {
            $changed = false;
            foreach (static::artifactFields($entry) as $handle) {
                $value = $entry->get($handle);
                $url = (string) ($value['url'] ?? '');
                $target = (string) (($value['embed_url'] ?? null) ?: $url);
                if ($this->newest) {
                    $target = $embedder->newestUrl($target) ?? $target;
                }
                try {
                    $entry->set($handle, $embedder->fetchForStorage($url, $target));
                    $changed = true;
                    $fetched++;
                } catch (EmbedException $e) {
                    $failed[] = $url . ': ' . Embedder::message($e);
                }
            }
            if ($changed) {
                $entry->save();
            }
        }

        return trans_choice('Fetched :count artifact.|Fetched :count artifacts.', $fetched) . ($failed === [] ? '' : ' ' . implode(' ', $failed));
    }
}
