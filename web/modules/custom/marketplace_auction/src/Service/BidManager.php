<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\marketplace_auction\Entity\Auction;
use Drupal\marketplace_auction\Entity\Bid;
use Drupal\marketplace_auction\Exception\BidRejectedException;

/**
 * Handles transactional auction bidding.
 */
final class BidManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Places a bid. Amounts are expressed in integer cents.
   */
  public function placeBid(
    int $auctionId,
    int $bidderId,
    int $amount,
  ): Bid {

    if ($auctionId <= 0 || $bidderId <= 0) {
      throw new BidRejectedException('Invalid auction or bidder.');
    }

    if ($amount <= 0 || $amount > 2147483647) {
      throw new BidRejectedException('Invalid bid amount.');
    }

    $auctionStorage = $this->entityTypeManager
      ->getStorage('marketplace_auction');

    $bidStorage = $this->entityTypeManager
      ->getStorage('marketplace_bid');

    $bidder = $this->entityTypeManager
      ->getStorage('user')
      ->load($bidderId);

    if (!$bidder || !$bidder->isActive()) {
      throw new BidRejectedException('Invalid or inactive bidder.');
    }

    if (!$bidder->hasPermission('place marketplace bids')) {
      throw new BidRejectedException(
        'You do not have permission to place bids.'
      );
    }

    $transaction = $this->database->startTransaction();

    try {
      // Lock the auction row until the transaction completes.
      $exists = $this->database
        ->select('marketplace_auction', 'a')
        ->fields('a', ['id'])
        ->condition('id', $auctionId)
        ->forUpdate()
        ->execute()
        ->fetchField();

      if (!$exists) {
        throw new BidRejectedException('Auction not found.');
      }

      // Never validate a bid against a stale cached auction.
      $auctionStorage->resetCache([$auctionId]);
      $auction = $auctionStorage->load($auctionId);

      if (!$auction instanceof Auction) {
        throw new BidRejectedException('Auction not found.');
      }

      $now = $this->time->getCurrentTime();

      if (!$auction->isOpen($now)) {
        throw new BidRejectedException(
          'This auction is not accepting bids.'
        );
      }

      if (!$auction->hasEligibleSeller()) {
        throw new BidRejectedException(
          'This auction is currently unavailable.'
        );
      }

      if ($auction->getSellerId() === $bidderId) {
        throw new BidRejectedException(
          'You cannot bid on your own auction.'
        );
      }

      $minimum = $auction->getMinimumBid();

      if ($amount < $minimum) {
        throw new BidRejectedException(
          'Minimum bid is $' . number_format($minimum / 100, 2)
        );
      }

      $bid = $bidStorage->create([
        'auction_id' => $auctionId,
        'bidder_id' => $bidderId,
        'amount' => $amount,
        'created' => $now,
      ]);

      $violations = $bid->validate();

      if ($violations->count()) {
        throw new BidRejectedException((string) $violations);
      }

      $bid->save();

      $auction->set('current_price', $amount);
      $auction->set(
        'bid_count',
        (int) $auction->get('bid_count')->value + 1
      );
      $auction->set('leading_bid_id', $bid->id());

      $auction->save();

      // Commit the transaction before returning.
      unset($transaction);
    }
    catch (\Throwable $exception) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }

      $auctionStorage->resetCache([$auctionId]);
      $bidStorage->resetCache();

      throw $exception;
    }

    return $bid;
  }

}
