<?php

namespace Drupal\marketplace_order\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\commerce_checkout\Controller\CheckoutController;
use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Extends Commerce checkout access for auction purchases.
 */
class AuctionCheckoutController extends CheckoutController {

  /**
   * {@inheritdoc}
   */
  public function checkAccess(RouteMatchInterface $route_match, AccountInterface $account) {
    $order = $route_match->getParameter('commerce_order');

    if (!$order instanceof OrderInterface) {
      return AccessResult::forbidden();
    }

    // Preserve native Commerce access for ordinary orders.
    if ($order->bundle() !== 'auction_order') {
      return parent::checkAccess($route_match, $account);
    }

    $mapping = \Drupal::database()
      ->select('marketplace_order_settlement', 'm')
      ->fields('m', ['settlement_id'])
      ->condition('order_id', $order->id())
      ->execute()
      ->fetchField();

    $settlement = $mapping
      ? \Drupal::entityTypeManager()
        ->getStorage('marketplace_auction_settlement')
        ->load($mapping)
      : NULL;

    $eligible = $settlement
      && $settlement->isSold()
      && $account->isAuthenticated()
      && (int) $account->id() === (int) $order->getCustomerId()
      && (int) $account->id() === (int) $settlement->get('winner_id')->target_id
      && $order->getState()->getId() !== 'canceled'
      && $order->getStore()->isPublished()
      && $order->hasItems();

    return AccessResult::allowedIf($eligible)
      ->andIf(AccessResult::allowedIfHasPermission($account, 'access checkout'))
      ->cachePerUser()
      ->addCacheableDependency($order)
      ->setCacheMaxAge(0);
  }

}
