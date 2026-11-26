<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Service;

/**
 * Resolves the public price and leader from private maximum bids.
 *
 * All monetary amounts are expressed in integer cents.
 */
final class ProxyBidResolver {

  /**
   * Resolves an auction's competitive bidding state.
   *
   * Each offer contains:
   * - bidder_id: The bidder's account ID.
   * - maximum: The bidder's private maximum in cents.
   * - sequence: The order in which the maximum was established.
   *
   * @return array|null
   *   The winning bidder and public price, or NULL without offers.
   */
  public function resolve(
    int $startingPrice,
    int $increment,
    array $offers,
  ): ?array {

    if ($startingPrice < 1 || $increment < 1) {
      throw new \InvalidArgumentException(
        'Starting price and increment must be positive.'
      );
    }

    if ($offers === []) {
      return NULL;
    }

    $seenBidders = [];
    $seenSequences = [];

    foreach ($offers as $offer) {
      if (
        !isset(
          $offer['bidder_id'],
          $offer['maximum'],
          $offer['sequence']
        )
        || !is_int($offer['bidder_id'])
        || !is_int($offer['maximum'])
        || !is_int($offer['sequence'])
        || $offer['bidder_id'] < 1
        || $offer['maximum'] < $startingPrice
        || $offer['maximum'] > 2147483647
        || $offer['sequence'] < 1
        || isset($seenBidders[$offer['bidder_id']])
        || isset($seenSequences[$offer['sequence']])
      ) {
        throw new \InvalidArgumentException(
          'Invalid or duplicate proxy bid.'
        );
      }

      $seenBidders[$offer['bidder_id']] = TRUE;
      $seenSequences[$offer['sequence']] = TRUE;
    }

    // Highest maximum wins. Earlier maximum wins a tie.
    usort($offers, static function (array $a, array $b): int {
      return ($b['maximum'] <=> $a['maximum'])
        ?: ($a['sequence'] <=> $b['sequence']);
    });

    $winner = $offers[0];

    $price = $startingPrice;

    if (isset($offers[1])) {
      $runnerUp = $offers[1];

      $price = max(
        $startingPrice,
        min(
          $winner['maximum'],
          $runnerUp['maximum'] + $increment,
        ),
      );
    }

    return [
      'bidder_id' => $winner['bidder_id'],
      'price' => $price,
    ];
  }

}
