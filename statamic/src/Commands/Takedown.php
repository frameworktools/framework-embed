<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed\Commands;

use Framework\Embed\EmbedException;
use FrameworkCloud\StatamicEmbed\Actions\FetchArtifactsAgain;
use FrameworkCloud\StatamicEmbed\Embedder;
use Illuminate\Console\Command;
use Statamic\Facades\Entry;

/**
 * Removes stored copies whose artifact the server reports gone. Only a 404 or
 * 410 removes a copy; timeouts, 5xx answers, and hosts no longer allowed
 * leave it. The field keeps its URL and renders a link. Scheduled daily when
 * framework-embed.takedown is on (the default).
 */
class Takedown extends Command
{
    protected $signature = 'framework-embed:takedown {--force : Run even when the takedown check is switched off}';

    protected $description = 'Remove stored Framework artifacts that the server reports gone';

    public function handle(): int
    {
        if (! config('framework-embed.takedown', true) && ! $this->option('force')) {
            $this->line('The takedown check is switched off (framework-embed.takedown).');

            return self::SUCCESS;
        }
        $embedder = app(Embedder::class);
        $verdicts = [];
        $checked = 0;
        $removed = 0;
        foreach (Entry::all() as $entry) {
            $changed = false;
            foreach (FetchArtifactsAgain::artifactFields($entry) as $handle) {
                $value = $entry->get($handle);
                if (empty($value['document'])) {
                    continue;
                }
                $target = (string) (($value['embed_url'] ?? null) ?: ($value['url'] ?? ''));
                if (! $embedder->isArtifactUrl($target)) {
                    continue;
                }
                if (! isset($verdicts[$target])) {
                    $checked++;
                    try {
                        $verdicts[$target] = $embedder->request($target)['doc'] === null ? 'gone' : 'ok';
                    } catch (EmbedException) {
                        $verdicts[$target] = 'unknown';
                    }
                }
                if ($verdicts[$target] === 'gone') {
                    $entry->set($handle, ['document' => null, 'removed' => true] + $value);
                    $changed = true;
                    $removed++;
                }
            }
            if ($changed) {
                $entry->save();
            }
        }
        $this->line(json_encode(['checked' => $checked, 'removed' => $removed]));

        return self::SUCCESS;
    }
}
