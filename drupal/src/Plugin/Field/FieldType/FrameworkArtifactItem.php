<?php

declare(strict_types=1);

namespace Drupal\framework_embed\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * A Framework artifact with a stored copy of its embed document.
 *
 * - url: the artifact URL the editor entered.
 * - embed_url: the pinned version URL from the document.
 * - document: the raw embed document JSON, rendered on view.
 * - fetched_at: when the copy was fetched.
 * - removed: set when the takedown check found the artifact gone.
 */
#[FieldType(
  id: 'framework_artifact',
  label: new TranslatableMarkup('Framework artifact'),
  description: new TranslatableMarkup('Stores and renders a published Framework model, table, hypothesis test, figure, or chart.'),
  category: 'reference',
  default_widget: 'framework_artifact_default',
  default_formatter: 'framework_artifact_stored',
)]
final class FrameworkArtifactItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'url' => DataDefinition::create('string')->setLabel(new TranslatableMarkup('Artifact URL'))->setRequired(TRUE),
      'embed_url' => DataDefinition::create('string')->setLabel(new TranslatableMarkup('Pinned embed URL')),
      'document' => DataDefinition::create('string')->setLabel(new TranslatableMarkup('Stored embed document')),
      'fetched_at' => DataDefinition::create('timestamp')->setLabel(new TranslatableMarkup('Fetched time')),
      'removed' => DataDefinition::create('boolean')->setLabel(new TranslatableMarkup('Removed by takedown check')),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'url' => ['type' => 'varchar', 'length' => 2048],
        'embed_url' => ['type' => 'varchar', 'length' => 2048],
        'document' => ['type' => 'text', 'size' => 'big'],
        'fetched_at' => ['type' => 'int'],
        'removed' => ['type' => 'int', 'size' => 'tiny', 'default' => 0],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): string {
    return 'url';
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    $url = $this->get('url')->getValue();

    return $url === NULL || trim((string) $url) === '';
  }

  /**
   * The stored document decoded, or NULL.
   */
  public function document(): ?array {
    $json = $this->get('document')->getValue();

    return is_string($json) && $json !== '' ? \Framework\Embed\Document::decode($json) : NULL;
  }

}
