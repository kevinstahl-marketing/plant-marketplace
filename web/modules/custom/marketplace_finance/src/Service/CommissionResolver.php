<?php

declare(strict_types=1);

namespace Drupal\marketplace_finance\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\marketplace_seller\Entity\Seller;

/**
 * Resolves commission terms for newly published auctions.
 */
final class CommissionResolver {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function resolve(?Seller $seller): array {
    $override = $seller?->get('commission_override_bps')->value;

    if ($override !== NULL) {
      $rate = (int) $override;
      $source = 'seller_override';
    }
    else {
      $value = $this->configFactory
        ->get('marketplace_finance.settings')
        ->get('commission_basis_points');

      if ($value === NULL) {
        throw new \DomainException('Commission is not configured.');
      }

      $rate = (int) $value;
      $source = 'marketplace_default';
    }

    if ($rate < 0 || $rate > 10000) {
      throw new \DomainException('Invalid commission rate.');
    }

    return [
      'basis_points' => $rate,
      'source' => $source,
    ];
  }

}
