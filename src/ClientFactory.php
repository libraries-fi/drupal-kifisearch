<?php

namespace Drupal\kifisearch;

use Ehann\RediSearch\Fields\NumericField;
use Elasticsearch\ClientBuilder;
use Ehann\RedisRaw\PhpRedisAdapter;
use Ehann\RediSearch\Index;
use Psr\Log\LoggerInterface;

class ClientFactory {
  public function create(LoggerInterface $logger) {
    $redis = (new PhpRedisAdapter())->connect('127.0.0.1', 6379);
    $redis->setLogger($logger);
    $kifiIndex = new Index($redis, 'kirjastot_fi');

    // Create the schema (this needs to be done whether the index actually exists or not):
    $kifiIndex
      ->addNumericField('entity_id')
      ->addTagField('entity_type')
      ->addTagField('bundle')
      ->addTagField('langcode')
      ->addTextField(name: 'title', sortable: true)
      ->addTextField('body')
      ->addNumericField('terms')
      ->addTagField('tags')
      ->addNumericField('created', true)
      ->addNumericField('changed', true)
      
      // Comment specific fields
      ->addTagField('commented_entity_type')
      ->addNumericField('commented_entity_id')
      ->addTagField('comment_field')
      // Procal specific fields
      ->addNumericField('procal_starts', true)
      ->addNumericField('procal_ends', true)
      ->addNumericField('procal_expires', true)
      ->addTextField('procal_location')
      ->addTextField('procal_organisation')
      ->addNumericField('procal_streamable')
      // evrecipe
      ->addTextField('evrecipe_organizer')

      // question
      ->addNumericField('score', true)

      // For sorting
      ->addNumericField('year', true);


    // Should this be cached? The "exists()" check seems do a lot of things.
    if ($kifiIndex->exists() === FALSE)
    {
      $kifiIndex->create();
    }

    return $kifiIndex;
  }
}
