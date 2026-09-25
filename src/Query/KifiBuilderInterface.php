<?php

namespace Drupal\kifisearch\Query;

use Ehann\RediSearch\Query\BuilderInterface;

interface KifiBuilderInterface extends BuilderInterface {
    public function inverseSimpleTagFilter(string $fieldName, string $value): KifiBuilderInterface;
}