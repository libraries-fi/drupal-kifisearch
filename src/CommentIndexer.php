<?php

namespace Drupal\kifisearch;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\comment\CommentInterface;
use Drupal\kifisearch\Plugin\Search\ContentSearch;
use Drupal\search\Plugin\SearchIndexingInterface;
use ElasticSearch\Client;
use Html2Text\Html2Text;

class CommentIndexer extends IndexerBase {
  public function getTotal() {
    $query = $this->database->select('comment_field_data', 'entity')
      ->distinct()
      ->fields('entity', ['cid'])
      ->condition('entity.status', CommentInterface::PUBLISHED)
      ->condition('entity.comment_type[]', $this->bundles, 'IN');

    $total = $query->countQuery()->execute()->fetchField();
    return $total;
  }

  public function getRemaining() {
    $query = $this->database->select('comment_field_data', 'entity')
      ->distinct()
      ->fields('entity', ['cid'])
      ->condition('entity.status', CommentInterface::PUBLISHED)
      ->condition('entity.comment_type[]', $this->bundles, 'IN');

    // NOTE: Reindex is NULL when left join is to zero rows.
    $query->condition($query->orConditionGroup()
      ->condition('search.reindex', NULL, 'IS')
      ->condition('search.reindex', 0, '<>'));

    $query->leftJoin('kifisearch_index', 'search', 'search.entity_id = entity.cid AND search.entity_type = :type', [
      ':type' => 'comment'
    ]);

    $remaining = $query->countQuery()->execute()->fetchField();

    return $remaining;
  }

  public function updateIndex() {
    foreach ($this->fetchItemsForIndexing() as $comment) {
      $langcode = $comment->language()->getId();

      $document = [
        'entity_type' => 'comment',
        'id' => (int)$comment->id(),
        'bundle' => $comment->bundle(),
        'title' => $comment->label(),
        'langcode' => $langcode,
        'created' => date('Y-m-d\TH:i:s', $comment->getCreatedTime()),
        'changed' => date('Y-m-d\TH:i:s', $comment->getChangedTime()),
        'fields' => [
          'comment' => [
            'commented_entity_type' => $comment->getCommentedEntityTypeId(),
            'commented_entity_id' => (int)$comment->getCommentedEntityId(),
            'comment_field' => $comment->getFieldName(),
          ]
        ]
      ];

      if ($comment->hasField('comment_body')) {
        $document['comment_body'] = (new Html2Text($comment->get('comment_body')->value))->getText();
      }

      $this->index($document);
    }
  }

  protected function fetchItemsForIndexing() {
    $query = $this->database->select('comment_field_data', 'entity')
      ->distinct()
      ->fields('entity', ['cid'])
      ->range(0, $this->batchSize)
      ->orderBy('entity.cid')
      ->condition('entity.status', CommentInterface::PUBLISHED)
      ->condition('entity.comment_type[]', $this->bundles, 'IN');

    // NOTE: Reindex is NULL when left join is to zero rows.
    $query->condition($query->orConditionGroup()
      ->condition('search.reindex', NULL, 'IS')
      ->condition('search.reindex', 0, '<>'));

    $query->leftJoin('kifisearch_index', 'search', 'search.entity_id = entity.cid AND search.entity_type = :type', [
      ':type' => 'comment'
    ]);

    $ids = $query->execute()->fetchCol();

    if ($ids) {
      $comments = $this->storage->loadMultiple($ids);
      return $comments;
    } else {
      return [];
    }
  }
}
