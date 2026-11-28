<?php

declare(strict_types=1);

namespace Drupal\marketplace_order\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\marketplace_auction\Entity\Auction;
use Drupal\marketplace_auction\Entity\AuctionSettlement;
use Drupal\marketplace_auction\ValueObject\ShippingSpecification;

/**
 * Converts sold auction settlements into Commerce orders.
 */
final class AuctionOrderManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Creates or retrieves the order for a sold settlement.
   */
  public function createOrder(int $settlementId): OrderInterface {
    if ($settlementId < 1) {
      throw new \DomainException('Invalid settlement ID.');
    }

    $transaction = $this->database->startTransaction();

    try {
      // Serialize concurrent processing of the same settlement.
      $locked = $this->database
        ->select('marketplace_auction_settlement', 's')
        ->fields('s', ['id'])
        ->condition('id', $settlementId)
        ->forUpdate()
        ->execute()
        ->fetchField();

      if (!$locked) {
        throw new \DomainException('Settlement not found.');
      }

      $existing = $this->database
        ->select('marketplace_order_settlement', 'm')
        ->fields('m', ['order_id'])
        ->condition('settlement_id', $settlementId)
        ->execute()
        ->fetchField();

      if ($existing) {
        $order = $this->entityTypeManager
          ->getStorage('commerce_order')
          ->load($existing);

        if (!$order instanceof OrderInterface) {
          throw new \DomainException('Mapped order is missing.');
        }

        \Drupal::moduleHandler()->invokeAll(
          'marketplace_order_ready',
          [$settlementId, $order],
        );

        unset($transaction);
        return $order;
      }

      $storage = $this->entityTypeManager
        ->getStorage('marketplace_auction_settlement');

      $storage->resetCache([$settlementId]);
      $settlement = $storage->load($settlementId);

      if (!$settlement instanceof AuctionSettlement
        || !$settlement->isSold()) {
        throw new \DomainException('Settlement is not a completed sale.');
      }

      $auctionId = (int) $settlement->get('auction_id')->target_id;

      $auctionStorage = $this->entityTypeManager
        ->getStorage('marketplace_auction');

      $auctionStorage->resetCache([$auctionId]);
      $auction = $auctionStorage->load($auctionId);

      if (!$auction instanceof Auction
        || $auction->get('status')->value !== 'closed') {
        throw new \DomainException('Auction is not closed.');
      }

      $winnerId = (int) $settlement->get('winner_id')->target_id;
      $sellerId = (int) $settlement->get('seller_profile_id')->target_id;
      $cents = (int) $settlement->get('final_price')->value;
      $currency = (string) $settlement->get('currency')->value;

      if ($winnerId < 1 || $sellerId < 1 || $cents < 1
        || $currency !== 'USD'
        || $winnerId === $auction->getSellerId()
        || $sellerId !== (int) $auction->get('seller_profile_id')->target_id
        || $cents !== (int) $auction->get('current_price')->value
        || $currency !== (string) $auction->get('currency')->value) {
        throw new \DomainException('Invalid settlement details.');
      }

      $winner = $this->entityTypeManager
        ->getStorage('user')
        ->load($winnerId);

      if (!$winner || !$winner->isActive()) {
        throw new \DomainException('Winning account is unavailable.');
      }

      $shipping = new ShippingSpecification(
        (int) $auction->get('package_weight_g')->value,
        (int) $auction->get('package_length_mm')->value,
        (int) $auction->get('package_width_mm')->value,
        (int) $auction->get('package_height_mm')->value,
        (int) $auction->get('handling_days')->value,
      );

      $storeUuid = $this->configFactory
        ->get('marketplace_order.settings')
        ->get('store_uuid');

      if (!$storeUuid) {
        throw new \DomainException('Marketplace store is not configured.');
      }

      $stores = $this->entityTypeManager
        ->getStorage('commerce_store')
        ->loadByProperties(['uuid' => $storeUuid]);

      if (count($stores) !== 1) {
        throw new \DomainException('Marketplace store not found.');
      }

      $store = reset($stores);

      if ($store->getDefaultCurrencyCode() !== $currency) {
        throw new \DomainException('Store currency mismatch.');
      }

      // Preserve monetary precision without floating-point arithmetic.
      $price = new Price(
        sprintf('%d.%02d', intdiv($cents, 100), $cents % 100),
        $currency,
      );

      $title = $auction->label();

      // Internal variation: not published for ordinary retail purchasing.
      $variation = $this->entityTypeManager
        ->getStorage('commerce_product_variation')
        ->create([
          'type' => 'auction_lot',
          'sku' => 'auction-' . $auctionId,
          'title' => $title,
          'status' => FALSE,
          'uid' => $auction->getSellerId(),
          'price' => $price,
          'weight' => [
            'number' => (string) $shipping->weightGrams,
            'unit' => 'g',
          ],
        ]);

      $this->validateAndSave($variation);

      $product = $this->entityTypeManager
        ->getStorage('commerce_product')
        ->create([
          'type' => 'auction_lot',
          'title' => $title,
          'status' => FALSE,
          'uid' => $auction->getSellerId(),
          'stores' => [$store->id()],
          'variations' => [$variation->id()],
        ]);

      $this->validateAndSave($product);

      $item = $this->entityTypeManager
        ->getStorage('commerce_order_item')
        ->create([
          'type' => 'auction_purchase',
          'purchased_entity' => $variation->id(),
          'quantity' => '1',
        ]);

      $item->setTitle($title, TRUE);
      $item->setUnitPrice($price, TRUE);

      $this->validateAndSave($item);

      $order = $this->entityTypeManager
        ->getStorage('commerce_order')
        ->create([
          'type' => 'auction_order',
          'store_id' => $store->id(),
          'uid' => $winnerId,
          'mail' => $winner->getEmail(),
          'state' => 'draft',
          'cart' => FALSE,
          'order_items' => [$item->id()],
        ]);

      $order->setRefreshState(OrderInterface::REFRESH_SKIP);

      $this->validateAndSave($order);

      $this->database
        ->insert('marketplace_order_settlement')
        ->fields([
          'settlement_id' => $settlementId,
          'order_id' => $order->id(),
          'order_item_id' => $item->id(),
          'created' => $this->time->getCurrentTime(),
        ])
        ->execute();

      \Drupal::moduleHandler()->invokeAll(
        'marketplace_order_ready',
        [$settlementId, $order],
      );

      unset($transaction);

      return $order;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();

      foreach ([
        'commerce_product',
        'commerce_product_variation',
        'commerce_order_item',
        'commerce_order',
      ] as $type) {
        $this->entityTypeManager
          ->getStorage($type)
          ->resetCache();
      }

      throw $exception;
    }
  }

  /**
   * Validates an entity before persisting it.
   */
  private function validateAndSave(EntityInterface $entity): void {
    $violations = $entity->validate();

    // Commerce assigns the order number during order placement.
    // A newly created draft order may legitimately have no number.
    if ($entity instanceof OrderInterface
      && $entity->isNew()
      && $entity->getState()->getId() === 'draft'
      && $entity->get('order_number')->isEmpty()) {

      for ($i = $violations->count() - 1; $i >= 0; $i--) {
        $violation = $violations->get($i);

        if ($violation->getPropertyPath() === 'order_number'
          && $violation->getCode() ===
            \Symfony\Component\Validator\Constraints\NotNull::IS_NULL_ERROR) {
          $violations->remove($i);
        }
      }
    }

    if ($violations->count()) {
      throw new \DomainException(sprintf(
        '%s validation failed: %s',
        $entity->getEntityTypeId(),
        (string) $violations,
      ));
    }

    $entity->save();
  }

}
