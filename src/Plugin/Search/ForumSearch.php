<?php

namespace Drupal\kifisearch\Plugin\Search;

use DateTime;
use InvalidArgumentException;
use Drupal\Component\Utility\Tags;
use Drupal\Core\Access\AccessibleInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\Config;
use Drupal\Core\Database\Connection;
use Drupal\Core\DateTime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\search\Plugin\SearchIndexingInterface;
use Drupal\search\Plugin\SearchPluginBase;
use Elasticsearch\Common\Exceptions\BadRequest400Exception;
use Elasticsearch\Common\Exceptions\NoNodesAvailableException;
use Html2Text\Html2Text;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
 * Search and indexing for asklib_question and asklib_answer entities.
 *
 * @SearchPlugin(
 *   id = "kifisearch_forum_search",
 *   title = @Translation("Forum (Elasticsearch)")
 * )
 */
class ForumSearch extends SearchPluginBase {
  const NODE_TYPE = 'forum';
  const COMMENT_TYPE = 'comment_forum';
  const VOCABULARY = 'forums';

  static public function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('language_manager'),
      $container->get('date.formatter'),
      $container->get('state'),
      $container->get('database'),
      $container->get('renderer'),
      $container->get('config.factory')->get('search.settings')
    );
  }

  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_manager, EntityFieldManagerInterface $field_manager, LanguageManagerInterface $languages, DateFormatterInterface $date_formatter, StateInterface $state, Connection $database, RendererInterface $renderer, Config $search_settings) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityManager = $entity_manager;
    $this->fieldManager = $field_manager;
    $this->languageManager = $languages;
    $this->dates = $date_formatter;
    $this->state = $state;
    $this->database = $database;
    $this->renderer = $renderer;
    $this->searchSettings = $search_settings;

    $this->client = \Elasticsearch\ClientBuilder::create()->build();
  }

  public function execute() {
    try {
      if ($this->isSearchExecutable() && $results = $this->findResults()) {
        return $this->prepareResults($results);
      }
    } catch (BadRequest400Exception $error) {
      drupal_set_message(t('Query contained errors.'), 'error');
    } catch (NoNodesAvailableException $error) {
      drupal_set_message(t('Could not connect to database'), 'error');
    }
    return [];
  }

  protected function findResults() {
    $query = $this->compileSearchQuery($this->keywords);
    $parameters = $this->getParameters();
    $skip = empty($parameters['page']) ? 0 : $parameters['page'] * 10;

    $result = $this->client->search([
      'index' => 'kirjastot_fi',
      'type' => 'content',
      'body' => $query,
      'from' => $skip,
    ]);

    return $result;
  }

  protected function compileSearchQuery($keywords) {
    $query = [
      'bool' => [
        'must' => [
          // conditions here
        ],
      ]
    ];

    if ($this->getParameter('ot')) {
      $query['bool']['must'][] = ['term' => [
        'entity_type' => 'node',
      ]];

      $query['bool']['must'][] = ['term' => [
        'bundle' => self::NODE_TYPE
      ]];
    } else {
      // Require either (type=node AND bundle=forum) OR (type=comment AND bundle=comment_forum)
      $query['bool']['must'][] = ['bool' => [
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
              // Does not yet exist...
              // ['term' => [
              //   'fields.comment.commented_bundle' => self::NODE_TYPE
              // ]]
            ]
          ]]
        ]
      ]];
    }

    if (!empty(trim($keywords))) {
      $keywords = mb_strtolower($keywords);
      $keywords = preg_replace('/[^[:alnum:]_-]/u', ' ', $keywords);
      $words = preg_split('/\s+/', $keywords);
      $words = array_map(function($word) { return $word . '*'; }, $words);
      $query_string = implode(' OR ', array_merge([$keywords], $words));

      if ($query_string) {
        $query['bool']['must'][] = ['query_string' => [
          'fields' => $this->getParameter('ot') ? ['title'] : ['title', 'body'],
          'query' => $query_string
        ]];
      }
    }

    if ($areas = $this->getParameter('a')) {
      $query['bool']['must'][] = ['terms' => [
        'terms' => explode('-', $areas)
      ]];
    }

    if ($from = $this->getParameter('df')) {
      $query['bool']['must'][] = ['range' => [
        'created' => [
          'gte' => $from
        ]
      ]];
    }

    if ($until = $this->getParameter('du')) {
      $query['bool']['must'][] = ['range' => [
        'created' => [
          'lte' => $until
        ]
      ]];
    }

    $search = [
      'query' => $query,
      'highlight' => ['fields' => [
        'body' => (object)[],
      ]]
    ];

    if (!empty($filter)) {
      $search['filter'] = $filter;
    }

    // print json_encode($search);

    return $search;
  }

  /**
   * @param $result Elasticsearch response.
   */
  protected function prepareResults(array $result) {
    $total = $result['hits']['total'];
    $time = $result['took'];
    $rows = $result['hits']['hits'];

    $nids = [];
    $cids = [];

    foreach ($rows as $row) {
      if ($row['_source']['entity_type'] == 'node') {
        $nids[] = $row['_source']['id'];
      } elseif ($row['_source']['entity_type'] == 'comment') {
        $cids[] = $row['_source']['id'];
        $nids[] = $row['_source']['fields']['comment']['commented_entity_id'];
      }
    }

    $nodes = $this->entityManager->getStorage('node')->loadMultiple($nids);
    $comments = $this->entityManager->getStorage('comment')->loadMultiple($cids);
    $prepared = [];

    pager_default_initialize($total, 10);

    foreach ($result['hits']['hits'] as $item) {
      $post_id = $item['_source']['id'];
      if (!isset($nodes[$post_id]) && !isset($comments[$post_id])) {
        user_error(sprintf('Indexed node #%d does not exist', $item['_source']['id']));
        continue;
      }

      $data = $item['_source'];

      if ($data['entity_type'] == 'node') {
        $thread = $nodes[$data['id']];
      } else {
        $thread = $nodes[$data['fields']['comment']['commented_entity_id']];
      }

      $area = $thread->get('taxonomy_forums')->entity;
      $this->addCacheableDependency($thread);

      $build = [
        'link' => $thread->url('canonical', ['absolute' => TRUE, 'language' => $thread->language()]),
        'node' => $thread,
        'type' => $thread->bundle(),
        'title' => $thread->label(),
        'score' => $item['_score'],
        'date' => strtotime($data['created']),
        'langcode' => $thread->language()->getId(),

        'extra' => [
          'bundle_label' => [
            '#type' => 'link',
            '#url' => new Url('forum.page', ['taxonomy_term' => $area->id()]),
            '#title' => $area->label(),
            '#weight' => -100,
          ]
        ],

        'snippet' => search_excerpt($this->keywords, implode(' ', [$data['body']]), $data['langcode']),
      ];

      $prepared[] = $build;
    }

    return $prepared;
  }

  public function isSearchExecutable() {
    $params = array_filter($this->searchParameters);
    unset($params['all_languages']);
    return !empty($params);
  }

  public function searchFormAlter(array &$form, FormStateInterface $form_state) {
    $parameters = $this->getParameters() ?: [];
    $langcode = $this->languageManager->getCurrentLanguage()->getId();

    $form['only_titles'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Search thread titles only'),
      '#default_value' => $this->getParameter('ot'),
    ];

    $form['area_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Limit search to areas'),
      '#open' => !empty($this->getParameter('a')),
    ];

    $form['area_options']['areas'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Forum areas'),
      '#options' => $this->getAreaOptions(),
      '#default_value' => explode('-', $this->getParameter('a', '')),
    ];

    $form['date_range'] = [
      '#type' => 'details',
      '#title' => $this->t('Filter by date'),
      '#open' => (bool)array_filter([$this->getParameter('df'), $this->getParameter('du')]),
      '#attributes' => [
        'class' => ['form-inline']
      ],
    ];

    $form['date_range']['from'] = [
      '#type' => 'date',
      '#title' => $this->t('Date from'),
      '#default_value' => $this->getParameter('df'),
    ];

    $form['date_range']['until'] = [
      '#type' => 'date',
      '#title' => $this->t('Date until'),
      '#default_value' => $this->getParameter('du'),
    ];
  }

  protected function getParameter($name, $default = null) {
    return isset($this->searchParameters[$name]) ? $this->searchParameters[$name] : $default;
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

    if ($form_state->getValue('only_titles')) {
      $query['ot'] = 1;
    }

    if ($areas = $form_state->getValue('areas')) {
      if ($areas = array_filter($areas)) {
        $query['a'] = implode('-', $areas);
      }
    }

    if ($from = $form_state->getValue('from')) {
      $query['df'] = $from;
    }

    if ($until = $form_state->getValue('until')) {
      $query['du'] = $until;
    }

    return $query;
  }
}
