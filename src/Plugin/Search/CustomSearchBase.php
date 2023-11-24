<?php

namespace Drupal\kifisearch\Plugin\Search;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\search\Plugin\SearchPluginBase;
use Ehann\RediSearch\Index;
use Ehann\RediSearch\Query\BuilderInterface;
use Elasticsearch\Client;
use Elasticsearch\Common\Exceptions\BadRequest400Exception;
use Elasticsearch\Common\Exceptions\NoNodesAvailableException;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class CustomSearchBase extends SearchPluginBase {
  protected $entityManager;
  protected $languageManager;
  protected Index $kifi_index;

  public const PAGE_SIZE = 10;

  static public function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('kifisearch.client')
    );
  }

  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_manager, LanguageManagerInterface $languages, Index $kifi_index) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityManager = $entity_manager;
    $this->languageManager = $languages;
    $this->kifi_index = $kifi_index;
  }

  public function suggestedTitle() {
    if (!empty($this->keywords)) {
      return $this->t('Search for \'@keywords\'', ['@keywords' => Unicode::truncate($this->keywords, 60, TRUE, TRUE)]);
    } else {
      return $this->t('Search');
    }
  }

  public function execute() {
    try {
      if ($this->isSearchExecutable() && $result = $this->findResults()) {
        \Drupal::service('pager.manager')->createPager($result['total'], self::PAGE_SIZE, 0)->getCurrentPage();
        return $this->prepareResults($result);
      }
    } catch (BadRequest400Exception $error) {
      $this->messenger()->addError(t('Query contained errors.'));
    } catch (NoNodesAvailableException $error) {
      $this->messenger()->addError(t('Could not connect to database'));
    }
    return [];
  }

  public function isSearchExecutable() {
    $params = array_filter($this->searchParameters);
    unset($params['all_languages']);
    return !empty($params);
  }

  public function searchFormAlter(array &$form, FormStateInterface $form_state) {
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    $parameters = $this->getParameters() ?: [];

    $has_extra = !empty(array_diff(array_keys(array_filter($parameters)), ['keys']));

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced search'),
      '#open' => $has_extra,
    ];

    // Using this structure allows easy re-parenting of fields in subclasses!
    $form['only_titles'] = [
      '#type' => 'container',
      '#group' => 'advanced',

      [
        '#type' => 'checkbox',
        '#title' => $this->t('Search titles only'),
        '#default_value' => $this->getParameter('ot'),
        '#parents' => ['only_titles']
      ]
    ];

    $form['all_languages'] = [
      '#type' => 'container',
      '#group' => 'advanced',

      [
        '#type' => 'checkbox',
        '#title' => $this->t('Search all languages'),
        '#default_value' => $this->getParameter('anylang'),
        '#parents' => ['all_languages']
      ]
    ];

    $form['date_range'] = [
      '#type' => 'container',
      '#group' => 'advanced',
    ];

    $form['date_from'] = [
      '#type' => 'container',
      '#group' => 'date_range',

      [
        '#type' => 'date',
        '#title' => $this->t('Date from'),
        '#default_value' => $this->getParameter('df'),
        '#parents' => ['date_from'],
      ]
    ];

    $form['date_until'] = [
      '#type' => 'container',
      '#group' => 'date_range',

      [
        '#type' => 'date',
        '#title' => $this->t('Date until'),
        '#default_value' => $this->getParameter('du'),
        '#parents' => ['date_until'],
      ]
    ];

    return $form;
  }

  public function buildSearchUrlQuery(FormStateInterface $form_state) {
    $query = parent::buildSearchUrlQuery($form_state);

    if ($form_state->getValue('only_titles')) {
      $query['ot'] = '1';
    }

    if ($form_state->getValue('all_languages')) {
      $query['anylang'] = '1';
    }

    if ($from = $form_state->getValue('date_from')) {
      $query['df'] = $from;
    }

    if ($until = $form_state->getValue('date_until')) {
      $query['du'] = $until;
    }

    return $query;
  }

  protected function compileSearchQuery(BuilderInterface &$search_query, $keywords) {

    if ($this->getParameter('anylang', 'no') == 'no')
    {
      $language_code = \Drupal::languageManager()->getCurrentLanguage()->getId();
      $search_query->tagFilter('langcode', [$language_code]);
    }

    // Filter search only from title
    if ($this->getParameter('ot', 'no') !== 'no')
    {
      // NOTE: Should we allow searching for multiple words? This can be done
      // in separating the search words with | symbol.
      $this->keywords = '@title:(' . $this->keywords . ')';
    }

    // Date From
    if ($from = $this->getParameter('df'))
    {
      $search_query->numericFilter('created', strtotime($from));
    }

    // Date until
    if ($until = $this->getParameter('du'))
    {
      $search_query->numericFilter('created', 0, strtotime($until));
    }
  }


  protected function getParameter($name, $default = null) {
    return $this->searchParameters[$name] ?? $default;
  }

  protected function processSnippet(array $hit) {

    // Check if $hit['body'] contains any strong tags.
    if (strpos($hit['body'], '<strong') !== FALSE) {

      $snippet = [
        '#markup' => Unicode::truncate($hit['body'], 200, TRUE, TRUE)
      ];

      // In rare cases, the truncate might leave '<strong...' or '</strong...'
      // at the end of the snippet. Remove them altogether.
      $snippet['#markup'] = preg_replace('/<\/?strong[^>]*$/', '', $snippet['#markup']);

      $last_open_strong = strrpos($snippet['#markup'], '<strong>');
      $last_close_strong = strrpos($snippet['#markup'], '</strong>');
      if ($last_close_strong < $last_open_strong || $last_open_strong === FALSE) {
        // The last strong tag is not closed. Close it.
        $snippet['#markup'] .= '</strong>';
      }

    } else {
      $snippet = [
        '#markup' => Unicode::truncate($hit['body'], 200, TRUE, TRUE)
      ];
    }
    return $snippet;
  }

  protected function findResults() {

    $parameters = $this->getParameters();
    $skip = $this->getParameter('page', 0) * self::PAGE_SIZE;

    // print json_encode($query);
    $search_query = $this->kifi_index
    ->withScores()
    ->limit($skip, self::PAGE_SIZE)
    // Should we use RediSearch's own summarizer or Drupal's?
    // ->summarize(['title'], 5, 200)
    ->highlight(['body']);

    // Apply content specific filters
    $this->compileSearchQuery($search_query, $this->keywords);

    $search_result = $search_query->search($this->keywords, true);
    $result = [
      'hits' => $search_result->getDocuments(),
      //'type' => 'content',
      'total' => $search_result->getCount(),
      'from' => $skip,
      'size' => self::PAGE_SIZE,
    ];

    return $result;
  }

  protected function loadMatchedEntities(array $result) {
    $cacheable_entities = [];
    $cache = [];

    foreach ($result['hits'] as $entry) {
      $entity_type = $entry['entity_type'];
      $cacheable_entities[$entity_type][] = $entry['entity_id'];
    }

    foreach ($cacheable_entities as $type => $ids) {
      $cache[$type] = $this->entityManager->getStorage($type)->loadMultiple($ids);
    }

    return $cache;
  }
}
