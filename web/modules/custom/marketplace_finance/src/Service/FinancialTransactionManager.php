<?php

declare(strict_types=1);

namespace Drupal\marketplace_finance\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

final class FinancialTransactionManager {

  private const TABLE = 'marketplace_finance_transaction';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CommissionCalculator $calculator,
    private readonly TimeInterface $time,
  ) {}

  public function loadByOrder(int $orderId): ?array {
    $record = $this->database
      ->select(self::TABLE, 'f')
      ->fields('f')
      ->condition('order_id', $orderId)
      ->execute()
      ->fetchAssoc();

    return $record ?: NULL;
  }

  public function record(int $settlementId): array {
    $transaction = $this->database->startTransaction();

    try {
      $mapping = $this->database
        ->select('marketplace_order_settlement', 'm')
        ->fields('m')
        ->condition('settlement_id', $settlementId)
        ->forUpdate()
        ->execute()
        ->fetchAssoc();

      if (!$mapping) {
        throw new \DomainException(
          'Settlement has no associated Commerce order.'
        );
      }

      $settlement = $this->entityTypeManager
        ->getStorage('marketplace_auction_settlement')
        ->load($settlementId);

      $order = $this->entityTypeManager
        ->getStorage('commerce_order')
        ->load($mapping['order_id']);

      if (!$settlement || !$settlement->isSold() || !$order) {
        throw new \DomainException(
          'Invalid settlement or Commerce order.'
        );
      }

      $auction = $settlement->get('auction_id')->entity;

      if (!$auction
        || $auction->get('status')->value !== 'closed'
        || $auction->get('commission_snapshot_bps')->isEmpty()
        || $auction->get('commission_snapshot_source')->isEmpty()) {
        throw new \DomainException(
          'Auction has no valid locked financial agreement.'
        );
      }

      $sellerId = (int) $settlement
        ->get('seller_profile_id')->target_id;

      $buyerId = (int) $settlement
        ->get('winner_id')->target_id;

      if ($sellerId <= 0 || $buyerId <= 0
        || $sellerId !== (int) $auction
          ->get('seller_profile_id')->target_id
        || $buyerId !== (int) $order->getCustomerId()) {
        throw new \DomainException(
          'Settlement ownership does not match order.'
        );
      }

      $currency = (string) $settlement
        ->get('currency')->value;

      $amount = (int) $settlement
        ->get('final_price')->value;

      if ($currency !== 'USD' || $amount <= 0) {
        throw new \DomainException(
          'Unsupported settlement currency or amount.'
        );
      }

      $item = $this->entityTypeManager
        ->getStorage('commerce_order_item')
        ->load($mapping['order_item_id']);

      $expectedPrice = new \Drupal\commerce_price\Price(
        sprintf('%d.%02d', intdiv($amount, 100), $amount % 100),
        $currency,
      );

      $items = $order->getItems();

      if (!$item
        || count($items) !== 1
        || (int) reset($items)->id() !== (int) $item->id()
        || $item->bundle() !== 'auction_purchase'
        || !$item->getUnitPrice()
        || !$item->getUnitPrice()->equals($expectedPrice)
        || \Drupal\commerce_price\Calculator::compare(
          $item->getQuantity(),
          '1',
        ) !== 0) {
        throw new \DomainException(
          'Commerce merchandise does not match auction settlement.'
        );
      }

      $rate = (int) $auction
        ->get('commission_snapshot_bps')->value;

      $split = $this->calculator->calculate($amount, $rate);

      $fields = [
        'settlement_id' => $settlementId,
        'auction_id' => (int) $auction->id(),
        'order_id' => (int) $order->id(),
        'seller_id' => $sellerId,
        'buyer_id' => $buyerId,
        'currency' => $currency,
        'merchandise_amount' => $amount,
        'commission_bps' => $rate,
        'commission_amount' => $split['commission'],
        'seller_proceeds' => $split['seller_proceeds'],
        'commission_source' => (string) $auction
          ->get('commission_snapshot_source')->value,
      ];

      $existing = $this->loadByOrder((int) $order->id());

      if ($existing) {
        foreach ($fields as $name => $value) {
          if ((string) $existing[$name] !== (string) $value) {
            throw new \DomainException(
              'Existing financial agreement does not match settlement.'
            );
          }
        }

        unset($transaction);
        return $existing;
      }

      $fields['created'] = $this->time->getCurrentTime();

      $id = $this->database
        ->insert(self::TABLE)
        ->fields($fields)
        ->execute();

      $record = ['id' => $id] + $fields;

      unset($transaction);

      return $record;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

}
