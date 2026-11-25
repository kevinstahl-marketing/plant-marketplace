<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;

/**
 * Provides anonymized public auction bid history.
 */
final class BidHistory {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Returns recent public bids, newest first.
   */
  public function getHistory(
    int $auctionId,
    int $viewerId = 0,
    int $limit = 50,
  ): array {

    if ($auctionId <= 0) {
      return [];
    }

    $limit = max(1, min($limit, 100));

    $records = $this->database
      ->select('marketplace_bid', 'b')
      ->fields('b', [
        'id',
        'bidder_id',
        'amount',
        'created',
      ])
      ->condition('auction_id', $auctionId)
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute();

    $history = [];

    foreach ($records as $record) {
      $bidderId = (int) $record->bidder_id;

      $history[] = [
        'bid_id' => (int) $record->id,
        'bidder' => $this->getAlias($auctionId, $bidderId),
        'amount' => (int) $record->amount,
        'created' => (int) $record->created,
        'is_own_bid' => $viewerId > 0
          && $viewerId === $bidderId,
      ];
    }

    return $history;
  }

  /**
   * Generates a stable, auction-specific bidder alias.
   */
  private function getAlias(
    int $auctionId,
    int $bidderId,
  ): string {

    $hash = hash_hmac(
      'sha256',
      $auctionId . ':' . $bidderId,
      Settings::getHashSalt(),
    );

    return 'Bidder ' . strtoupper(substr($hash, 0, 10));
  }

}
