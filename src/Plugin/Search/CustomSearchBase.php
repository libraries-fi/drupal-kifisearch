<?php

namespace Drupal\kifisearch\Plugin\Search;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\search\Plugin\SearchPluginBase;
use Elasticsearch\Client;
use Elasticsearch\Common\Exceptions\BadRequest400Exception;
use Elasticsearch\Common\Exceptions\NoNodesAvailableException;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class CustomSearchBase extends SearchPluginBase {
  protected $entityManager;
  protected $languageManager;
  protected $client;

  const PAGE_SIZE = 10;

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

  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_manager, LanguageManagerInterface $languages, Client $client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityManager = $entity_manager;
    $this->languageManager = $languages;
    $this->client = $client;
  }

  public function execute() {
    try {
      if ($this->isSearchExecutable() && $result = $this->findResults()) {
        pager_default_initialize($result['hits']['total'], self::PAGE_SIZE);
        return $this->prepareResults($result);
      }
    } catch (BadRequest400Exception $error) {
      var_dump($error);
      drupal_set_message(t('Query contained errors.'), 'error');
    } catch (NoNodesAvailableException $error) {
      drupal_set_message(t('Could not connect to database'), 'error');
    }
    return [];
  }

  public function isSearchExecutable() {
    $params = array_filter($this->searchParameters);
    unset($params['all_languages']);
    return !empty($params);
  }

  public function searchFormAlter(array &$form, FormStateInterface $form_state) {
    $parameters = $this->getParameters() ?: [];
    $langcode = $this->languageManager->getCurrentLanguage()->getId();

    // Using this structure allows easy re-parenting of fields in subclasses!
    $form['only_titles'] = [
      '#type' => 'container',

      [
        '#type' => 'checkbox',
        '#title' => $this->t('Search titles only'),
        '#default_value' => $this->getParameter('ot'),
        '#parents' => ['only_titles']
      ]
    ];

    $form['all_languages'] = [
      '#type' => 'container',

      [
        '#type' => 'checkbox',
        '#title' => $this->t('Search all languages'),
        '#default_value' => $this->getParameter('anylang'),
        '#parents' => ['all_languages']
      ]
    ];

    $form['date_range'] = [
      '#type' => 'container',

      'from' => [
        '#type' => 'date',
        '#title' => $this->t('Date from'),
        '#default_value' => $this->getParameter('df'),
      ],
      'until' => [
        '#type' => 'date',
        '#title' => $this->t('Date until'),
        '#default_value' => $this->getParameter('du'),
      ],
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

    if ($from = $form_state->getValue('from')) {
      $query['df'] = $from;
    }

    if ($until = $form_state->getValue('until')) {
      $query['du'] = $until;
    }

    return $query;
  }

  protected function compileSearchQuery($keywords) {
    // if (!empty(trim($keywords))) {
    //   $keywords = mb_strtolower($keywords);
    //   $keywords = preg_replace('/[^[:alnum:]_-]/u', ' ', $keywords);
    //   $words = preg_split('/\s+/', $keywords);
    //   $words = array_map(function($word) { return $word . '*'; }, $words);
    //   $query_string = implode(' OR ', array_merge([$keywords], $words));
    //
    //   if ($query_string) {
    //     $query['bool']['must'][] = [
    //       'query_string' => [
    //         'fields' => $this->getParameter('ot') ? ['title'] : ['title', 'body'],
    //         'query' => $query_string
    //       ]
    //     ];
    //   }
    // }

    if (!empty(trim($keywords))) {
      if ($this->getParameter('ot', 'no') == 'no') {
        $search['query']['bool']['must'][] = [
          'multi_match' => [
            'query' => $keywords,
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

        $search['highlight'] = [
          'fields' => ['body' => (object)[]],
          'pre_tags' => ['<strong>'],
          'post_tags' => ['</strong>'],
        ];
      } else {
        // Perform simple sanity check because 'query_string' query will crash easily.
        $keywords = mb_strtolower($keywords);
        $keywords = preg_replace('/[^\w\s]/u', '', $keywords);

        // Using 'query_string' query because the Finnish stemmer cannot process compound words.
        $search['query']['bool']['must'][] = [
          'query_string' => [
            'fields' => ['title'],
            'query' => "*{$keywords}*",
          ]
        ];
      }
    }

    if ($this->getParameter('anylang', 'no') == 'no') {
      $search['query']['bool']['filter'][] = ['term' => [
        'langcode' => $this->languageManager->getCurrentLanguage()->getId()
      ]];
    }

    return $search;
  }


  protected function getParameter($name, $default = null) {
    return isset($this->searchParameters[$name]) ? $this->searchParameters[$name] : $default;
  }

  protected function findResults() {
    $query = $this->compileSearchQuery($this->keywords);
    $parameters = $this->getParameters();
    $skip = $this->getParameter('page', 0) * self::PAGE_SIZE;

    print json_encode($query);

    $result = $this->client->search([
      'index' => 'kirjastot_fi',
      'type' => 'content',
      'body' => $query,
      'from' => $skip,
      'size' => self::PAGE_SIZE,
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
}
