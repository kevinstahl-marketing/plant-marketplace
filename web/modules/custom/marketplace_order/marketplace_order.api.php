<?php

/**
 * @file
 * Hooks provided by Marketplace Order.
 */

/**
 * Responds to an available auction order.
 *
 * Invoked inside the order creation database transaction.
 * May run multiple times for the same settlement.
 * Implementations must be idempotent.
 *
 * Do not perform external API calls or irreversible side effects here.
 *
 * @param int $settlement_id
 *   The auction settlement ID.
 * @param \Drupal\commerce_order\Entity\OrderInterface $order
 *   The associated Commerce order.
 */
function hook_marketplace_order_ready(
  int $settlement_id,
  \Drupal\commerce_order\Entity\OrderInterface $order,
): void {
  // Record local marketplace-specific information.
}
