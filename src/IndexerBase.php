<?php

namespace Drupal\kifisearch;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\node\NodeInterface;
use Drupal\kifisearch\Plugin\Search\NodeElasticSearch as ContentSearch;
use Drupal\search\Plugin\SearchIndexingInterface;
use ElasticSearch\Client;
use Html2Text\Html2Text;

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

    $this->database->query('INSERT INTO {kifisearch_index} (entity_id, entity_type) VALUES (:id, :type)', [
      'id' => $document['id'],
      'type' => $document['entity_type'],
    ]);
  }
}
