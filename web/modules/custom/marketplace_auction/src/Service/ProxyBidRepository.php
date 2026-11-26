<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Service;

use Drupal\Core\Database\Connection;

/**
 * Provides internal storage for private proxy bid maximums.
 *
 * All writes must occur within a transaction that holds
 * the corresponding auction row lock.
 */
final class ProxyBidRepository {

  private const TABLE = 'marketplace_proxy_bid';

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Retrieves all effective maximums for an auction.
   */
  public function getOffers(int $auctionId): array {
    $records = $this->database
      ->select(self::TABLE, 'p')
      ->fields('p', [
        'bidder_id',
        'maximum',
        'sequence',
      ])
      ->condition('auction_id', $auctionId)
      ->execute();

    $offers = [];

    foreach ($records as $record) {
      $offers[] = [
        'bidder_id' => (int) $record->bidder_id,
        'maximum' => (int) $record->maximum,
        'sequence' => (int) $record->sequence,
      ];
    }

    return $offers;
  }

  /**
   * Retrieves a bidder's existing maximum.
   */
  public function getOffer(
    int $auctionId,
    int $bidderId,
  ): ?array {
    $record = $this->database
      ->select(self::TABLE, 'p')
      ->fields('p', [
        'bidder_id',
        'maximum',
        'sequence',
      ])
      ->condition('auction_id', $auctionId)
      ->condition('bidder_id', $bidderId)
      ->execute()
      ->fetchAssoc();

    if (!$record) {
      return NULL;
    }

    return [
      'bidder_id' => (int) $record['bidder_id'],
      'maximum' => (int) $record['maximum'],
      'sequence' => (int) $record['sequence'],
    ];
  }

  /**
   * Stores a new or increased private maximum.
   *
   * The caller must hold the auction row lock.
   */
  public function saveMaximum(
    int $auctionId,
    int $bidderId,
    int $maximum,
    int $now,
  ): void {

    if (
      $auctionId < 1
      || $bidderId < 1
      || $maximum < 1
      || $maximum > 2147483647
    ) {
      throw new \InvalidArgumentException(
        'Invalid proxy bid values.'
      );
    }

    $existing = $this->getOffer($auctionId, $bidderId);

    if ($existing && $maximum <= $existing['maximum']) {
      throw new \InvalidArgumentException(
        'A new maximum must exceed the existing maximum.'
      );
    }

    $query = $this->database
      ->select(self::TABLE, 'p');

    $query->addExpression('MAX(p.sequence)', 'last_sequence');
    $query->condition('auction_id', $auctionId);

    $sequence = (int) $query->execute()->fetchField() + 1;

    $fields = [
      'maximum' => $maximum,
      'sequence' => $sequence,
      'changed' => $now,
    ];

    if ($existing !== NULL) {
      $this->database
        ->update(self::TABLE)
        ->fields($fields)
        ->condition('auction_id', $auctionId)
        ->condition('bidder_id', $bidderId)
        ->execute();
    }
    else {
      $this->database
        ->insert(self::TABLE)
        ->fields($fields + [
          'auction_id' => $auctionId,
          'bidder_id' => $bidderId,
          'created' => $now,
        ])
        ->execute();
    }
  }

}
