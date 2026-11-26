<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Service;

use Drupal\marketplace_auction\Entity\Auction;

/**
 * Protects auction terms after bidding begins.
 */
final class AuctionIntegrityPolicy {

  public const PROTECTED_FIELDS = [
    'title',
    'description',
    'images',
    'seller_id',
    'seller_profile_id',
    'starting_price',
    'bid_increment',
    'bidding_mode',
    'currency',
    'starts_at',
    'original_ends_at',
    'soft_close_window',
    'soft_close_extension',
  ];

  /**
   * Rejects changes to committed auction terms.
   */
  public function assertValidUpdate(
    Auction $original,
    Auction $updated,
  ): void {

    if ((int) $original->get('bid_count')->value === 0) {
      return;
    }

    foreach (self::PROTECTED_FIELDS as $field) {
      if (!$original->get($field)->equals($updated->get($field))) {
        throw new \DomainException(
          sprintf(
            'Auction field "%s" cannot change after bidding begins.',
            $field,
          ),
        );
      }
    }

    if (
      (int) $updated->get('ends_at')->value
      < (int) $original->get('ends_at')->value
    ) {
      throw new \DomainException(
        'An auction deadline cannot be shortened after bidding begins.'
      );
    }

    if (
      (int) $updated->get('current_price')->value
      < (int) $original->get('current_price')->value
    ) {
      throw new \DomainException(
        'The current auction price cannot decrease.'
      );
    }

    if (
      (int) $updated->get('bid_count')->value
      < (int) $original->get('bid_count')->value
    ) {
      throw new \DomainException(
        'The auction bid count cannot decrease.'
      );
    }
  }

}
