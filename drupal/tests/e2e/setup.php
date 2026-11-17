<?php

/**
 * @file
 * Test only. Run with `drush php:script` after enabling framework_embed and
 * framework_embed_test: adds the filter to Basic and Full HTML, a link field
 * with the Framework artifact formatter, and the nodes check.php reads.
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\Node;

foreach (['basic_html', 'full_html'] as $id) {
  $format = FilterFormat::load($id);
  $format->setFilterConfig('framework_embed', ['status' => TRUE, 'weight' => 100]);
  $format->save();
}

if (!FieldStorageConfig::loadByName('node', 'field_artifact')) {
  FieldStorageConfig::create(['field_name' => 'field_artifact', 'entity_type' => 'node', 'type' => 'link'])->save();
  FieldConfig::create(['field_name' => 'field_artifact', 'entity_type' => 'node', 'bundle' => 'article', 'label' => 'Artifact'])->save();
  EntityViewDisplay::load('node.article.default')
    ->setComponent('field_artifact', ['type' => 'framework_embed', 'label' => 'hidden'])
    ->save();
}

if (!FieldStorageConfig::loadByName('node', 'field_framework')) {
  FieldStorageConfig::create(['field_name' => 'field_framework', 'entity_type' => 'node', 'type' => 'framework_artifact'])->save();
  FieldConfig::create(['field_name' => 'field_framework', 'entity_type' => 'node', 'bundle' => 'article', 'label' => 'Framework artifact'])->save();
  EntityViewDisplay::load('node.article.default')
    ->setComponent('field_framework', ['type' => 'framework_artifact_stored', 'label' => 'hidden'])
    ->save();
  EntityFormDisplay::load('node.article.default')
    ->setComponent('field_framework', ['type' => 'framework_artifact_default'])
    ->save();
}

$nodes = [
  'filter' => ['basic_html', "<p>Before the model.</p>\n<p>https://framework.pub/d/minimal-model</p>\n<p><a href=\"https://framework.pub/@ada/admissions/python-altair\">https://framework.pub/@ada/admissions/python-altair</a></p>\n<p>After.</p>"],
  'missing' => ['basic_html', "<p>Gone:</p>\n<p>https://framework.pub/d/gone</p>\n<p>Down:</p>\n<p>https://framework.pub/d/down</p>\n<p>Elsewhere:</p>\n<p>https://evil.example/d/minimal-model</p>"],
  'extra' => ['full_html', '<p>https://framework.example.org/d/minimal-test</p>'],
];
foreach ($nodes as $title => [$format, $body]) {
  Node::create(['type' => 'article', 'title' => $title, 'body' => ['value' => $body, 'format' => $format], 'status' => 1])->save();
}
Node::create(['type' => 'article', 'title' => 'field', 'field_artifact' => ['uri' => 'https://framework.pub/d/r-lmer'], 'status' => 1])->save();

echo "nodes 1-4 created\n";
