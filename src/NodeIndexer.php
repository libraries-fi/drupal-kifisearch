<?php

namespace Drupal\kifisearch;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\devel\Twig\Extension\Debug;
use Drupal\node\NodeInterface;
use Drupal\kifisearch\Plugin\Search\ContentSearch;
use Drupal\search\Plugin\SearchIndexingInterface;
use ElasticSearch\Client;

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
      foreach ($node->getTranslationLanguages() as $language) {
        $langcode = $language->getId();
        $node = $node->getTranslation($langcode);

        $document = [
          'entity_type' => 'node',
          'id' => (int)$node->id(),
          'bundle' => $node->bundle(),
          'title' => $node->label(),
          'langcode' => $langcode,
          'created' => date('Y-m-d\TH:i:s', $node->getCreatedTime()),
          'changed' => date('Y-m-d\TH:i:s', $node->getChangedTime()),
        ];

        switch ($node->getType()) {
          case 'forum':
            $document += $this->extractTermsFromEntity($node, ['taxonomy_forums']);
            break;

          case 'page':
            // Nothing to index.
            break;

          case 'announcement':
            $document += $this->extractTermsFromEntity($node, ['field_groups']);
            break;

          case 'evrecipe':
            /*
             * NOTE: Fields category and target are not entity refs but string lists, so there is
             * no actual term to index...
             */

            if ($node->hasField('field_evrecipe_category')) {
              foreach ($node->get('field_evrecipe_category') as $field) {
                $document['tags'][] = $field->value;
              }
            }

            if ($node->hasField('field_evrecipe_target')) {
              foreach ($node->get('field_evrecipe_target') as $field) {
                $document['tags'][] = $field->value;
              }
            }

            if ($node->hasField('field_evrecipe_description')) {
              $document['body'] = $this->stripHtml($node->get('field_evrecipe_description')->value);
            }

            if ($node->hasField('field_evrecipe_implementor')) {
              $document['evrecipe_organiser'] = $node->get('field_evrecipe_implementor')->value;
            }

            break;

          case 'matbank_item':
            $document += $this->extractTermsFromEntity($node, [
              'field_category',
              'field_tags'
            ]);

            break;

          case 'procal_entry':
            $document += $this->extractTermsFromEntity($node, ['field_groups']);
            
            if ($node->hasField('field_procal_starts')) {
              $document['procal_starts'] = $node->get('field_procal_starts')->value;
              $document['procal_ends'] = $node->get('field_procal_ends')->value;
            }

            if ($node->hasField('field_procal_expires') && !empty($node->get('field_procal_expires')->value)) {
              $document['procal_expires'] = strtotime($node->get('field_procal_expires')->value);
            }

            if ($node->hasField('field_procal_city')) {
              $document['procal_city'] = $node->get('field_procal_city')->value;
            }

            if ($node->hasField('field_procal_location')) {
              $document['procal_location'] = $node->get('field_procal_location')->value;
            }

            if ($node->hasField('field_procal_organisation')) {
              $document['procal_organisation'] = $node->get('field_procal_organisation')->value;
            }

            if ($node->hasField('field_procal_stream')) {
              $document['procal_streamable'] = (int)$node->get('field_procal_stream')->value;
            }

            break;
        }

        if ($node->hasField('body')) {
          $document['body'] = $this->stripHtml($node->get('body')->value);
        }

        $this->index($document);
      }
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
      return $this->storage->loadMultiple($nids);
    } else {
      return [];
    }
  }
}
