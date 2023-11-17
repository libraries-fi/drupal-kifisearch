<?php

namespace Drupal\kifisearch\Query;

use Ehann\RediSearch\Index;

class KifiIndex extends Index
{
    /**
     * @return KifiBuilder
     */
    protected function makeQueryBuilder(): KifiBuilder
    {
        return (new KifiBuilder($this->redisClient, $this->getIndexName()));
    }
}