<?php

namespace Drupal\kifisearch\Query;

use Ehann\RediSearch\RediSearchRedisClient;
use Ehann\RediSearch\Query\Builder as QueryBuilder;

class KifiBuilder extends QueryBuilder
{
    private $indexName;

    public function __construct(RediSearchRedisClient $redis, string $indexName)
    {
        $this->redis = $redis;
        $this->indexName = $indexName;
    }

    // Override Builder:makeSearchCommandArguments
    public function makeSearchCommandArguments(string $query): array
    {
        // Escape dash characters in query
        $query = str_replace('-', '\\-', $query);

        $queryParts = array_merge([$query], $this->tagFilters, $this->numericFilters, $this->geoFilters);
        // This line here is the the reason for KifiBuilder and KifiIndex.
        // The extra quotes '', that Builder adds, is not compatible with
        // RediSearch 1.x. See original line here: vendor/ethanhann/redisearch-php/src/Query/Builder.php#L192
        $queryWithFilters = trim(implode(' ', $queryParts));

        return array_filter(
            array_merge(
                trim($queryWithFilters) === '' ? [$this->indexName] : [$this->indexName, $queryWithFilters],
                $this->explodeArgument($this->limit),
                $this->explodeArgument($this->slop),
                [
                    $this->verbatim,
                    $this->withScores,
                    $this->withPayloads,
                    $this->noStopWords,
                    $this->noContent,
                ],
                $this->explodeArgument($this->inFields),
                $this->explodeArgument($this->inKeys),
                $this->explodeArgument($this->return),
                $this->explodeArgument($this->summarize),
                $this->explodeArgument($this->highlight),
                $this->explodeArgument($this->sortBy),
                $this->explodeArgument($this->scorer),
                $this->explodeArgument($this->language),
                $this->explodeArgument($this->expander),
                $this->explodeArgument($this->payload),
            ),
            function ($item) {
                return !is_null($item) && $item !== '';
            }
        );
    }
}