<?php

namespace Drupal\kifisearch\Plugin\Search;

use DateTime;
use InvalidArgumentException;
use Drupal\Component\Utility\Tags;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\Config;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\kifisearch\NodeIndexer;
use Drupal\search\Plugin\SearchIndexingInterface;
use Drupal\search\Plugin\SearchPluginBase;
use Ehann\RediSearch\Index;
use Ehann\RediSearch\Query\BuilderInterface;
use Elasticsearch\Client;
use Elasticsearch\Common\Exceptions\BadRequest400Exception;
use Elasticsearch\Common\Exceptions\NoNodesAvailableException;
use Html2Text\Html2Text;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Search and indexing for asklib_question and asklib_answer entities.
 *
 * @SearchPlugin(
 *   id = "kifisearch",
 *   title = @Translation("All content")
 * )
 */
class ContentSearch extends CustomSearchBase implements SearchIndexingInterface {
  // Should match the ID defined in the @SearchPlugin annotation.
  public const SEARCH_ID = 'kifisearch';

  public const ALLOWED_NODE_TYPES = [
    'announcement',
    'buildings',
    'evrecipe',
    'matbank_item',
    'page',
    'procal_entry',
  ];

  protected $database;
  protected $searchSettings;

  protected NodeIndexer $nodeIndexer;

  static public function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('kifisearch.client'),
      $container->get('database'),
      $container->get('config.factory')->get('search.settings')
    );
  }

  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_manager, LanguageManagerInterface $languages, Index $kifi_index, Connection $database, Config $search_settings) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_manager, $languages, $kifi_index);

    $this->database = $database;
    $this->searchSettings = $search_settings;

    $batch_size = $this->searchSettings->get('index.cron_limit');
    $node_storage = $entity_manager->getStorage('node');

    $this->nodeIndexer = new NodeIndexer($database, $node_storage, $this->kifi_index, self::ALLOWED_NODE_TYPES, $batch_size);
  }

  /**
   * @param $result Elasticsearch response.
   */
  protected function prepareResults(array $result) {


    $prepared = [];

    $bids = array_unique(array_map(fn($hit) => $hit['bundle'], $result['hits']));
    $bundles = $this->entityManager->getStorage('node_type')->loadMultiple($bids);

    $cache = $this->loadMatchedEntities($result);

    foreach ($result['hits'] as $hit) {
      $entity_type = $hit['entity_type'];
      $entity_id = $hit['entity_id'];

      if (!isset($cache[$entity_type][$entity_id])) {
        user_error(sprintf('Stale search entry: %s #%d does not exist', $entity_type, $entity_id));
        continue;
      }

      $entity = $cache[$entity_type][$entity_id];

      $build = [
        'link' => $entity->toUrl('canonical', ['absolute' => TRUE, 'language' => $entity->language()])->toString(),
        'entity' => $entity,
        'type' => $entity->bundle(),
        'title' => $entity->label(),
        'score' => $hit['score'],
        'date' => $hit['created'],
        'langcode' => $entity->language()->getId(),
        'extra' => [],
        'snippet' => $this->processSnippet($hit),
      ];

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

  protected function compileSearchQuery(BuilderInterface &$search_query, $keywords) {
    parent::compileSearchQuery($search_query, $keywords);
    // Use the default ordering for content.
    $search_query->sortBy('year', 'DESC');
  }


  public function indexStatus() {
    $node_status = $this->nodeIndexer->indexStatus();

    return [
      'total' => $node_status['total'],
      'remaining' => $node_status['remaining'],
    ];
  }

  public function updateIndex() {
    $indexers = [$this->nodeIndexer];

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
}
