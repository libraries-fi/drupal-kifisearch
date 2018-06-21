<?php

namespace Drupal\kifisearch;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\node\NodeInterface;
use Drupal\kifisearch\Plugin\Search\ContentSearch;
use Drupal\search\Plugin\SearchIndexingInterface;
use ElasticSearch\Client;
use Html2Text\Html2Text;

class NodeIndexer extends IndexerBase {
  public function getTotal() {
    $query = $this->database->select('node_field_data', 'entity')
      ->distinct()
      ->fields('entity', ['nid'])
      ->condition('entity.status', NodeInterface::PUBLISHED)
      ->condition('entity.type[]', $this->bundles, 'IN');

    $total = $query->countQuery()->execute()->fetchField();
    return $total;
  }

  public function getRemaining() {
    $query = $this->database->select('node_field_data', 'entity')
      ->distinct()
      ->fields('entity', ['nid'])
      ->condition('entity.status', NodeInterface::PUBLISHED)
      ->condition('entity.type[]', $this->bundles, 'IN');

    // NOTE: Reindex is NULL when left join is to zero rows.
    $query->condition($query->orConditionGroup()
      ->condition('search.reindex', NULL, 'IS')
      ->condition('search.reindex', 0, '<>'));

    $query->leftJoin('kifisearch_index', 'search', 'search.entity_id = entity.nid AND search.entity_type = :type', [
      ':type' => 'node'
    ]);

    $remaining = $query->countQuery()->execute()->fetchField();

    return $remaining;
  }

  public function updateIndex() {
    foreach ($this->fetchItemsForIndexing() as $node) {
      $langcode = $node->language()->getId();

      $document = [
        'entity_type' => 'node',
        'id' => (int)$node->id(),
        'bundle' => $node->bundle(),
        'title' => $node->label(),
        'langcode' => $langcode,
        'created' => date('Y-m-d\TH:i:s', $node->getCreatedTime()),
        'changed' => date('Y-m-d\TH:i:s', $node->getChangedTime()),
      ];

      if ($node->hasField('body')) {
        $document['body'] = (new Html2Text($node->get('body')->value))->getText();
      }

      switch ($node->getType()) {
        case 'forum':
          if ($node->hasField('taxonomy_forums')) {
            $document['terms'][] = (int)$node->get('taxonomy_forums')->target_id;
            $document['tags'][] = $node->get('taxonomy_forums')->entity->label();
          }
          break;

        case 'page':
          // Nothing to index.
          break;

        case 'announcement':
          if ($node->hasField('field_groups')) {
            foreach ($node->get('field_groups') as $item) {
              $document['terms'][] = (int)$item->target_id;
              $document['tags'][] = $item->entity->label();
            }
          }
          break;

        case 'evrecipe':
        // FIXME: Define indexed content.
          break;

        case 'matbank_item':
          if ($node->hasField('field_category')) {
            $document['terms'][] = (int)$node->get('field_category')->target_id;
            $document['tags'][] = $node->get('field_category')->entity->label();
          }

          if ($node->hasField('field_tags')) {
            foreach ($node->get('field_tags') as $item) {
              $document['terms'][] = (int)$item->target_id;
              $document['tags'][] = $item->entity->label();
            }
          }
          break;

        case 'procal_entry':
          if ($node->hasField('field_procal_starts')) {
            $document['fields']['procal_entry']['starts'] = $node->get('field_procal_starts')->value;
            $document['fields']['procal_entry']['ends'] = $node->get('field_procal_ends')->value;
          }

          if ($node->hasField('field_procal_expires')) {
            $document['fields']['procal_entry']['expires'] = $node->get('field_procal_expires')->value;
          }

          if ($node->hasField('field_procal_city')) {
            $document['fields']['procal_entry']['city'] = $node->get('field_procal_city')->value;
          }

          if ($node->hasField('field_procal_location')) {
            $document['fields']['procal_entry']['location'] = $node->get('field_procal_location')->value;
          }

          if ($node->hasField('field_procal_organisation')) {
            $document['fields']['procal_entry']['organisation'] = $node->get('field_procal_organisation')->value;
          }

          if ($node->hasField('field_procal_stream')) {
            $document['fields']['procal_entry']['streamable'] = (bool)$node->get('field_procal_stream')->value;
          }

          if ($node->hasField('field_procal_groups')) {
            foreach ($node->get('field_procal_groups') as $item) {
              $document['terms'][] = (int)$item->target_id;
              $document['tags'][] = $item->entity->label();
            }
          }
        break;

      }

      $this->index($document);
    }
  }

  protected function fetchItemsForIndexing() {
    $query = $this->database->select('node_field_data', 'entity')
      ->distinct()
      ->fields('entity', ['nid'])
      ->range(0, $this->batchSize)
      ->orderBy('entity.nid')
      ->condition('entity.status', NodeInterface::PUBLISHED)
      ->condition('entity.type[]', $this->bundles, 'IN');

    // NOTE: Reindex is NULL when left join is to zero rows.
    $query->condition($query->orConditionGroup()
      ->condition('search.reindex', NULL, 'IS')
      ->condition('search.reindex', 0, '<>'));

    $query->leftJoin('kifisearch_index', 'search', 'search.entity_id = entity.nid AND search.entity_type = :type', [
      ':type' => 'node'
    ]);

    $nids = $query->execute()->fetchCol();

    if ($nids) {
      $nodes = $this->storage->loadMultiple($nids);
      return $nodes;
    } else {
      return [];
    }
  }
}
