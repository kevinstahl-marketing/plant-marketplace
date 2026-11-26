<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Service;

/**
 * Calculates auction deadline extensions.
 */
final class SoftClosePolicy {

  /**
   * Returns the effective closing timestamp after a competitive bid.
   */
  public function resolve(
    int $endsAt,
    int $now,
    int $window,
    int $extension,
  ): int {

    if ($window <= 0 || $extension <= 0) {
      return $endsAt;
    }

    $remaining = $endsAt - $now;

    if ($remaining <= 0 || $remaining > $window) {
      return $endsAt;
    }

    return max($endsAt, $now + $extension);
  }

}
