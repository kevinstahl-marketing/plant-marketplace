<?php

declare(strict_types=1);

namespace Drupal\marketplace_seller\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\marketplace_seller\Entity\Seller;

/**
 * Resolves marketplace seller profiles and eligibility.
 */
final class SellerManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the unique seller profile belonging to a user.
   */
  public function getSellerForUser(int $userId): ?Seller {
    if ($userId <= 0) {
      return NULL;
    }

    $storage = $this->entityTypeManager
      ->getStorage('marketplace_seller');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('user_id', $userId)
      ->range(0, 2)
      ->execute();

    if (count($ids) !== 1) {
      return NULL;
    }

    $seller = $storage->load(reset($ids));

    return $seller instanceof Seller ? $seller : NULL;
  }

  /**
   * Returns an active seller belonging to an active user.
   */
  public function getActiveSellerForUser(int $userId): ?Seller {
    $seller = $this->getSellerForUser($userId);

    if (!$seller || !$seller->isActive()) {
      return NULL;
    }

    $account = $this->entityTypeManager
      ->getStorage('user')
      ->load($userId);

    if (!$account || !$account->isActive()) {
      return NULL;
    }

    return $seller;
  }

}
