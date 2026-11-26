<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\marketplace_auction\Entity\Auction;
use Drupal\marketplace_auction\Entity\AuctionSettlement;
use Drupal\marketplace_auction\Entity\Bid;

/**
 * Records finalized auction outcomes.
 */
final class AuctionSettlementManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Retrieves an existing settlement for an auction.
   */
  public function getSettlement(
    int $auctionId,
  ): ?AuctionSettlement {
    $storage = $this->entityTypeManager
      ->getStorage('marketplace_auction_settlement');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('auction_id', $auctionId)
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      return NULL;
    }

    $settlement = $storage->load(reset($ids));

    return $settlement instanceof AuctionSettlement
      ? $settlement
      : NULL;
  }

  /**
   * Settles an expired auction.
   *
   * The caller must hold the auction row lock within a transaction.
   */
  public function settle(
    Auction $auction,
    int $now,
  ): AuctionSettlement {
    $auctionId = (int) $auction->id();

    if ($auctionId <= 0) {
      throw new \DomainException(
        'Cannot settle an unsaved auction.'
      );
    }

    $existing = $this->getSettlement($auctionId);

    if ($existing !== NULL) {
      return $existing;
    }

    $status = $auction->get('status')->value;

    if (!in_array($status, ['open', 'scheduled'], TRUE)) {
      throw new \DomainException(
        'Auction is not eligible for settlement.'
      );
    }

    if ((int) $auction->get('ends_at')->value > $now) {
      throw new \DomainException(
        'Auction has not reached its closing time.'
      );
    }

    $seller = $auction->getSellerProfile();

    if ($seller === NULL) {
      throw new \DomainException(
        'Auction has no seller profile.'
      );
    }

    $bidCount = (int) $auction->get('bid_count')->value;
    $currentPrice = (int) $auction->get('current_price')->value;
    $leadingBid = $auction->get('leading_bid_id')->entity;

    $values = [
      'auction_id' => $auctionId,
      'seller_profile_id' => $seller->id(),
      'currency' => $auction->get('currency')->value,
      'settled_at' => $now,
      'outcome' => AuctionSettlement::OUTCOME_UNSOLD,
      'final_price' => 0,
    ];

    if ($bidCount > 0) {
      if (!$leadingBid instanceof Bid) {
        throw new \DomainException(
          'Auction has bids but no valid leading bid.'
        );
      }

      $winnerId = (int) $leadingBid
        ->get('bidder_id')->target_id;

      $winningAmount = (int) $leadingBid
        ->get('amount')->value;

      if (
        (int) $leadingBid->get('auction_id')->target_id !== $auctionId
        || $winnerId <= 0
        || $winnerId === $auction->getSellerId()
        || $winningAmount !== $currentPrice
        || $winningAmount < (int) $auction->get('starting_price')->value
      ) {
        throw new \DomainException(
          'Auction winning bid is inconsistent.'
        );
      }

      $values['winner_id'] = $winnerId;
      $values['winning_bid_id'] = $leadingBid->id();
      $values['final_price'] = $winningAmount;
      $values['outcome'] = AuctionSettlement::OUTCOME_SOLD;
    }
    elseif (
      $bidCount < 0
      || $currentPrice !== 0
      || !$auction->get('leading_bid_id')->isEmpty()
    ) {
      throw new \DomainException(
        'Auction has inconsistent bidding state.'
      );
    }

    $settlement = $this->entityTypeManager
      ->getStorage('marketplace_auction_settlement')
      ->create($values);

    $violations = $settlement->validate();

    if ($violations->count() > 0) {
      throw new \DomainException((string) $violations);
    }

    $settlement->save();

    return $settlement;
  }

}
