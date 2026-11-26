<?php

declare(strict_types=1);

namespace Drupal\Tests\marketplace_auction\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\marketplace_auction\Service\ProxyBidRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests private proxy bid persistence.
 */
#[Group('marketplace_auction')]
#[RunTestsInSeparateProcesses]
final class ProxyBidRepositoryTest extends KernelTestBase {

  protected static $modules = ['system'];

  private ProxyBidRepository $repository;

  protected function setUp(): void {
    parent::setUp();

    require_once DRUPAL_ROOT
      . '/modules/custom/marketplace_auction/marketplace_auction.install';

    $definition = marketplace_auction_schema();

    $database = $this->container->get('database');

    $database->schema()->createTable(
      'marketplace_proxy_bid',
      $definition['marketplace_proxy_bid'],
    );

    $this->repository = new ProxyBidRepository($database);
  }

  public function testEmptyAuction(): void {
    $this->assertSame([], $this->repository->getOffers(1));

    $this->assertNull(
      $this->repository->getOffer(1, 2),
    );
  }

  public function testStoreMaximum(): void {
    $this->repository->saveMaximum(1, 2, 10000, 1000);

    $this->assertSame([
      'bidder_id' => 2,
      'maximum' => 10000,
      'sequence' => 1,
    ], $this->repository->getOffer(1, 2));
  }

  public function testMultipleBidders(): void {
    $this->repository->saveMaximum(1, 2, 10000, 1000);
    $this->repository->saveMaximum(1, 3, 6000, 1001);

    $this->assertCount(
      2,
      $this->repository->getOffers(1),
    );

    $this->assertSame(
      2,
      $this->repository->getOffer(1, 3)['sequence'],
    );
  }

  public function testIncreaseMaximum(): void {
    $this->repository->saveMaximum(1, 2, 10000, 1000);
    $this->repository->saveMaximum(1, 3, 6000, 1001);
    $this->repository->saveMaximum(1, 2, 15000, 1002);

    $this->assertCount(
      2,
      $this->repository->getOffers(1),
    );

    $this->assertSame([
      'bidder_id' => 2,
      'maximum' => 15000,
      'sequence' => 3,
    ], $this->repository->getOffer(1, 2));
  }

  public function testDecreaseRejected(): void {
    $this->repository->saveMaximum(1, 2, 10000, 1000);

    $this->expectException(\InvalidArgumentException::class);

    $this->repository->saveMaximum(1, 2, 9000, 1001);
  }

  public function testEqualMaximumRejected(): void {
    $this->repository->saveMaximum(1, 2, 10000, 1000);

    $this->expectException(\InvalidArgumentException::class);

    $this->repository->saveMaximum(1, 2, 10000, 1001);
  }

  public function testAuctionIsolation(): void {
    $this->repository->saveMaximum(1, 2, 10000, 1000);
    $this->repository->saveMaximum(2, 2, 5000, 1001);

    $this->assertSame(
      10000,
      $this->repository->getOffer(1, 2)['maximum'],
    );

    $this->assertSame(
      5000,
      $this->repository->getOffer(2, 2)['maximum'],
    );

    $this->assertSame(
      1,
      $this->repository->getOffer(2, 2)['sequence'],
    );

    $this->assertCount(
      1,
      $this->repository->getOffers(1),
    );
  }

}
