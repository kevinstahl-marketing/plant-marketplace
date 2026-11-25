<?php

declare(strict_types=1);

namespace Drupal\marketplace_seller_dashboard\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Provides the seller dashboard.
 */
final class DashboardController extends ControllerBase {

  public function access(AccountInterface $account): AccessResultInterface {
    $seller = \Drupal::service('marketplace_seller.manager')
      ->getActiveSellerForUser((int) $account->id());

    return AccessResult::allowedIf(
      $account->isAuthenticated()
      && $account->hasPermission('create marketplace auctions')
      && $seller !== NULL
    )->cachePerUser()->setCacheMaxAge(0);
  }

  public function overview(): array {
    $uid = (int) $this->currentUser()->id();

    $seller = \Drupal::service('marketplace_seller.manager')
      ->getActiveSellerForUser($uid);

    if (!$seller) {
      throw new AccessDeniedHttpException();
    }

    $statistics = \Drupal::service(
      'marketplace_seller_dashboard.statistics'
    )->getStatistics($seller);

    return [
      '#theme' => 'marketplace_seller_dashboard',
      '#seller_name' => $seller->label(),
      '#seller_location' => $seller->get('location')->value,
      '#statistics' => $statistics,

      '#create_link' => [
        '#type' => 'link',
        '#title' => $this->t('Create auction'),
        '#url' => Url::fromRoute('marketplace_auction.create'),
        '#attributes' => [
          'class' => ['ui-button'],
        ],
      ],

      '#auctions' => [
        '#type' => 'view',
        '#name' => 'seller_auctions',
        '#display_id' => 'block_1',
        '#arguments' => [(string) $uid],
      ],

      '#cache' => [
        'contexts' => ['user', 'user.permissions'],
        'max-age' => 0,
      ],
    ];
  }

}
