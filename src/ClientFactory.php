<?php

namespace Drupal\kifisearch;

use Elasticsearch\ClientBuilder;

class ClientFactory {
  public function create() {
    return ClientBuilder::create()->build();
  }
}
