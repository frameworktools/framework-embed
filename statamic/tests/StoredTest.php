<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed\Tests;

use FrameworkCloud\StatamicEmbed\Actions\FetchArtifactsAgain;
use FrameworkCloud\StatamicEmbed\Actions\UseNewestArtifactVersions;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Parse;

/** Stored tier: the framework_artifact fieldtype, its actions, and the takedown command. */
final class StoredTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeFetcher();
        Collection::make('articles')->save();
        Blueprint::make('article')->setNamespace('collections.articles')->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'artifact', 'field' => ['type' => 'framework_artifact']],
            ],
        ])->save();
    }

    /** Saves an entry the way the control panel does: validate, process, merge, save. */
    private function save(?\Statamic\Contracts\Entries\Entry $entry, string $url): \Statamic\Contracts\Entries\Entry
    {
        $entry ??= Entry::make()->collection('articles')->blueprint('article')->slug('a' . uniqid());
        $fields = $entry->blueprint()->fields()->setParent($entry)->addValues(['title' => 'Test', 'artifact' => $url]);
        $fields->validator()->validate();
        $entry->merge($fields->process()->values()->only(['title', 'artifact'])->all())->save();

        return $entry;
    }

    private function render(\Statamic\Contracts\Entries\Entry $entry, string $template): string
    {
        $html = (string) Parse::template($template . "\n", $entry->toAugmentedArray(), [], true);

        return substr($html, 0, -1);
    }

    public function test_saving_a_url_stores_the_document_and_pins_the_version(): void
    {
        $entry = $this->save(null, 'https://framework.pub/d/minimal-model');
        $value = $entry->get('artifact');

        $this->assertSame('https://framework.pub/d/minimal-model', $value['url']);
        $this->assertSame('https://framework.pub/api/embed/v1/artifacts/minimalmodel0000/versions/1', $value['embed_url']);
        $this->assertSame(file_get_contents(self::FIXTURES . '/minimal-model.json'), $value['document']);
        $this->assertCount(1, $this->requests, 'Validation and processing share one fetch.');
    }

    public function test_views_render_from_the_stored_copy_without_requests(): void
    {
        $entry = $this->save(null, 'https://framework.pub/d/r-ggplot');
        $this->requests = [];
        $this->serve('down', ['r-ggplot']);

        $this->assertSame($this->fixture('r-ggplot'), $this->render($entry, '{{ framework:artifact field="artifact" }}'));
        $this->assertSame($this->fixture('r-ggplot'), $this->render($entry, '{{ artifact:html }}'));
        $this->assertStringContainsString('src="https://framework.pub/api/embed/v1/artifacts/rggplot000000000/versions/1/image"', $this->render($entry, '{{ artifact:html }}'));
        $this->assertSame('1', $this->render($entry, '{{ artifact:version }}'));
        $this->assertSame([], $this->requests);
    }

    public function test_saving_the_same_url_keeps_the_copy(): void
    {
        $entry = $this->save(null, 'https://framework.pub/d/minimal-model');
        $before = $entry->get('artifact');
        $this->app->forgetScopedInstances();

        $this->save($entry, 'https://framework.pub/d/minimal-model');

        $this->assertSame($before, $entry->get('artifact'));
        $this->assertCount(1, $this->requests);
    }

    public function test_gone_unreachable_and_disallowed_urls_fail_validation(): void
    {
        $this->serve('down', ['stata-stcox']);
        foreach (['https://framework.pub/d/gone-artifact' => 'private, password-protected, or deleted', 'https://framework.pub/d/stata-stcox' => 'could not be reached', 'https://evil.example/d/minimal-model' => 'not a Framework artifact on an allowed host'] as $url => $message) {
            try {
                $this->save(null, $url);
                $this->fail("{$url} saved");
            } catch (ValidationException $e) {
                $this->assertStringContainsString($message, implode(' ', $e->validator->errors()->all()));
            }
        }
    }

    public function test_fetch_again_and_use_newest_version_actions(): void
    {
        $this->serve('bump', ['r-lmer']);
        $entry = $this->save(null, 'https://framework.pub/d/r-lmer/versions/1');
        $this->assertSame('2', $this->render($entry, '{{ artifact:latest_version }}'));

        $again = new FetchArtifactsAgain();
        $this->assertTrue($again->visibleTo($entry));
        $this->assertStringContainsString('replace its stored copy', $again->confirmationText());
        $this->app->forgetScopedInstances();
        $again->run(collect([$entry]), []);
        $this->assertSame('https://framework.pub/api/embed/v1/artifacts/rlmer00000000000/versions/1', end($this->requests));
        $this->assertSame('1', $this->render(Entry::find($entry->id()), '{{ artifact:version }}'));

        $this->app->forgetScopedInstances();
        (new UseNewestArtifactVersions())->run(collect([$entry]), []);
        $saved = Entry::find($entry->id());
        $this->assertSame('2', $this->render($saved, '{{ artifact:version }}'));
        $this->assertSame('https://framework.pub/api/embed/v1/artifacts/rlmer00000000000/versions/2', $saved->get('artifact')['embed_url']);
    }

    public function test_takedown_removes_only_definite_gone(): void
    {
        $gone = $this->save(null, 'https://framework.pub/d/minimal-model');
        $down = $this->save(null, 'https://framework.pub/d/stata-stcox');
        $kept = $this->save(null, 'https://framework.pub/d/r-lmer');
        $this->serve('gone', ['minimal-model']);
        $this->serve('down', ['stata-stcox']);

        $this->artisan('framework-embed:takedown')->expectsOutput('{"checked":3,"removed":1}')->assertSuccessful();

        $value = Entry::find($gone->id())->get('artifact');
        $this->assertNull($value['document']);
        $this->assertTrue($value['removed']);
        $this->assertSame('<p><a href="https://framework.pub/d/minimal-model">https://framework.pub/d/minimal-model</a></p>', $this->render(Entry::find($gone->id()), '{{ artifact:html }}'));
        $this->assertNotNull(Entry::find($down->id())->get('artifact')['document']);
        $this->assertNotNull(Entry::find($kept->id())->get('artifact')['document']);
    }

    public function test_takedown_can_be_switched_off(): void
    {
        $this->save(null, 'https://framework.pub/d/minimal-model');
        $this->serve('gone', ['minimal-model']);
        config(['framework-embed.takedown' => false]);
        $this->requests = [];

        $this->artisan('framework-embed:takedown')->assertSuccessful();
        $this->assertSame([], $this->requests);
    }
}
