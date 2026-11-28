<?php

declare(strict_types=1);

namespace Drupal\marketplace_finance\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\commerce_price\Price;

final class FinanceController extends ControllerBase {

  public function overview(): array {
    $database = \Drupal::database();
    $manager = $this->entityTypeManager();

    $calculator = \Drupal::service(
      'marketplace_finance.commission_calculator'
    );

    $rate = (int) $this->config(
      'marketplace_finance.settings'
    )->get('commission_basis_points');

    $query = $database->select(
      'marketplace_order_settlement',
      'm',
    );

    $query->fields('m', [
      'settlement_id',
      'order_id',
    ]);

    $query->orderBy('created', 'DESC');

    $mappings = $query->execute()->fetchAll();

    $rows = [];
    $gross = 0;
    $commission = 0;
    $proceeds = 0;
    $paidCount = 0;
    $pendingCount = 0;
    $skipped = 0;

    foreach ($mappings as $mapping) {
      $settlement = $manager
        ->getStorage('marketplace_auction_settlement')
        ->load($mapping->settlement_id);

      $order = $manager
        ->getStorage('commerce_order')
        ->load($mapping->order_id);

      if (!$settlement || !$order || !$settlement->isSold()) {
        $skipped++;
        continue;
      }

      if ($order->getState()->getId() === 'canceled') {
        continue;
      }

      $currency = (string) $settlement
        ->get('currency')->value;

      if ($currency !== 'USD') {
        $skipped++;
        continue;
      }

      $amount = (int) $settlement
        ->get('final_price')->value;

      $split = $calculator->calculate($amount, $rate);

      $seller = $settlement
        ->get('seller_profile_id')->entity;

      $total = $order->getTotalPrice();
      $paid = $order->getTotalPaid();

      $isPaid = $total
        && $paid
        && $total->getCurrencyCode() === $currency
        && $paid->getCurrencyCode() === $currency
        && $total->greaterThan(new Price('0', $currency))
        && !$paid->lessThan($total);

      if ($isPaid) {
        $gross += $split['gross'];
        $commission += $split['commission'];
        $proceeds += $split['seller_proceeds'];
        $paidCount++;
      }
      else {
        $pendingCount++;
      }

      $rows[] = [
        [
          'data' => Link::fromTextAndUrl(
            '#' . $order->id(),
            Url::fromRoute(
              'entity.commerce_order.canonical',
              ['commerce_order' => $order->id()],
            ),
          )->toRenderable(),
        ],
        $seller ? $seller->label() : 'Unknown seller',
        $this->money($amount),
        $this->money($split['commission']),
        $this->money($split['seller_proceeds']),
        $isPaid
          ? $this->t('Paid')
          : $this->t('Not fully paid'),
      ];
    }

    $build['notice'] = [
      '#markup' => '<p><strong>Estimated financial breakdown.</strong> '
        . 'Uses the current commission rate. Excludes shipping, '
        . 'taxes, processing fees and payout reconciliation. '
        . 'These are not recorded seller balances.</p>',
    ];

    $build['settings'] = [
      '#type' => 'link',
      '#title' => $this->t('Configure commission'),
      '#url' => Url::fromRoute('marketplace_finance.settings'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    $build['summary'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Metric'),
        $this->t('Value'),
      ],
      '#rows' => [
        ['Current commission', ($rate / 100) . '%'],
        ['Paid auction orders', $paidCount],
        ['Orders not fully paid', $pendingCount],
        ['Paid merchandise volume', $this->money($gross)],
        ['Estimated commission', $this->money($commission)],
        ['Estimated seller proceeds', $this->money($proceeds)],
        ['Invalid or unsupported records', $skipped],
      ],
    ];

    $build['transactions'] = [
      '#type' => 'table',
      '#caption' => $this->t('Auction order breakdown'),
      '#header' => [
        $this->t('Commerce order'),
        $this->t('Seller'),
        $this->t('Sale price'),
        $this->t('Est. commission'),
        $this->t('Est. seller proceeds'),
        $this->t('Payment status'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No auction orders found.'),
    ];

    $build['#cache']['max-age'] = 0;

    return $build;
  }

  private function money(int $cents): string {
    return '$' . number_format(intdiv($cents, 100))
      . '.' . sprintf('%02d', $cents % 100);
  }

}
