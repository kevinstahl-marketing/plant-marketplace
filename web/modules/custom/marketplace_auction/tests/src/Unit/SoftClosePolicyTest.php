<?php

declare(strict_types=1);

namespace Drupal\Tests\marketplace_auction\Unit;

use Drupal\marketplace_auction\Service\SoftClosePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('marketplace_auction')]
final class SoftClosePolicyTest extends TestCase {

  #[DataProvider('scenarios')]
  public function testDeadline(
    int $endsAt,
    int $now,
    int $window,
    int $extension,
    int $expected,
  ): void {
    $policy = new SoftClosePolicy();

    $this->assertSame(
      $expected,
      $policy->resolve($endsAt, $now, $window, $extension),
    );
  }

  public static function scenarios(): array {
    return [
      'outside window' => [1000, 800, 120, 120, 1000],
      'inside window' => [1000, 950, 120, 120, 1070],
      'exact boundary' => [1000, 880, 120, 120, 1000],
      'one second left' => [1000, 999, 120, 120, 1119],
      'already expired' => [1000, 1001, 120, 120, 1000],
      'exact expiration' => [1000, 1000, 120, 120, 1000],
      'disabled window' => [1000, 950, 0, 120, 1000],
      'disabled extension' => [1000, 950, 120, 0, 1000],
      'never shorten' => [1000, 900, 120, 30, 1000],
      'repeated extension' => [1070, 1060, 120, 120, 1180],
    ];
  }

}
