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

      $agreement = \Drupal::service(
        'marketplace_finance.transaction_manager'
      )->loadByOrder((int) $order->id());

      if (!$agreement) {
        $skipped++;
        continue;
      }

      $lockedRate = (int) $agreement['commission_bps'];
      $amount = (int) $agreement['merchandise_amount'];

      $split = [
        'gross' => $amount,
        'commission' => (int) $agreement['commission_amount'],
        'seller_proceeds' => (int) $agreement['seller_proceeds'],
      ];

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
        ($lockedRate / 100) . '%',
        $this->money($split['commission']),
        $this->money($split['seller_proceeds']),
        $isPaid
          ? $this->t('Paid')
          : $this->t('Not fully paid'),
      ];
    }

    $build['notice'] = [
      '#markup' => '<p><strong>Contractual merchandise breakdown.</strong> '
        . 'Uses the commission locked at auction publication. Excludes shipping, '
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

    $build['seller_policies'] = [
      '#type' => 'link',
      '#title' => $this->t('Manage seller commissions'),
      '#url' => Url::fromRoute('marketplace_finance.seller_commissions'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    $build['summary'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Metric'),
        $this->t('Value'),
      ],
      '#rows' => [
        ['Default commission for new auctions', ($rate / 100) . '%'],
        ['Paid auction orders', $paidCount],
        ['Orders not fully paid', $pendingCount],
        ['Paid merchandise volume', $this->money($gross)],
        ['Gross commission', $this->money($commission)],
        ['Seller merchandise proceeds', $this->money($proceeds)],
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
        $this->t('Locked rate'),
        $this->t('Commission'),
        $this->t('Seller proceeds'),
        $this->t('Payment status'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No auction orders found.'),
    ];

    $build['stripe'] = $this->stripeReporting();

    $build['#cache']['max-age'] = 0;

    return $build;
  }

  private function stripeReporting(): array {
    $reporting = \Drupal::service(
      'marketplace_finance.reporting'
    );

    $observations = $reporting->latestPaymentObservations();

    $rows = [];
    $received = 0;
    $fees = 0;
    $net = 0;
    $refunded = 0;
    $matched = 0;
    $discrepancies = 0;
    $pending = 0;
    $seen = [];

    foreach ($observations as $record) {
      if ((int) $record['livemode'] !== 0
        || $record['currency'] !== 'USD') {
        continue;
      }

      $status = $record['reconciliation_status'];

      // Avoid counting a remote payment more than once.
      $intent = $record['payment_intent_id'];

      if (isset($seen[$intent])) {
        $status = 'duplicate_reference';
      }

      $seen[$intent] = TRUE;

      if ($status === 'matched') {
        $received += (int) $record['stripe_received'];
        $fees += (int) $record['stripe_fee'];
        $net += (int) $record['stripe_net'];
        $refunded += (int) $record['stripe_refunded'];
        $matched++;
      }
      elseif ($status === 'pending') {
        $pending++;
      }
      else {
        $discrepancies++;
      }

      $rows[] = [
        '#' . $record['order_id'],
        '#' . $record['commerce_payment_id'],
        $this->money((int) $record['stripe_received']),
        $record['stripe_fee'] === NULL
          ? 'Unknown'
          : $this->money((int) $record['stripe_fee']),
        $record['stripe_net'] === NULL
          ? 'Unknown'
          : $this->money((int) $record['stripe_net']),
        $this->money((int) $record['stripe_refunded']),
        $record['balance_status'] ?? 'Unknown',
        $status,
        gmdate('Y-m-d H:i:s', (int) $record['observed_at']) . ' UTC',
      ];
    }

    return [
      'heading' => [
        '#markup' => '<h2>Stripe reconciliation — test mode</h2>',
      ],
      'notice' => [
        '#markup' => '<p>Latest stored payment observations. '
          . 'Totals include matched test payments only. '
          . 'Charge net excludes subsequent refund balance transactions. '
          . 'These amounts are not available seller balances.</p>',
      ],
      'summary' => [
        '#type' => 'table',
        '#header' => ['Metric', 'Value'],
        '#rows' => [
          ['Verified Stripe receipts', $this->money($received)],
          ['Actual charge processing fees', $this->money($fees)],
          ['Original charge net', $this->money($net)],
          ['Reported refunds', $this->money($refunded)],
          ['Matched payments', $matched],
          ['Pending reconciliation', $pending],
          ['Discrepancies', $discrepancies],
        ],
      ],
      'payments' => [
        '#type' => 'table',
        '#caption' => 'Latest Stripe payment observations',
        '#header' => [
          'Order',
          'Payment',
          'Received',
          'Fee',
          'Charge net',
          'Refunded',
          'Funds status',
          'Reconciliation',
          'Last observation',
        ],
        '#rows' => $rows,
        '#empty' => 'No reconciled payments found.',
      ],
    ];
  }

  private function money(int $cents): string {
    return '$' . number_format(intdiv($cents, 100))
      . '.' . sprintf('%02d', $cents % 100);
  }

}
