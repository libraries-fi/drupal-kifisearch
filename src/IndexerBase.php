<?php

namespace Drupal\kifisearch;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\search\Plugin\SearchIndexingInterface;
use ElasticSearch\Client;
use Html2Text\Html2Text;
use InvalidArgumentException;

abstract class IndexerBase implements SearchIndexingInterface {
  protected $database;
  protected $storage;
  protected $elastic;
  protected $bundles;

  abstract public function getTotal();
  abstract public function getRemaining();

  public function __construct(Connection $database, EntityStorageInterface $storage, Client $elastic, array $allowed_bundles, int $batch_size) {
    $this->database = $database;
    $this->storage = $storage;
    $this->elastic = $elastic;
    $this->bundles = $allowed_bundles;
    $this->batchSize = (int)$batch_size;
  }

  public function indexStatus() {
    return [
      'total' => $this->getTotal(),
      'remaining' => $this->getRemaining(),
    ];
  }

  public function markForReindex() {
    // Main search plugin will handle this.
  }

  public function indexClear() {
    // Main search plugin will handle this.
  }

  public function index(array $document) {
    $docid = sprintf('%s::%d::%s', $document['entity_type'], $document['id'], $document['langcode']);

    $this->elastic->index([
      'index' => 'kirjastot_fi',
      'type' => 'content',
      'id' => $docid,
      'body' => $document,
    ]);

    $this->database->query('
      INSERT INTO {kifisearch_index} (entity_id, entity_type)
      VALUES (:id, :type)
      ON DUPLICATE KEY UPDATE reindex=0', [
      'id' => $document['id'],
      'type' => $document['entity_type'],
    ]);
  }

  protected function extractTermsFromEntity(FieldableEntityInterface $entity, array $fields) {
    $terms = [
      // Numberic IDs
      'terms' => [],

      // Labels
      'tags' => [],
    ];

    foreach ($fields as $key) {
      if ($entity->hasField($key)) {
        $extracted = $this->extractTerms($entity->get($key), $entity->language()->getId());
        $terms['terms'] = array_merge($terms['terms'], $extracted['terms']);
        $terms['tags'] = array_merge($terms['tags'], $extracted['tags']);
      }
    }

    return $terms;
  }

  protected function extractTerms(FieldItemListInterface $fields, $langcode) {
    $terms = ['terms' => [], 'tags' => []];

    foreach ($fields as $field) {
      // FieldItemList is populated with an empty field with a null ID, so check that we have
      // a valid entity.
      if ($field->target_id) {
        $terms['terms'][] = (int)$field->target_id;
        // $terms['tags'][] = $field->entity->getTranslation($langcode)->label();

        try {
          $terms['tags'][] = $field->entity->getTranslation($langcode)->label();
        } catch (InvalidArgumentException $e) {
          // pass
        }
      }
    }

    $terms['terms'] = array_values(array_unique($terms['terms']));
    $terms['tags'] = array_values(array_unique($terms['tags']));

    return $terms;
  }

  protected function stripHtml($html, array $options = null) {
    if (is_null($options)) {
      $options = ['do_links' => 'none'];
    }

    return (new Html2Text($html, $options))->getText();
  }
}
