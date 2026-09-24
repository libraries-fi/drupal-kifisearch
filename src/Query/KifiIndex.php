<?php

namespace Drupal\kifisearch\Query;

use Ehann\RediSearch\Exceptions\RediSearchException;
use Ehann\RediSearch\Exceptions\UnknownIndexNameException;
use Ehann\RediSearch\Index;

/**
 * Provides a RediSearch index with Kifi-specific behavior.
 */
class KifiIndex extends Index {

  /**
   * Determines whether the index exists.
   *
   * @return bool
   *   TRUE when the index exists, FALSE otherwise.
   */
  public function exists(): bool {
    try {
      $this->info();
      return TRUE;
    }
    catch (UnknownIndexNameException) {
      return FALSE;
    }
    catch (RediSearchException $exception) {
      // Redis Search 8 returns "<index name>: no such index".
      $message = strtolower(trim($exception->getMessage()));
      if ($message === 'no such index' || str_ends_with($message, ': no such index')) {
        return FALSE;
      }

      throw $exception;
    }
  }

  /**
   * Creates the Kifi-specific query builder.
   *
   * @return \Drupal\kifisearch\Query\KifiBuilder
   *   The query builder.
   */
  protected function makeQueryBuilder(): KifiBuilder {
    return (new KifiBuilder($this->redisClient, $this->getIndexName()));
  }

}