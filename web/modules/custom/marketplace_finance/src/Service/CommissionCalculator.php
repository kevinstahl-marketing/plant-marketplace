<?php

declare(strict_types=1);

namespace Drupal\marketplace_finance\Service;

/**
 * Calculates marketplace commissions using integer minor units.
 */
final class CommissionCalculator {

  public function calculate(
    int $amount,
    int $basisPoints,
  ): array {
    if ($amount < 0) {
      throw new \InvalidArgumentException(
        'Amount cannot be negative.'
      );
    }

    if ($basisPoints < 0 || $basisPoints > 10000) {
      throw new \InvalidArgumentException(
        'Commission must be between 0 and 100 percent.'
      );
    }

    // Round half up without floating-point arithmetic.
    $commission =
      intdiv($amount, 10000) * $basisPoints
      + intdiv(
        ($amount % 10000) * $basisPoints + 5000,
        10000,
      );

    return [
      'gross' => $amount,
      'commission' => $commission,
      'seller_proceeds' => $amount - $commission,
      'basis_points' => $basisPoints,
    ];
  }

}
