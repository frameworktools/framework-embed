<?php

declare(strict_types=1);

namespace Drupal\framework_embed\Plugin\Filter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;
use Drupal\framework_embed\Embedder;
use Drupal\framework_embed\UrlParagraphs;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Turns a Framework artifact URL alone in a paragraph into the artifact.
 *
 * Place it after "Limit allowed HTML tags": that filter would strip the
 * figure. The figure itself is built per request by a lazy builder, so the
 * filtered text stays cached while each figure follows its own max-age.
 */
#[Filter(
  id: 'framework_embed',
  title: new TranslatableMarkup('Embed Framework artifacts'),
  type: FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
  description: new TranslatableMarkup('Shows a Framework artifact URL that is alone in a paragraph as the artifact. Only framework.pub and the hosts allowed under Configuration → Content authoring → Framework embeds are fetched.'),
  weight: 100,
)]
final class FrameworkEmbedFilter extends FilterBase implements ContainerFactoryPluginInterface {

  private Embedder $embedder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $filter = new static($configuration, $plugin_id, $plugin_definition);
    $filter->embedder = $container->get('framework_embed.embedder');

    return $filter;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode): FilterProcessResult {
    $result = new FilterProcessResult();
    $result->addCacheTags(['config:framework_embed.settings']);
    $text = UrlParagraphs::replace((string) $text, function (string $url) use ($result): ?string {
      if (!$this->embedder->isArtifactUrl($url)) {
        return NULL;
      }
      $lazy = $this->embedder->lazy($url);

      return (string) $result->createPlaceholder($lazy['#lazy_builder'][0], $lazy['#lazy_builder'][1]);
    });
    $result->setProcessedText($text);

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE): string {
    return (string) $this->t('Paste a Framework artifact URL on its own line to show the artifact.');
  }

}
