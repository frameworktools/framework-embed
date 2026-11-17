<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed\Actions;

/**
 * Fetches the newest version of every Framework artifact field in the
 * selected entries, and replaces the stored copies after confirmation.
 */
class UseNewestArtifactVersions extends FetchArtifactsAgain
{
    protected static $title = 'Use newest Framework artifact versions';

    protected bool $newest = true;
}
