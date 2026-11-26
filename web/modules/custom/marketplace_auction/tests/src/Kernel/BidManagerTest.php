<?php

declare(strict_types=1);

namespace Drupal\Tests\marketplace_auction\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\marketplace_auction\Entity\Auction;
use Drupal\marketplace_auction\Exception\BidRejectedException;
use Drupal\marketplace_seller\Entity\Seller;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the auction bidding engine.
 */
#[Group('marketplace_auction')]
#[RunTestsInSeparateProcesses]
final class BidManagerTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'options',
    'datetime',
    'file',
    'image',
    'media',
    'views',
    'marketplace_seller',
    'marketplace_auction',
  ];

  private Auction $auction;
  private User $sellerAccount;
  private User $bidderA;
  private User $bidderB;

  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('user', ['users_data']);
    $this->installSchema('file', ['file_usage']);

    $this->installSchema(
      'marketplace_auction',
      ['marketplace_proxy_bid'],
    );

    foreach ([
      'user',
      'file',
      'media',
      'marketplace_seller',
      'marketplace_auction',
      'marketplace_bid',
    ] as $entityType) {
      $this->installEntitySchema($entityType);
    }

    $this->installConfig(['system', 'user', 'filter']);

    User::create([
      'name' => 'test_admin',
      'status' => 1,
    ])->save();

    $role = Role::create([
      'id' => 'test_bidder',
      'label' => 'Test bidder',
    ]);

    $role->grantPermission('place marketplace bids');
    $role->save();

    $this->sellerAccount = $this->createAccount('seller');
    $this->bidderA = $this->createAccount('bidder_a');
    $this->bidderB = $this->createAccount('bidder_b');

    $seller = Seller::create([
      'user_id' => $this->sellerAccount->id(),
      'name' => 'Test Nursery',
      'status' => Seller::STATUS_ACTIVE,
    ]);

    $seller->save();

    $now = \Drupal::time()->getCurrentTime();

    $this->auction = Auction::create([
      'title' => 'Test Monstera',
      'seller_id' => $this->sellerAccount->id(),
      'seller_profile_id' => $seller->id(),
      'status' => Auction::STATUS_OPEN,
      'currency' => 'USD',
      'starting_price' => 2500,
      'bid_increment' => 100,
      'current_price' => 0,
      'bid_count' => 0,
      'starts_at' => $now - 60,
      'ends_at' => $now + 3600,
    ]);

    $this->auction->save();
  }

  private function createAccount(string $name): User {
    $user = User::create([
      'name' => $name,
      'status' => 1,
      'roles' => ['test_bidder'],
    ]);

    $user->save();

    return $user;
  }

  private function placeBid(User $bidder, int $amount): void {
    \Drupal::service('marketplace_auction.bid_manager')
      ->placeBid(
        (int) $this->auction->id(),
        (int) $bidder->id(),
        $amount,
      );
  }

  private function reloadAuction(): Auction {
    $storage = \Drupal::entityTypeManager()
      ->getStorage('marketplace_auction');

    $storage->resetCache([$this->auction->id()]);

    return $storage->load($this->auction->id());
  }

  public function testValidBidding(): void {
    $this->placeBid($this->bidderA, 2500);
    $this->placeBid($this->bidderB, 2600);

    $auction = $this->reloadAuction();

    $this->assertSame(
      2600,
      (int) $auction->get('current_price')->value,
    );

    $this->assertSame(
      2,
      (int) $auction->get('bid_count')->value,
    );

    $this->assertSame(2700, $auction->getMinimumBid());

    $leadingBid = $auction->get('leading_bid_id')->entity;

    $this->assertSame(
      (int) $this->bidderB->id(),
      (int) $leadingBid->get('bidder_id')->target_id,
    );
  }

  public function testBelowStartingPrice(): void {
    $this->expectException(BidRejectedException::class);
    $this->placeBid($this->bidderA, 2400);
  }

  public function testSellerCannotBid(): void {
    $this->expectException(BidRejectedException::class);
    $this->placeBid($this->sellerAccount, 2500);
  }

  public function testRejectedBidPreservesState(): void {
    $this->placeBid($this->bidderA, 2500);

    try {
      $this->placeBid($this->bidderB, 2550);
      $this->fail('Invalid bid was accepted.');
    }
    catch (BidRejectedException) {
      $auction = $this->reloadAuction();

      $this->assertSame(
        2500,
        (int) $auction->get('current_price')->value,
      );

      $this->assertSame(
        1,
        (int) $auction->get('bid_count')->value,
      );

      $count = \Drupal::entityQuery('marketplace_bid')
        ->accessCheck(FALSE)
        ->condition('auction_id', $auction->id())
        ->count()
        ->execute();

      $this->assertSame(1, (int) $count);
    }
  }


  private function enableProxyBidding(): void {
    $this->auction->set('bidding_mode', Auction::MODE_PROXY);
    $this->auction->save();
  }

  public function testProxyCompetition(): void {
    $this->enableProxyBidding();

    $this->placeBid($this->bidderA, 10000);

    $auction = $this->reloadAuction();

    $this->assertSame(
      2500,
      (int) $auction->get('current_price')->value,
    );

    $this->placeBid($this->bidderB, 6000);

    $auction = $this->reloadAuction();

    $this->assertSame(
      6100,
      (int) $auction->get('current_price')->value,
    );

    $this->assertSame(
      (int) $this->bidderA->id(),
      (int) $auction->get('leading_bid_id')->entity
        ->get('bidder_id')->target_id,
    );

    $this->placeBid($this->bidderB, 15000);

    $auction = $this->reloadAuction();

    $this->assertSame(
      10100,
      (int) $auction->get('current_price')->value,
    );

    $this->assertSame(
      (int) $this->bidderB->id(),
      (int) $auction->get('leading_bid_id')->entity
        ->get('bidder_id')->target_id,
    );

    $this->assertSame(
      3,
      (int) $auction->get('bid_count')->value,
    );
  }

  public function testPrivateMaximumIncrease(): void {
    $this->enableProxyBidding();

    $this->placeBid($this->bidderA, 10000);
    $this->placeBid($this->bidderB, 6000);

    $before = $this->reloadAuction();
    $leadingId = (int) $before->get('leading_bid_id')->target_id;

    $this->placeBid($this->bidderA, 15000);

    $auction = $this->reloadAuction();

    $this->assertSame(
      6100,
      (int) $auction->get('current_price')->value,
    );

    $this->assertSame(
      2,
      (int) $auction->get('bid_count')->value,
    );

    $this->assertSame(
      $leadingId,
      (int) $auction->get('leading_bid_id')->target_id,
    );

    $repository = \Drupal::service(
      'marketplace_auction.proxy_bid_repository'
    );

    $offer = $repository->getOffer(
      (int) $auction->id(),
      (int) $this->bidderA->id(),
    );

    $this->assertSame(15000, $offer['maximum']);
  }

  public function testProxyTie(): void {
    $this->enableProxyBidding();

    $this->placeBid($this->bidderA, 10000);
    $this->placeBid($this->bidderB, 10000);

    $auction = $this->reloadAuction();

    $this->assertSame(
      10000,
      (int) $auction->get('current_price')->value,
    );

    $this->assertSame(
      (int) $this->bidderA->id(),
      (int) $auction->get('leading_bid_id')->entity
        ->get('bidder_id')->target_id,
    );
  }

  public function testProxyMaximumCannotDecrease(): void {
    $this->enableProxyBidding();

    $this->placeBid($this->bidderA, 10000);

    $this->expectException(BidRejectedException::class);

    $this->placeBid($this->bidderA, 9000);
  }

  public function testRejectedProxyBidPreservesStorage(): void {
    $this->enableProxyBidding();

    $this->placeBid($this->bidderA, 10000);

    try {
      $this->placeBid($this->bidderB, 2550);
      $this->fail('Invalid proxy bid was accepted.');
    }
    catch (BidRejectedException) {
      $repository = \Drupal::service(
        'marketplace_auction.proxy_bid_repository'
      );

      $this->assertCount(
        1,
        $repository->getOffers((int) $this->auction->id()),
      );

      $auction = $this->reloadAuction();

      $this->assertSame(
        2500,
        (int) $auction->get('current_price')->value,
      );

      $this->assertSame(
        1,
        (int) $auction->get('bid_count')->value,
      );
    }
  }


}
