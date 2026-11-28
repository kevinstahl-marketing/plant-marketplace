<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\marketplace_auction\Entity\Auction;
use Drupal\marketplace_auction\ValueObject\ShippingSpecification;
use Drupal\marketplace_seller\Service\SellerManager;

/**
 * Handles marketplace auction creation.
 */
final class AuctionManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SellerManager $sellerManager,
    private readonly TimeInterface $time,
    private readonly AuctionMediaManager $mediaManager,
    private readonly Connection $database,
  ) {}

  /**
   * Creates an auction for an eligible seller.
   *
   * Monetary values are expressed in integer cents.
   */
  public function createAuction(
    int $userId,
    string $title,
    string $description,
    int $startingPrice,
    int $bidIncrement,
    int $durationHours,
    array $photos,
    ShippingSpecification $shipping,
    ?string $biddingMode = NULL,
  ): Auction {

    $seller = $this->sellerManager
      ->getActiveSellerForUser($userId);

    $account = $this->entityTypeManager
      ->getStorage('user')
      ->load($userId);

    if (!$seller || !$account
      || !$account->hasPermission('create marketplace auctions')) {
      throw new \DomainException(
        'You are not eligible to create auctions.'
      );
    }

    $title = trim($title);
    $description = trim($description);

    if ($title === '' || mb_strlen($title) > 255) {
      throw new \DomainException('Invalid auction title.');
    }

    if ($description === '' || mb_strlen($description) > 20000) {
      throw new \DomainException('Invalid auction description.');
    }

    if ($startingPrice < 1 || $startingPrice > 10000000
      || $bidIncrement < 1 || $bidIncrement > 10000000) {
      throw new \DomainException('Invalid auction pricing.');
    }

    if (!in_array($durationHours, [24, 72, 120, 168], TRUE)) {
      throw new \DomainException('Invalid auction duration.');
    }

    $config = \Drupal::config('marketplace_auction.settings');

    $defaultMode = $config->get('default_bidding_mode')
      ?? Auction::MODE_PROXY;

    if ($biddingMode === NULL) {
      $biddingMode = $defaultMode;
    }

    if (!in_array($biddingMode, [
      Auction::MODE_DIRECT,
      Auction::MODE_PROXY,
    ], TRUE)) {
      throw new \DomainException('Invalid bidding mode.');
    }

    if (!($config->get('allow_seller_bidding_mode') ?? TRUE)
      && $biddingMode !== $defaultMode) {
      throw new \DomainException(
        'Seller selection of bidding mode is disabled.'
      );
    }

    $softCloseWindow = (int) $config->get(
      'default_soft_close_window'
    );

    $softCloseExtension = (int) $config->get(
      'default_soft_close_extension'
    );

    if (
      $softCloseWindow < 0 || $softCloseWindow > 3600
      || $softCloseExtension < 0 || $softCloseExtension > 3600
    ) {
      throw new \DomainException(
        'Invalid marketplace soft close configuration.'
      );
    }

    $now = $this->time->getCurrentTime();

    $transaction = $this->database->startTransaction();

    try {
      $images = $this->mediaManager->createImages(
        $userId,
        $photos
      );

      $auction = $this->entityTypeManager
        ->getStorage('marketplace_auction')
        ->create([
          'title' => $title,
          'images' => $images,
          'description' => [
            'value' => $description,
            'format' => 'plain_text',
          ],
          'seller_id' => $userId,
          'seller_profile_id' => $seller->id(),
          'status' => Auction::STATUS_OPEN,
          'currency' => 'USD',
          'bidding_mode' => $biddingMode,
          'starting_price' => $startingPrice,
          'bid_increment' => $bidIncrement,
          'current_price' => 0,
          'bid_count' => 0,
          'starts_at' => $now,
          'ends_at' => $now + ($durationHours * 3600),
          'original_ends_at' => $now + ($durationHours * 3600),
          'soft_close_window' => $softCloseWindow,
          'soft_close_extension' => $softCloseExtension,
          'extension_count' => 0,
          ...$shipping->toFieldValues(),
        ]);

      $violations = $auction->validate();

      if ($violations->count()) {
        throw new \DomainException(
          'Auction validation failed.'
        );
      }

      $auction->save();

      unset($transaction);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();

      foreach (['file', 'media', 'marketplace_auction'] as $type) {
        $this->entityTypeManager
          ->getStorage($type)
          ->resetCache();
      }

      throw $exception;
    }

    return $auction;
  }

}
