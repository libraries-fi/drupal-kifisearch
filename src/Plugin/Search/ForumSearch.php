<?php

namespace Drupal\kifisearch\Plugin\Search;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Search and indexing for asklib_question and asklib_answer entities.
 *
 * @SearchPlugin(
 *   id = "kifisearch_forum_search",
 *   title = @Translation("Forum")
 * )
 */
class ForumSearch extends CustomSearchBase {
  const NODE_TYPE = 'forum';
  const COMMENT_TYPE = 'comment_forum';
  const VOCABULARY = 'forums';

  protected function compileSearchQuery($keywords) {
    $search = parent::compileSearchQuery($keywords);

    if ($this->getParameter('ot')) {
      $search['query']['bool']['filter'][] = ['term' => [
        'entity_type' => 'node',
      ]];

      $search['query']['bool']['filter'][] = ['term' => [
        'bundle' => self::NODE_TYPE
      ]];
    } else {
      // Require either (type=node AND bundle=forum) OR (type=comment AND bundle=comment_forum)
      $search['query']['bool']['filter'][] = ['bool' => [
        'should' => [
          ['bool' => [
            'must' => [
              ['term' => [
                'entity_type' => 'node'
              ]],
              ['term' => [
                'bundle' => self::NODE_TYPE
              ]]
            ]
          ]],
          ['bool' => [
            'must' => [
              ['term' => [
                'entity_type' => 'comment'
              ]],
              ['term' => [
                'bundle' => self::COMMENT_TYPE
              ]],
            ]
          ]]
        ]
      ]];
    }

    if ($areas = $this->getParameter('a')) {
      $search['query']['bool']['filter'][] = ['terms' => [
        'terms' => explode('-', $areas)
      ]];
    }

    return $search;
  }

  /**
   * @param $result Elasticsearch response.
   */
  protected function prepareResults(array $result) {
    $total = $result['hits']['total'];
    $time = $result['took'];
    $rows = $result['hits']['hits'];

    $cache = $this->loadMatchedEntities($result) + ['node' => []];
    $prepared = [];

    $extra_nids = [];
    foreach ($result['hits']['hits'] as $hit) {
      if ($hit['_source']['entity_type'] == 'comment') {
        $nid = $hit['_source']['fields']['comment']['commented_entity_id'];

        if (!isset($cache['node'][$nid])) {
          $extra_nids[] = $nid;
        }
      }
    }

    if ($extra_nids) {
      $cache['node'] += $this->entityManager->getStorage('node')->loadMultiple($extra_nids);
    }

    foreach ($result['hits']['hits'] as $hit) {
      $post_id = $hit['_source']['id'];
      $entity_type = $hit['_source']['entity_type'];

      if (!isset($cache[$entity_type][$post_id])) {
        user_error(sprintf('Indexed node #%d does not exist', $hit['_source']['id']));
        continue;
      }

      if ($entity_type == 'node') {
        $thread = $cache['node'][$post_id];
      } else {
        $thread = $cache['node'][$hit['_source']['fields']['comment']['commented_entity_id']];
      }

      $area = $thread->get('taxonomy_forums')->entity;
      $this->addCacheableDependency($thread);

      $build = [
        'link' => $thread->url('canonical', ['absolute' => TRUE, 'language' => $thread->language()]),
        'entity' => $thread,
        'type' => $thread->bundle(),
        'title' => $thread->label(),
        'score' => $hit['_score'],
        'date' => strtotime($hit['_source']['created']),
        'langcode' => $thread->language()->getId(),

        'extra' => [
          'bundle_label' => [
            '#type' => 'link',
            '#url' => new Url('forum.page', ['taxonomy_term' => $area->id()]),
            '#title' => $area->label(),
            '#weight' => -100,
          ]
        ],

        'snippet' => $this->processSnippet($hit)
      ];

      $prepared[] = $build;
    }

    return $prepared;
  }

  public function searchFormAlter(array &$form, FormStateInterface $form_state) {
    $form = parent::searchFormAlter($form, $form_state);

    $form['only_titles'][0]['#title'] = $this->t('Search thread titles only');

    $form['areas'] = [
      '#type' => 'container',
      '#group' => 'advanced',
      
      [
        '#type' => 'checkboxes',
        '#title' => $this->t('Forum areas'),
        '#options' => $this->getAreaOptions(),
        '#default_value' => explode('-', $this->getParameter('a', '')),
        '#parents' => ['areas']
      ]
    ];
  }

  protected function getAreaOptions() {
    $terms = $this->entityManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => self::VOCABULARY
    ]);

    $options = [];

    foreach ($terms as $term) {
      if ($term->access('view')) {
        $options[$term->id()] = (string)$term->label();
      }
    }

    asort($options);
    return $options;
  }

  public function buildSearchUrlQuery(FormStateInterface $form_state) {
    $query = parent::buildSearchUrlQuery($form_state);

    if ($areas = $form_state->getValue('areas')) {
      if ($areas = array_filter($areas)) {
        $query['a'] = implode('-', $areas);
      }
    }

    return $query;
  }
}
