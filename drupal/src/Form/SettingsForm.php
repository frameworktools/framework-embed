<?php

declare(strict_types=1);

namespace Drupal\framework_embed\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\framework_embed\Embedder;
use Drupal\framework_embed\HostList;

/**
 * Configuration → Content authoring → Framework embeds.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'framework_embed_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['framework_embed.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('framework_embed.settings');
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Artifact URLs from framework.pub render as figures. Add self-hosted Framework Cloud servers here. No other host is ever contacted.') . '</p>',
    ];
    $form['hosts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Extra hosts'),
      '#default_value' => implode("\n", (array) $config->get('hosts')),
      '#description' => $this->t('One per line. A host name such as framework.example.org allows HTTPS only. An origin such as http://localhost:8000 allows exactly that scheme, host, and port. IP addresses are not allowed.'),
      '#rows' => 5,
    ];
    $form['max_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Longest fresh lifetime for cached embeds'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 0,
      '#max' => 86400,
      '#default_value' => (int) ($config->get('max_ttl') ?? 3600),
      '#description' => $this->t("The server's Cache-Control header can only shorten it."),
    ];

    $form['takedown'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Remove stored copies of artifacts that are no longer public'),
      '#default_value' => (bool) ($config->get('takedown') ?? TRUE),
      '#description' => $this->t('Once a day, cron checks each stored artifact on an allowed host. A copy is removed only when the server answers 404 or 410, and a link takes its place. Timeouts and server errors change nothing.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    [$hosts, $invalid] = HostList::parse((string) $form_state->getValue('hosts'));
    if ($invalid !== []) {
      $form_state->setErrorByName('hosts', $this->t('Not allowed: @entries. Enter a host name such as framework.example.org, or an origin such as http://localhost:8000. IP addresses and paths are not allowed.', ['@entries' => implode(', ', $invalid)]));
    }
    $form_state->setValue('hosts', $hosts);
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('framework_embed.settings')
      ->set('hosts', array_values($form_state->getValue('hosts')))
      ->set('max_ttl', (int) $form_state->getValue('max_ttl'))
      ->set('takedown', (bool) $form_state->getValue('takedown'))
      ->save();
    Cache::invalidateTags([Embedder::TAG]);
    parent::submitForm($form, $form_state);
  }

}
