<?php

namespace Drupal\kifisearch;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\search\Plugin\SearchIndexingInterface;
use Ehann\RediSearch\Document\DocumentInterface;
use Ehann\RediSearch\Fields\TagField;
use Ehann\RediSearch\Index;
use ElasticSearch\Client;
use Html2Text\Html2Text;
use InvalidArgumentException;

abstract class IndexerBase implements SearchIndexingInterface {
  protected $database;
  protected $storage;
  protected Index $kifi_index;
  protected $bundles;
  protected $batchSize;

  abstract public function getTotal();
  abstract public function getRemaining();

  public function __construct(Connection $database, EntityStorageInterface $storage, Index $kifi_index, array $allowed_bundles, int $batch_size) {
    $this->database = $database;
    $this->storage = $storage;
    $this->kifi_index = $kifi_index;
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

  protected function prepareDocument(string $docid, array $document):DocumentInterface
  {
    $kifiDocument = $this->kifi_index->makeDocument();

    if (!empty($document['tags'])) {
      $document['tags'] = implode(',', $document['tags']);
    }
    if (!empty($document['terms'])) {
      $document['terms'] = implode(',', $document['terms']);
    }

    // Generic entity fields
    $kifiDocument->entity_id->setValue($document['id']);
    $kifiDocument->entity_type->setValue($document['entity_type']);
    $kifiDocument->bundle->setValue($document['bundle']);
    $kifiDocument->langcode->setValue($document['langcode']);
    $kifiDocument->title->setValue($document['title']);
    $kifiDocument->body->setValue($document['body']);
    $kifiDocument->terms->setValue($document['terms']);
    $kifiDocument->tags->setValue($document['tags']);

    // Created and changed are in the format of '1999-09-09T00:00:00'
    // Convert them into unix time

    $document['year'] = date('Y', strtotime($document['created']));
    $document['created'] = strtotime($document['created']);
    $document['changed'] = strtotime($document['changed']);

    $kifiDocument->created->setValue($document['created']);
    $kifiDocument->changed->setValue($document['changed']);
    $kifiDocument->year->setValue($document['year']);

    // Comment specific fields
    $kifiDocument->commented_entity_type->setValue($document['commented_entity_type']);
    $kifiDocument->commented_entity_id->setValue($document['commented_entity_id']);
    $kifiDocument->comment_field->setValue($document['comment_field']);

    // Procal specific fields
    $kifiDocument->procal_starts->setValue($document['procal_starts']);
    $kifiDocument->procal_ends->setValue($document['procal_ends']);
    $kifiDocument->procal_expires->setValue($document['procal_expires']);
    $kifiDocument->procal_location->setValue($document['procal_location']);
    $kifiDocument->procal_organisation->setValue($document['procal_organisation']);
    $kifiDocument->procal_streamable->setValue($document['procal_streamable']);

    // Question specific
    $kifiDocument->asklib_score->setValue($document['asklib_score']);


    // RediSearch specific language parameter
    // TODO: Language parameter is not supported in RediSearch 1.1.2 (version
    // currently in use). However 2.0.12 does support language, but in our
    // use case, we do not need the extended language support, since we
    // are mostly using Latin alphabet based languages (fi, sv, en).
    /*
    $language = \Ehann\RediSearch\Language::FINNISH;

    if ($document['langcode'] === 'sv') {
      $language = \Ehann\RediSearch\Language::SWEDISH;
    }

    if ($document['langcode'] === 'en') {
      $language = \Ehann\RediSearch\Language::ENGLISH;
    }

    $kifiDocument->setLanguage($language);
    */

    // evrecipe
    $kifiDocument->evrecipe_organizer->setValue($document['evrecipe_organizer']);
    
    return $kifiDocument;
  }

  public function index(array $document) {
    $docid = sprintf('%s::%d::%s', $document['entity_type'], $document['id'], $document['langcode']);

    // dump($docid . ' === ' . $document['title']);
    $this->kifi_index->add($this->prepareDocument($docid, $document));

    /*
    $this->elastic->index([
      'index' => 'kirjastot_fi',
      'type' => 'content',
      'id' => $docid,
      'body' => $document,
    ]);*/

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
