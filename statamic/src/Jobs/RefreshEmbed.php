<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed\Jobs;

use FrameworkCloud\StatamicEmbed\Embedder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Fetches one live embed document. Dispatched after the response, or onto the queue. */
final class RefreshEmbed implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public readonly string $url) {}

    public function handle(Embedder $embedder): void
    {
        if (! $embedder->isFresh($this->url)) {
            $embedder->refresh($this->url);
        }
    }
}
