<?php

namespace Drupal\marketplace_order\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Applies marketplace checkout authorization to Commerce checkout routes.
 */
final class CheckoutRouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    $routes = [
      'commerce_checkout.form',
      'commerce_payment.checkout.return',
      'commerce_payment.checkout.cancel',
    ];

    foreach ($routes as $name) {
      if ($route = $collection->get($name)) {
        $route->setRequirement(
          '_custom_access',
          '\Drupal\marketplace_order\Controller\AuctionCheckoutController::checkAccess'
        );
      }
    }
  }

}
