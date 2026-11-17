<?php

declare(strict_types=1);

namespace Drupal\framework_embed\Plugin\Field\FieldWidget;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Framework\Embed\EmbedException;

/**
 * URL field plus the stored copy's state and its "Fetch again" choices.
 *
 * Saving with a new URL fetches the artifact and pins its version. "Fetch
 * again" and "Use newest version" are choices on the form; the stored copy is
 * replaced only when the editor saves with one selected. The document itself
 * never travels to the browser: it is a server-side form value.
 */
#[FieldWidget(
  id: 'framework_artifact_default',
  label: new TranslatableMarkup('Artifact URL with stored copy'),
  field_types: ['framework_artifact'],
)]
final class FrameworkArtifactWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    /** @var \Drupal\framework_embed\Plugin\Field\FieldType\FrameworkArtifactItem $item */
    $item = $items[$delta];
    $doc = $item->document();
    $url = (string) ($item->get('url')->getValue() ?? '');

    $element += ['#type' => 'fieldset'];
    $element['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Artifact URL'),
      '#default_value' => $url,
      '#maxlength' => 2048,
      '#description' => $this->t('Enter the URL of a public or unlisted artifact on framework.pub or an allowed Framework Cloud server. Saving fetches the artifact and stores a copy of that version with this content.'),
      '#required' => $element['#required'] ?? FALSE,
    ];
    foreach (['embed_url', 'document', 'fetched_at', 'removed'] as $property) {
      $element[$property] = ['#type' => 'value', '#value' => $item->get($property)->getValue()];
    }
    $element['stored_url'] = ['#type' => 'value', '#value' => $url];

    if ($doc !== NULL) {
      $fetched = (int) $item->get('fetched_at')->getValue();
      $element['status'] = [
        '#type' => 'item',
        '#title' => $this->t('Stored copy'),
        '#markup' => $this->t('Version @version of “@title”, fetched @date.', [
          '@version' => $doc['version'] ?? '—',
          '@title' => $doc['title'] ?? '',
          '@date' => $fetched > 0 ? DrupalDateTime::createFromTimestamp($fetched)->format('Y-m-d H:i') : '—',
        ]),
      ];
      $options = [
        'keep' => $this->t('Keep stored copy'),
        'again' => $this->t('Fetch again (version @version)', ['@version' => $doc['version'] ?? '—']),
      ];
      if (is_int($doc['latest_version'] ?? NULL) && is_int($doc['version'] ?? NULL) && $doc['latest_version'] > $doc['version']) {
        $options['newest'] = $this->t('Use newest version (@version)', ['@version' => $doc['latest_version']]);
      }
      $element['action'] = [
        '#type' => 'radios',
        '#title' => $this->t('Save action'),
        '#options' => $options,
        '#default_value' => 'keep',
      ];
      $element['preview'] = [
        '#type' => 'details',
        '#title' => $this->t('Preview'),
        'figure' => \Drupal::service('framework_embed.embedder')->renderStored($item->get('document')->getValue(), $url),
      ];
    }
    elseif ((bool) $item->get('removed')->getValue()) {
      $element['status'] = [
        '#type' => 'item',
        '#title' => $this->t('Stored copy'),
        '#markup' => $this->t('The stored copy was removed because the artifact is no longer available on the server. Visitors see a link.'),
      ];
      $element['action'] = [
        '#type' => 'radios',
        '#title' => $this->t('Save action'),
        '#options' => ['keep' => $this->t('Keep link'), 'again' => $this->t('Fetch again')],
        '#default_value' => 'keep',
      ];
    }
    $element['#element_validate'][] = [static::class, 'validateArtifact'];

    return $element;
  }

  /**
   * Fetches when the URL changed, when there is no copy yet, or when the
   * editor chose "Fetch again" or "Use newest version".
   */
  public static function validateArtifact(array &$element, FormStateInterface $form_state, array &$complete_form): void {
    $values = (array) $form_state->getValue($element['#parents']);
    $url = trim((string) ($values['url'] ?? ''));
    $action = (string) ($values['action'] ?? 'keep');
    $stored = (string) ($values['document'] ?? '');

    if ($url === '') {
      foreach (['embed_url' => NULL, 'document' => NULL, 'fetched_at' => NULL, 'removed' => 0] as $property => $value) {
        $form_state->setValueForElement($element[$property], $value);
      }
      return;
    }
    $changed = $url !== (string) ($values['stored_url'] ?? '');
    $removed = (bool) ($values['removed'] ?? FALSE);
    if (!$changed && $action === 'keep' && ($stored !== '' || $removed)) {
      return;
    }

    /** @var \Drupal\framework_embed\Embedder $embedder */
    $embedder = \Drupal::service('framework_embed.embedder');
    $pinned = (string) ($values['embed_url'] ?? '');
    $target = $changed ? $url : ($pinned !== '' ? $pinned : $url);
    if (!$changed && $action === 'newest') {
      $target = $embedder->newestUrl($target) ?? $target;
    }
    if (!$embedder->isArtifactUrl($target)) {
      $form_state->setError($element['url'], t('This URL is not a Framework artifact on an allowed host. Administrators add Framework Cloud servers under Configuration → Content authoring → Framework embeds.'));
      return;
    }
    try {
      $answer = $embedder->request($target);
    }
    catch (EmbedException $e) {
      $form_state->setError($element['url'], t('Could not reach the Framework Cloud server or read its embed response. Try again later.'));
      return;
    }
    if ($answer['doc'] === NULL) {
      $form_state->setError($element['url'], t('This Framework artifact is private, password-protected, or deleted.'));
      return;
    }

    $embedUrl = is_string($answer['doc']['embed_url'] ?? NULL) ? $answer['doc']['embed_url'] : '';
    $match = $embedder->matcher()->match($embedUrl);
    $form_state->setValueForElement($element['embed_url'], $match !== NULL && $match->type === 'embed' ? $embedUrl : NULL);
    $form_state->setValueForElement($element['document'], $answer['body']);
    $form_state->setValueForElement($element['fetched_at'], \Drupal::time()->getCurrentTime());
    $form_state->setValueForElement($element['removed'], 0);
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    foreach ($values as &$value) {
      unset($value['action'], $value['stored_url'], $value['status'], $value['preview']);
      $value['url'] = trim((string) ($value['url'] ?? ''));
      $value['removed'] = (int) ($value['removed'] ?? 0);
    }

    return $values;
  }

}
