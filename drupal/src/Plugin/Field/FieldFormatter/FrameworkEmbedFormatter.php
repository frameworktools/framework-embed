<?php

declare(strict_types=1);

namespace Drupal\framework_embed\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\framework_embed\Embedder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows each link as the Framework artifact it points to.
 */
#[FieldFormatter(
  id: 'framework_embed',
  label: new TranslatableMarkup('Framework artifact'),
  field_types: ['link'],
)]
final class FrameworkEmbedFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  private Embedder $embedder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $formatter = new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
    );
    $formatter->embedder = $container->get('framework_embed.embedder');

    return $formatter;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    foreach ($items as $delta => $item) {
      $url = $item->getUrl();
      if ($url->isExternal()) {
        $elements[$delta] = $this->embedder->lazy($url->toString());
      }
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition): bool {
    return $field_definition->getType() === 'link';
  }

}
