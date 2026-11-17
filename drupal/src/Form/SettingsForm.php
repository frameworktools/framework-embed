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
      '#markup' => '<p>' . $this->t('Artifacts from framework.pub are enabled by default. Add self-hosted Framework Cloud servers below to allow embeds from those hosts.') . '</p>',
    ];
    $form['hosts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Extra hosts'),
      '#default_value' => implode("\n", (array) $config->get('hosts')),
      '#description' => $this->t('Enter one host per line. A host name such as framework.example.org allows HTTPS on the default port. An origin such as http://localhost:8000 allows that exact scheme, host, and port. IP addresses and paths are not allowed.'),
      '#rows' => 5,
    ];
    $form['max_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum cache lifetime'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 0,
      '#max' => 86400,
      '#default_value' => (int) ($config->get('max_ttl') ?? 3600),
      '#description' => $this->t('Applies to live embeds. Shorter Cache-Control max-age headers from the Framework Cloud server take precedence.'),
    ];

    $form['takedown'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Remove stored copies when an artifact is deleted or restricted'),
      '#default_value' => (bool) ($config->get('takedown') ?? TRUE),
      '#description' => $this->t('Cron checks stored artifacts on allowed hosts once a day. If the server returns 404 or 410, the stored copy is replaced with a link. Timeouts and server errors leave the stored copy unchanged.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    [$hosts, $invalid] = HostList::parse((string) $form_state->getValue('hosts'));
    if ($invalid !== []) {
      $form_state->setErrorByName('hosts', $this->t('Not allowed: @entries. Enter a host name such as framework.example.org or an origin such as http://localhost:8000. IP addresses and paths are not allowed.', ['@entries' => implode(', ', $invalid)]));
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
