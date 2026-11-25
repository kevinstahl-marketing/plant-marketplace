<?php

declare(strict_types=1);

namespace Drupal\marketplace_seller_dashboard\Service;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\marketplace_seller\Entity\Seller;

/**
 * Calculates seller dashboard statistics.
 */
final class DashboardStatistics {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  public function getStatistics(Seller $seller): array {
    $now = $this->time->getCurrentTime();

    $statistics = [
      'total' => 0,
      'open' => 0,
      'scheduled' => 0,
      'closed' => 0,
      'draft' => 0,
      'cancelled' => 0,
      'bids' => 0,
      'awaiting_closure' => 0,
    ];

    $query = $this->database->select('marketplace_auction', 'a');

    $query->fields('a', ['status']);
    $query->addExpression('COUNT(*)', 'auction_count');
    $query->addExpression('COALESCE(SUM(a.bid_count), 0)', 'total_bids');

    $query->condition('a.seller_profile_id', $seller->id());
    $query->condition('a.seller_id', $seller->getUserId());
    $query->groupBy('a.status');

    foreach ($query->execute() as $row) {
      $count = (int) $row->auction_count;

      if (array_key_exists($row->status, $statistics)) {
        $statistics[$row->status] = $count;
      }

      $statistics['total'] += $count;
      $statistics['bids'] += (int) $row->total_bids;
    }

    $expired = $this->database
      ->select('marketplace_auction', 'a')
      ->condition('seller_profile_id', $seller->id())
      ->condition('seller_id', $seller->getUserId())
      ->condition('status', 'open')
      ->condition('ends_at', $now, '<=')
      ->countQuery()
      ->execute()
      ->fetchField();

    $statistics['awaiting_closure'] = (int) $expired;
    $statistics['open'] -= (int) $expired;

    return $statistics;
  }

}
