<?php

declare(strict_types=1);

namespace Drupal\Tests\marketplace_auction\Kernel;

use Drupal\Component\Datetime\TimeInterface;
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

  private int $clockTime;

  private Auction $auction;
  private User $sellerAccount;
  private User $bidderA;
  private User $bidderB;

  protected function setUp(): void {
    parent::setUp();

    // Replace the real clock with a controllable test clock.
    $this->clockTime = time();

    $clock = $this->createMock(TimeInterface::class);

    $clock->method('getCurrentTime')
      ->willReturnCallback(fn (): int => $this->clockTime);

    $clock->method('getRequestTime')
      ->willReturnCallback(fn (): int => $this->clockTime);

    $clock->method('getCurrentMicroTime')
      ->willReturnCallback(fn (): float => (float) $this->clockTime);

    $clock->method('getRequestMicroTime')
      ->willReturnCallback(fn (): float => (float) $this->clockTime);

    $this->container->set('datetime.time', $clock);


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
      'marketplace_auction_settlement',
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



  /**
   * Configures the test auction's closing policy.
   */
  private function configureSoftClose(
    int $remaining,
    int $window = 120,
    int $extension = 120,
  ): int {
    $now = \Drupal::time()->getCurrentTime();
    $ends = $now + $remaining;

    $auction = $this->reloadAuction();

    $auction->set('starts_at', $now - 600);
    $auction->set('ends_at', $ends);
    $auction->set('original_ends_at', $ends);
    $auction->set('soft_close_window', $window);
    $auction->set('soft_close_extension', $extension);
    $auction->set('extension_count', 0);
    $auction->save();

    return $ends;
  }

  public function testSoftCloseExtendsDeadline(): void {
    $original = $this->configureSoftClose(60);

    $this->placeBid($this->bidderA, 2500);

    $auction = $this->reloadAuction();

    $this->assertGreaterThan(
      $original,
      (int) $auction->get('ends_at')->value,
    );

    $this->assertSame(
      $original,
      (int) $auction->get('original_ends_at')->value,
    );

    $this->assertSame(
      1,
      (int) $auction->get('extension_count')->value,
    );
  }

  public function testSoftCloseOutsideWindow(): void {
    $original = $this->configureSoftClose(600);

    $this->placeBid($this->bidderA, 2500);

    $auction = $this->reloadAuction();

    $this->assertSame(
      $original,
      (int) $auction->get('ends_at')->value,
    );

    $this->assertSame(
      0,
      (int) $auction->get('extension_count')->value,
    );
  }

  public function testPrivateMaximumDoesNotExtendDeadline(): void {
    $this->enableProxyBidding();
    $original = $this->configureSoftClose(600);

    $this->placeBid($this->bidderA, 10000);

    // Advance time without modifying the committed auction.
    $this->clockTime += 540;

    $this->placeBid($this->bidderA, 15000);

    $auction = $this->reloadAuction();

    $this->assertSame(
      $original,
      (int) $auction->get('ends_at')->value,
    );

    $this->assertSame(
      0,
      (int) $auction->get('extension_count')->value,
    );

    $this->assertSame(
      2500,
      (int) $auction->get('current_price')->value,
    );
  }

  public function testDisabledSoftClose(): void {
    $original = $this->configureSoftClose(60, 0, 0);

    $this->placeBid($this->bidderA, 2500);

    $auction = $this->reloadAuction();

    $this->assertSame(
      $original,
      (int) $auction->get('ends_at')->value,
    );

    $this->assertSame(
      0,
      (int) $auction->get('extension_count')->value,
    );
  }

  public function testExpiredAuctionCannotBeExtended(): void {
    $original = $this->configureSoftClose(-10);

    try {
      $this->placeBid($this->bidderA, 2500);
      $this->fail('An expired auction accepted a bid.');
    }
    catch (\Drupal\marketplace_auction\Exception\BidRejectedException $e) {
      $auction = $this->reloadAuction();

      $this->assertSame(
        $original,
        (int) $auction->get('ends_at')->value,
      );

      $this->assertSame(
        0,
        (int) $auction->get('bid_count')->value,
      );

      $this->assertSame(
        0,
        (int) $auction->get('extension_count')->value,
      );
    }
  }


  public function testCommittedTermsAreImmutable(): void {
    $this->configureSoftClose(600);
    $this->placeBid($this->bidderA, 2500);

    $changes = [
      'title' => 'Different plant',
      'description' => [
        'value' => 'Changed specimen',
        'format' => 'plain_text',
      ],
      'images' => [['target_id' => 999]],
      'seller_id' => $this->bidderB->id(),
      'seller_profile_id' => 999,
      'starting_price' => 100,
      'bid_increment' => 500,
      'bidding_mode' => Auction::MODE_PROXY,
      'currency' => 'EUR',
      'starts_at' => $this->clockTime - 1200,
      'original_ends_at' => $this->clockTime + 900,
      'soft_close_window' => 300,
      'soft_close_extension' => 300,
    ];

    foreach ($changes as $field => $value) {
      $auction = $this->reloadAuction();
      $before = $auction->get($field)->getValue();

      $auction->set($field, $value);

      try {
        $auction->save();
        $this->fail("Protected field $field was modified.");
      }
      catch (\Drupal\Core\Entity\EntityStorageException $e) {
        $this->assertStringContainsString(
          'cannot change after bidding begins',
          $e->getMessage(),
        );
      }

      $this->assertSame(
        $before,
        $this->reloadAuction()->get($field)->getValue(),
      );
    }
  }

  public function testDeadlineCannotBeShortened(): void {
    $original = $this->configureSoftClose(600);

    $this->placeBid($this->bidderA, 2500);

    $auction = $this->reloadAuction();
    $auction->set('ends_at', $original - 100);

    $this->expectException(
      \Drupal\Core\Entity\EntityStorageException::class,
    );

    $this->expectExceptionMessage(
      'deadline cannot be shortened',
    );

    $auction->save();
  }

  public function testCurrentPriceCannotDecrease(): void {
    $this->placeBid($this->bidderA, 2500);

    $auction = $this->reloadAuction();
    $auction->set('current_price', 100);

    $this->expectException(
      \Drupal\Core\Entity\EntityStorageException::class,
    );

    $this->expectExceptionMessage(
      'current auction price cannot decrease',
    );

    $auction->save();
  }

  public function testBidCountCannotDecrease(): void {
    $this->placeBid($this->bidderA, 2500);

    $auction = $this->reloadAuction();
    $auction->set('bid_count', 0);

    $this->expectException(
      \Drupal\Core\Entity\EntityStorageException::class,
    );

    $this->expectExceptionMessage(
      'bid count cannot decrease',
    );

    $auction->save();
  }

  public function testTermsEditableBeforeBidding(): void {
    $auction = $this->reloadAuction();

    $auction->set('title', 'Updated Monstera');
    $auction->set('starting_price', 3000);
    $auction->save();

    $auction = $this->reloadAuction();

    $this->assertSame('Updated Monstera', $auction->label());
    $this->assertSame(
      3000,
      (int) $auction->get('starting_price')->value,
    );
  }


  /**
   * Processes the current test auction.
   */
  private function processLifecycle(): string {
    return \Drupal::service('marketplace_auction.lifecycle')
      ->processAuction((int) $this->auction->id());
  }

  /**
   * Retrieves the current auction's settlement.
   */
  private function getSettlement(): ?\Drupal\marketplace_auction\Entity\AuctionSettlement {
    return \Drupal::service('marketplace_auction.settlement_manager')
      ->getSettlement((int) $this->auction->id());
  }

  public function testSoldAuctionSettlement(): void {
    $this->configureSoftClose(600);

    $this->placeBid($this->bidderA, 2500);
    $this->placeBid($this->bidderB, 3000);

    $this->clockTime += 600;

    $this->assertSame('closed', $this->processLifecycle());

    $settlement = $this->getSettlement();

    $this->assertNotNull($settlement);
    $this->assertTrue($settlement->isSold());

    $this->assertSame(
      (int) $this->bidderB->id(),
      (int) $settlement->get('winner_id')->target_id,
    );

    $this->assertSame(
      3000,
      (int) $settlement->get('final_price')->value,
    );

    $auction = $this->reloadAuction();

    $this->assertSame(
      Auction::STATUS_CLOSED,
      $auction->get('status')->value,
    );

    $this->assertSame(
      (int) $auction->get('leading_bid_id')->target_id,
      (int) $settlement->get('winning_bid_id')->target_id,
    );
  }

  public function testUnsoldAuctionSettlement(): void {
    $this->configureSoftClose(600);

    $this->clockTime += 600;

    $this->assertSame('closed', $this->processLifecycle());

    $settlement = $this->getSettlement();

    $this->assertNotNull($settlement);
    $this->assertFalse($settlement->isSold());

    $this->assertSame(
      'unsold',
      $settlement->get('outcome')->value,
    );

    $this->assertTrue(
      $settlement->get('winner_id')->isEmpty(),
    );

    $this->assertSame(
      0,
      (int) $settlement->get('final_price')->value,
    );
  }

  public function testSettlementIsIdempotent(): void {
    $this->configureSoftClose(600);
    $this->placeBid($this->bidderA, 2500);

    $this->clockTime += 600;

    $this->assertSame('closed', $this->processLifecycle());

    $firstId = $this->getSettlement()->id();

    $this->assertSame('unchanged', $this->processLifecycle());
    $this->assertSame('unchanged', $this->processLifecycle());

    $this->assertSame(
      $firstId,
      $this->getSettlement()->id(),
    );

    $count = \Drupal::entityQuery('marketplace_auction_settlement')
      ->accessCheck(FALSE)
      ->condition('auction_id', $this->auction->id())
      ->count()
      ->execute();

    $this->assertSame(1, (int) $count);
  }

  public function testProxySettlementUsesPublicPrice(): void {
    $this->enableProxyBidding();
    $this->configureSoftClose(600);

    $this->placeBid($this->bidderA, 10000);
    $this->placeBid($this->bidderB, 6000);

    $this->clockTime += 600;

    $this->assertSame('closed', $this->processLifecycle());

    $settlement = $this->getSettlement();

    $this->assertTrue($settlement->isSold());

    $this->assertSame(
      (int) $this->bidderA->id(),
      (int) $settlement->get('winner_id')->target_id,
    );

    // Charge the public winning price, not the private maximum.
    $this->assertSame(
      6100,
      (int) $settlement->get('final_price')->value,
    );
  }

  public function testSettlementRespectsExtendedDeadline(): void {
    $this->configureSoftClose(60);

    $this->placeBid($this->bidderA, 2500);

    // Original deadline has arrived, but soft close extended it.
    $this->clockTime += 60;

    $this->assertSame('unchanged', $this->processLifecycle());
    $this->assertNull($this->getSettlement());

    // Effective closing time has now arrived.
    $this->clockTime += 60;

    $this->assertSame('closed', $this->processLifecycle());
    $this->assertNotNull($this->getSettlement());
  }

  public function testSettlementCannotBeModified(): void {
    $this->configureSoftClose(600);
    $this->placeBid($this->bidderA, 2500);

    $this->clockTime += 600;

    $this->assertSame('closed', $this->processLifecycle());

    $settlement = $this->getSettlement();

    $settlement->set('final_price', 100);

    $this->expectException(
      \Drupal\Core\Entity\EntityStorageException::class,
    );

    $this->expectExceptionMessage(
      'settlement records cannot be modified',
    );

    $settlement->save();
  }

  public function testSettlementDatabaseUniqueness(): void {
    $this->configureSoftClose(600);
    $this->placeBid($this->bidderA, 2500);

    $this->clockTime += 600;

    $this->assertSame('closed', $this->processLifecycle());

    $duplicate = $this->getSettlement()->createDuplicate();

    $this->expectException(
      \Drupal\Core\Entity\EntityStorageException::class,
    );

    $duplicate->save();
  }

}
