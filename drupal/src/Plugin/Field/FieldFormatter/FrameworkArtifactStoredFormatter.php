<?php

declare(strict_types=1);

namespace Drupal\framework_embed\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\framework_embed\Embedder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the stored copy. Never contacts the server.
 */
#[FieldFormatter(
  id: 'framework_artifact_stored',
  label: new TranslatableMarkup('Stored artifact'),
  field_types: ['framework_artifact'],
)]
final class FrameworkArtifactStoredFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  private Embedder $embedder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $formatter = new static($plugin_id, $plugin_definition, $configuration['field_definition'], $configuration['settings'], $configuration['label'], $configuration['view_mode'], $configuration['third_party_settings']);
    $formatter->embedder = $container->get('framework_embed.embedder');

    return $formatter;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    foreach ($items as $delta => $item) {
      $elements[$delta] = $this->embedder->renderStored($item->get('document')->getValue(), (string) $item->get('url')->getValue(), (bool) $item->get('removed')->getValue());
    }

    return $elements;
  }

}
