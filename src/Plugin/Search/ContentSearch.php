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

use Drupal\kifisearch\CommentIndexer;
use Drupal\kifisearch\NodeIndexer;

/**
 * Search and indexing for asklib_question and asklib_answer entities.
 *
 * @SearchPlugin(
 *   id = "kifisearch",
 *   title = @Translation("Content (Elasticsearch)")
 * )
 */
class ContentSearch extends SearchPluginBase implements SearchIndexingInterface {
  // Should match the ID defined in the @SearchPlugin annotation.
  const SEARCH_ID = 'kifisearch';

  const ALLOWED_NODE_TYPES = [
    'announcement',
    'buildings',
    'evrecipe',
    'forum',
    'matbank_item',
    'page',
    'procal_entry',
  ];

  const ALLOWED_COMMENT_TYPES = [
    // 'comment',
    // 'comment_asklib',
    'comment_forum',
  ];

  protected $entityManager;
  protected $languageManager;
  protected $dates;
  protected $database;
  protected $searchSettings;
  protected $client;

  static public function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('language_manager'),
      $container->get('date.formatter'),
      $container->get('database'),
      $container->get('config.factory')->get('search.settings')
    );
  }

  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_manager, EntityFieldManagerInterface $field_manager, LanguageManagerInterface $languages, DateFormatterInterface $date_formatter, Connection $database, Config $search_settings) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityManager = $entity_manager;
    $this->languageManager = $languages;
    $this->dates = $date_formatter;
    $this->database = $database;
    $this->searchSettings = $search_settings;

    $this->client = \Elasticsearch\ClientBuilder::create()->build();

    $batch_size = $this->searchSettings->get('index.cron_limit');
    $node_storage = $entity_manager->getStorage('node');

    $this->nodeIndexer = new NodeIndexer($database, $node_storage, $this->client, self::ALLOWED_NODE_TYPES, $batch_size);

    $comment_storage = $entity_manager->getStorage('comment');

    $this->commentIndexer = new CommentIndexer($database, $comment_storage, $this->client, self::ALLOWED_COMMENT_TYPES, $batch_size);
  }

  public function execute() {
    try {
      if ($this->isSearchExecutable() && $results = $this->findResults()) {
        return $this->prepareResults($results);
      }
    } catch (BadRequest400Exception $error) {
      var_dump($error->getMessage());
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

  protected function loadMatchedEntities(array $result) {
    $cacheable_entities = [];
    $cache = [];

    foreach ($result['hits']['hits'] as $entry) {
      $entity_type = $entry['_source']['entity_type'];
      $cacheable_entities[$entity_type][] = $entry['_source']['id'];
    }

    foreach ($cacheable_entities as $type => $ids) {
      $cache[$type] = $this->entityManager->getStorage($type)->loadMultiple($ids);
    }

    return $cache;
  }

  /**
   * @param $result Elasticsearch response.
   */
  protected function prepareResults(array $result) {
    $total = $result['hits']['total'];
    $time = $result['took'];
    $rows = $result['hits']['hits'];

    $nids = array_map(function($row) { return $row['_source']['id']; }, $rows);
    $nodes = $this->entityManager->getStorage('node')->loadMultiple($nids);
    $prepared = [];

    $bids = array_unique(array_map(function($item) { return $item['_source']['bundle']; }, $result['hits']['hits']));
    $bundles = $this->entityManager->getStorage('node_type')->loadMultiple($bids);

    $cache = $this->loadMatchedEntities($result);

    pager_default_initialize($total, 10);

    foreach ($result['hits']['hits'] as $item) {
      $entity_type = $item['_source']['entity_type'];
      $entity_id = $item['_source']['id'];

      if (!isset($cache[$entity_type][$entity_id])) {
        user_error(sprintf('Stale search entry: %s #%d does not exist', $entity_type, $entity_id));
        continue;
      }

      $entity = $cache[$entity_type][$entity_id];

      $build = [
        'link' => $entity->url('canonical', ['absolute' => TRUE, 'language' => $entity->language()]),
        'entity' => $entity,
        'type' => $entity->bundle(),
        'title' => $entity->label(),
        'score' => $item['_score'],
        'date' => strtotime($item['_source']['created']),
        'langcode' => $entity->language()->getId(),
        'extra' => [],
      ];

      if (!empty($item['highlight'])) {
        $matches = reset($item['highlight']);

        foreach ($matches as $match) {
          $build['snippet'][] = [
            '#markup' => $match
          ];
        }
      }

      if (isset($bundles[$entity->bundle()])) {
        $build['extra']['type_label'] = $bundles[$entity->bundle()]->label();
      } else {
        $build['extra']['type_label'] = $entity->getEntityType()->getLabel();
      }

      $prepared[] = $build;

      $this->addCacheableDependency($entity);
    }

    return $prepared;
  }

  public function indexStatus() {
    $node_status = $this->nodeIndexer->indexStatus();
    $comment_status = $this->commentIndexer->indexStatus();

    return [
      'total' => $node_status['total'] + $comment_status['total'],
      'remaining' => $node_status['remaining'] + $comment_status['remaining'],
    ];
  }

  public function updateIndex() {
    $indexers = [$this->nodeIndexer, $this->commentIndexer];

    // Execute (only) the first indexer that has something to do.
    foreach ($indexers as $indexer) {
      if ($indexer->getRemaining()) {
        return $indexer->updateIndex();
      }
    }
  }

  public function markForReindex() {
    $this->database->query('UPDATE {kifisearch_index} SET reindex = 1');
  }

  public function indexClear() {
    $this->database->query('DELETE FROM {kifisearch_index}');
  }

  protected function compileSearchQuery($query_string) {
    $langcode = $this->languageManager->getCurrentLanguage()->getId();

    $query = [
      'bool' => [
        // 'must' => [],
        // 'should' => [],
      ]
    ];

    $query['bool']['must'][] = [
      'multi_match' => [
        'query' => $query_string,
        // 'fuzziness' => 15,
        'fields' => [
          'body',
          'title',
          'tags',

          // Some custom fields to do searching from.
          // Have to list them one-by-one because ES will fail with fields of wrong type.
          'fields.evrecipe.organiser',
          'fields.procal_entry.city',
          'fields.procal_entry.location',
          'fields.procal_entry.organisation',
        ],
      ]
    ];

    if ($langcode) {
      $query['bool']['must'][] = [
        'term' => ['langcode' => [
          'value' => $langcode,
        ]],
      ];
    }

    return [
      'query' => $query,
      'highlight' => [
        'fields' => ['body' => (object)[]],
        'pre_tags' => ['<strong>'],
        'post_tags' => ['</strong>'],
      ]
    ];
  }

  public function isSearchExecutable() {
    $params = array_filter($this->searchParameters);
    unset($params['all_languages']);
    return !empty($params);
  }

  public function searchFormAlter(array &$form, FormStateInterface $form_state) {
    $parameters = $this->getParameters() ?: [];
    $langcode = $this->languageManager->getCurrentLanguage()->getId();

    if (isset($parameters['tags']) && $tags = $parameters['tags']) {
      $tags = $this->entityManager->getStorage('taxonomy_term')->loadMultiple(Tags::explode($tags));
    } else {
      $tags = [];
    }

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => t('Advanced search'),
      '#open' => count(array_diff(array_keys($parameters), ['page', 'keys'])) > 1,
      'all_languages' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Search all languages'),
        '#default_value' => !empty($parameters['all_languages'])
      ],
    ];

    $form['advanced']['action'] = [
      '#type' => 'container',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Advanced search'),
      ]
    ];
  }

  public function buildSearchUrlQuery(FormStateInterface $form_state) {
    $query = parent::buildSearchUrlQuery($form_state);

    if ($form_state->getValue('all_languages')) {
      $query['all_languages'] = '1';
    }

    return $query;
  }
}
