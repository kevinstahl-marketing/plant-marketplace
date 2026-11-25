<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\marketplace_auction\Entity\Auction;

/**
 * Manages automatic auction status transitions.
 */
final class AuctionLifecycle {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Processes auctions whose scheduled transitions are due.
   */
  public function processDueAuctions(int $limit = 100): array {

    $storage = $this->entityTypeManager
      ->getStorage('marketplace_auction');

    $now = $this->time->getCurrentTime();

    $closing = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', [
        Auction::STATUS_OPEN,
        Auction::STATUS_SCHEDULED,
      ], 'IN')
      ->condition('ends_at', $now, '<=')
      ->sort('ends_at', 'ASC')
      ->range(0, $limit)
      ->execute();

    $opening = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', Auction::STATUS_SCHEDULED)
      ->condition('starts_at', $now, '<=')
      ->condition('ends_at', $now, '>')
      ->sort('starts_at', 'ASC')
      ->range(0, $limit)
      ->execute();

    $results = [
      'opened' => 0,
      'closed' => 0,
      'unchanged' => 0,
    ];

    foreach (array_unique(array_merge(
      array_values($closing),
      array_values($opening)
    )) as $id) {
      $result = $this->processAuction((int) $id);
      $results[$result]++;
    }

    return $results;
  }

  /**
   * Safely processes one auction.
   */
  public function processAuction(int $auctionId): string {

    $storage = $this->entityTypeManager
      ->getStorage('marketplace_auction');

    $transaction = $this->database->startTransaction();

    try {
      // Use the same row lock as BidManager.
      $exists = $this->database
        ->select('marketplace_auction', 'a')
        ->fields('a', ['id'])
        ->condition('id', $auctionId)
        ->forUpdate()
        ->execute()
        ->fetchField();

      if (!$exists) {
        unset($transaction);
        return 'unchanged';
      }

      $storage->resetCache([$auctionId]);
      $auction = $storage->load($auctionId);

      if (!$auction instanceof Auction) {
        unset($transaction);
        return 'unchanged';
      }

      $now = $this->time->getCurrentTime();
      $status = $auction->get('status')->value;
      $starts = (int) $auction->get('starts_at')->value;
      $ends = (int) $auction->get('ends_at')->value;

      $result = 'unchanged';

      if (in_array($status, [
        Auction::STATUS_OPEN,
        Auction::STATUS_SCHEDULED,
      ], TRUE) && $now >= $ends) {

        $auction->set('status', Auction::STATUS_CLOSED);
        $auction->save();

        $result = 'closed';
      }
      elseif (
        $status === Auction::STATUS_SCHEDULED
        && $now >= $starts
        && $now < $ends
      ) {
        $auction->set('status', Auction::STATUS_OPEN);
        $auction->save();

        $result = 'opened';
      }

      unset($transaction);

      return $result;
    }
    catch (\Throwable $exception) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }

      $storage->resetCache([$auctionId]);

      throw $exception;
    }
  }

}
