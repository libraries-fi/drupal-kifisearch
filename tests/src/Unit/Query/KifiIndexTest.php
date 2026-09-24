<?php

namespace Drupal\Tests\kifisearch\Unit\Query;

use Drupal\kifisearch\Query\KifiIndex;
use Drupal\Tests\UnitTestCase;
use Ehann\RediSearch\Exceptions\RediSearchException;
use Ehann\RedisRaw\RedisRawClientInterface;

/**
 * Tests the custom RediSearch index behavior.
 *
 * @coversDefaultClass \Drupal\kifisearch\Query\KifiIndex
 *
 * @group kifisearch
 */
class KifiIndexTest extends UnitTestCase {

  /**
   * Tests responses that identify a missing index.
   *
   * @param string $response
   *   The response from Redis Search.
   *
   * @dataProvider missingIndexResponseProvider
   * @covers ::exists
   */
  public function testMissingIndexResponses(string $response): void {
    $redis = $this->createMock(RedisRawClientInterface::class);
    $redis->expects($this->once())
      ->method('rawCommand')
      ->with('FT.INFO', ['kirjastot_fi'])
      ->willReturn($response);

    $index = new KifiIndex($redis, 'kirjastot_fi');

    $this->assertFalse($index->exists());
  }

  /**
   * Provides missing-index responses used by supported Redis Search versions.
   *
   * @return array<string, array{string}>
   *   Test cases keyed by the response variant.
   */
  public static function missingIndexResponseProvider(): array {
    return [
      'legacy response' => ['unknown index name'],
      'current response' => ['kirjastot_fi: no such index'],
      'unprefixed current response' => ['no such index'],
      'case and whitespace normalization' => ["  KIRJASTOT_FI: NO SUCH INDEX\n"],
    ];
  }

  /**
   * Ensures unrelated RediSearch errors are not treated as a missing index.
   *
   * @covers ::exists
   */
  public function testUnrelatedErrorIsRethrown(): void {
    $redis = $this->createMock(RedisRawClientInterface::class);
    $redis->method('rawCommand')
      ->willReturn('permission denied');

    $index = new KifiIndex($redis, 'kirjastot_fi');

    $this->expectException(RediSearchException::class);
    $this->expectExceptionMessage('permission denied');
    $index->exists();
  }

}
