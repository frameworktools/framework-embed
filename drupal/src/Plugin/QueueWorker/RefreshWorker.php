<?php

declare(strict_types=1);

namespace Drupal\framework_embed\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\framework_embed\Embedder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Fetches live embed documents queued by Embedder::schedule().
 *
 * Most items are already handled after the response that queued them; cron
 * catches the rest, for example when a request ended early.
 */
#[QueueWorker(
  id: Embedder::QUEUE,
  title: new TranslatableMarkup('Framework embed refresh'),
  cron: ['time' => 30],
)]
final class RefreshWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  private Embedder $embedder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $worker = new static($configuration, $plugin_id, $plugin_definition);
    $worker->embedder = $container->get('framework_embed.embedder');

    return $worker;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $url = is_array($data) && isset($data['url']) && is_string($data['url']) ? $data['url'] : '';
    if ($url !== '' && !$this->embedder->isFresh($url)) {
      $this->embedder->refresh($url);
    }
  }

}
