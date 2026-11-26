<?php

declare(strict_types=1);

namespace Drupal\Tests\marketplace_auction\Unit;

use Drupal\marketplace_auction\Service\ProxyBidResolver;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the proxy bidding pricing algorithm.
 */
#[Group('marketplace_auction')]
final class ProxyBidResolverTest extends TestCase {

  #[DataProvider('pricingScenarios')]
  public function testPricing(
    array $offers,
    ?array $expected,
  ): void {
    $resolver = new ProxyBidResolver();

    $this->assertSame(
      $expected,
      $resolver->resolve(2500, 100, $offers),
    );
  }

  public static function pricingScenarios(): array {
    $a = [
      'bidder_id' => 2,
      'maximum' => 10000,
      'sequence' => 1,
    ];

    $b = [
      'bidder_id' => 3,
      'maximum' => 6000,
      'sequence' => 2,
    ];

    return [
      'no bidders' => [
        [],
        NULL,
      ],

      'single bidder' => [
        [$a],
        ['bidder_id' => 2, 'price' => 2500],
      ],

      'automatic counterbid' => [
        [$a, $b],
        ['bidder_id' => 2, 'price' => 6100],
      ],

      'input order does not matter' => [
        [$b, $a],
        ['bidder_id' => 2, 'price' => 6100],
      ],

      'maximum caps public price' => [
        [$a, array_replace($b, ['maximum' => 9950])],
        ['bidder_id' => 2, 'price' => 10000],
      ],

      'earlier maximum wins tie' => [
        [array_replace($b, ['maximum' => 10000]), $a],
        ['bidder_id' => 2, 'price' => 10000],
      ],

      'new bidder takes lead' => [
        [$a, array_replace($b, ['maximum' => 15000])],
        ['bidder_id' => 3, 'price' => 10100],
      ],

      'three competing bidders' => [
        [
          $a,
          $b,
          [
            'bidder_id' => 4,
            'maximum' => 9000,
            'sequence' => 3,
          ],
        ],
        ['bidder_id' => 2, 'price' => 9100],
      ],
    ];
  }

  public function testDuplicateBidderRejected(): void {
    $resolver = new ProxyBidResolver();

    $this->expectException(\InvalidArgumentException::class);

    $resolver->resolve(2500, 100, [
      [
        'bidder_id' => 2,
        'maximum' => 10000,
        'sequence' => 1,
      ],
      [
        'bidder_id' => 2,
        'maximum' => 15000,
        'sequence' => 2,
      ],
    ]);
  }

  public function testInvalidMaximumRejected(): void {
    $resolver = new ProxyBidResolver();

    $this->expectException(\InvalidArgumentException::class);

    $resolver->resolve(2500, 100, [
      [
        'bidder_id' => 2,
        'maximum' => 2400,
        'sequence' => 1,
      ],
    ]);
  }

}
