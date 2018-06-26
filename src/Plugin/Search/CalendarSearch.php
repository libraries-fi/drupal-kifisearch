<?php

namespace Drupal\kifisearch\Plugin\Search;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Form\FormStateInterface;

/**
 * Search and indexing for asklib_question and asklib_answer entities.
 *
 * @SearchPlugin(
 *   id = "kifisearch_calendar_search",
 *   title = @Translation("Calendar")
 * )
 */
class CalendarSearch extends CustomSearchBase {
  const NODE_TYPE = 'procal_entry';
  const VOCABULARY = 'procal_groups';

  protected function compileSearchQuery($keywords) {
    $search = parent::compileSearchQuery($keywords);

    $search['query']['bool']['filter'][] = ['term' => [
      'bundle' => self::NODE_TYPE
    ]];

    $search['query']['bool']['filter'][] = ['term' => [
      'entity_type' => 'node'
    ]];

    if ($groups = $this->getParameter('g')) {
      $search['query']['bool']['filter'][] = ['terms' => [
        'terms' => explode('-', $groups)
      ]];
    }

    return $search;
  }

  public function buildSearchUrlQuery(FormStateInterface $form_state) {
    $query = parent::buildSearchUrlQuery($form_state);

    if ($groups = $form_state->getValue('groups')) {
      if ($groups = array_filter($groups)) {
        $query['g'] = implode('-', $groups);
      }
    }

    return $query;
  }

  protected function prepareResults(array $result) {
    $cache = $this->loadMatchedEntities($result);
    $prepared = [];

    foreach ($result['hits']['hits'] as $hit) {
      $entity_type = $hit['_source']['entity_type'];
      $entity_id = $hit['_source']['id'];

      if (!isset($cache[$entity_type][$entity_id])) {
        user_error(sprintf('Stale search entry: %s #%d does not exist', $entity_type, $entity_id));
        continue;
      }

      $entity = $cache[$entity_type][$entity_id];

      $build = [
        'link' => $entity->url('canonical', ['absolute' => TRUE, 'language' => $entity->language()]),
        'title' => $entity->label(),
        'date' => strtotime($hit['_source']['created']),
        'langcode' => $entity->language()->getId(),
        'extra' => [],
      ];

      if (!empty($hit['_source']['tags'])) {
        $tags = $hit['_source']['tags'];
        asort($tags);

        $build['extra']['groups'] = [
          '#plain_text' => implode(', ', $tags)
        ];
      }

      if (!empty($hit['highlight'])) {
        $matches = reset($hit['highlight']);

        $build['snippet'][] = [
          '#markup' => implode(' ... ', $matches)
        ];
      } else {
        $build['snippet'][] = [
          '#markup' => Unicode::truncate($hit['_source']['body'], 200, TRUE, TRUE)
        ];
      }

      $prepared[] = $build;
      $this->addCacheableDependency($entity);
    }

    return $prepared;
  }

  public function searchFormAlter(array &$form, FormStateInterface $form_state) {
    $form = parent::searchFormAlter($form, $form_state);

    $form['all_languages']['#access'] = FALSE;

    $form_state->addBuildInfo('form_id', 'search_form_calendar');
    $parameters = $this->getParameters() ?: [];
    $langcode = $this->languageManager->getCurrentLanguage()->getId();

    $form['group_options'] = [
      '#type' => 'container',
      '#title' => $this->t('Limit search to areas'),
      '#open' => !empty($this->getParameter('g')),
    ];

    $form['groups'] = [
      '#type' => 'container',
      [
        '#type' => 'checkboxes',
        '#title' => $this->t('Categories'),
        '#options' => $this->getAreaOptions(),
        '#default_value' => explode('-', $this->getParameter('g', '')),
        '#parents' => ['groups'],
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
}
