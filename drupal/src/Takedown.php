<?php

declare(strict_types=1);

namespace Drupal\framework_embed;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\State\StateInterface;
use Framework\Embed\EmbedException;
use Psr\Log\LoggerInterface;

/**
 * Removes stored copies whose artifact the server reports gone.
 *
 * Runs once a day from cron when "Takedown check" is on (the default). Only
 * a 404 or 410 removes a copy; timeouts, 5xx answers, and hosts no longer
 * allowed leave it. The field keeps its URL and renders a link.
 */
final class Takedown {

  public const BATCH = 100;

  public function __construct(
    private readonly Embedder $embedder,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly LoggerInterface $logger,
  ) {}

  public function enabled(): bool {
    return (bool) ($this->configFactory->get('framework_embed.settings')->get('takedown') ?? TRUE);
  }

  /**
   * Runs when the last run is a day old.
   */
  public function runIfDue(int $now): void {
    if ($this->enabled() && $now - (int) $this->state->get('framework_embed.takedown_last', 0) >= 86400) {
      $this->run();
      $this->state->set('framework_embed.takedown_last', $now);
    }
  }

  /**
   * Checks up to BATCH entities per field.
   *
   * @return array{checked: int, removed: int}
   */
  public function run(): array {
    $counts = ['checked' => 0, 'removed' => 0];
    if (!$this->enabled()) {
      return $counts;
    }
    $verdicts = [];
    foreach ($this->fieldManager->getFieldMapByFieldType('framework_artifact') as $entityTypeId => $fields) {
      $storage = $this->entityTypeManager->getStorage($entityTypeId);
      foreach (array_keys($fields) as $fieldName) {
        $cursorKey = "framework_embed.takedown_cursor.{$entityTypeId}.{$fieldName}";
        $cursor = (int) $this->state->get($cursorKey, 0);
        $idKey = $this->entityTypeManager->getDefinition($entityTypeId)->getKey('id');
        $ids = $storage->getQuery()
          ->accessCheck(FALSE)
          ->exists("{$fieldName}.document")
          ->condition($idKey, $cursor, '>')
          ->sort($idKey)
          ->range(0, self::BATCH)
          ->execute();
        $this->state->set($cursorKey, count($ids) < self::BATCH ? 0 : (int) end($ids));

        foreach ($storage->loadMultiple($ids) as $entity) {
          $changed = FALSE;
          foreach ($entity->get($fieldName) as $item) {
            $document = (string) ($item->get('document')->getValue() ?? '');
            if ($document === '') {
              continue;
            }
            $target = (string) ($item->get('embed_url')->getValue() ?: $item->get('url')->getValue());
            if (!$this->embedder->isArtifactUrl($target)) {
              continue;
            }
            if (!isset($verdicts[$target])) {
              $counts['checked']++;
              try {
                $verdicts[$target] = $this->embedder->request($target)['doc'] === NULL ? 'gone' : 'ok';
              }
              catch (EmbedException) {
                $verdicts[$target] = 'unknown';
              }
            }
            if ($verdicts[$target] === 'gone') {
              $item->set('document', NULL);
              $item->set('removed', 1);
              $counts['removed']++;
              $changed = TRUE;
            }
          }
          if ($changed) {
            if ($entity instanceof RevisionLogInterface && $entity->getEntityType()->isRevisionable()) {
              $entity->setNewRevision(TRUE);
              $entity->setRevisionLogMessage('Framework Embeds: removed stored artifact copy after the server returned 404 or 410.');
            }
            $entity->save();
            $this->logger->notice('Removed a stored Framework artifact from @type @id: the server reported it gone.', ['@type' => $entityTypeId, '@id' => $entity->id()]);
          }
        }
      }
    }

    return $counts;
  }

}
