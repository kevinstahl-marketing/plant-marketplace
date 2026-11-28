<?php

declare(strict_types=1);

namespace Drupal\marketplace_finance\Service;

use Drupal\Core\Database\Connection;

final class FinanceReporting {

  public function __construct(
    private readonly Connection $database,
  ) {}

  public function latestPaymentObservations(): array {
    $latest = $this->database
      ->select('marketplace_finance_payment_snapshot', 'h');

    $latest->addExpression('MAX(h.id)', 'latest_id');
    $latest->groupBy('h.commerce_payment_id');

    $query = $this->database
      ->select('marketplace_finance_payment_snapshot', 'p');

    $query->innerJoin(
      $latest,
      'latest',
      'p.id = latest.latest_id',
    );

    $query->innerJoin(
      'marketplace_finance_transaction',
      'f',
      'p.transaction_id = f.id',
    );

    $query->fields('p');
    $query->addField('f', 'order_id');
    $query->addField('f', 'seller_id');

    $query->orderBy('p.id', 'DESC');

    return $query->execute()->fetchAllAssoc(
      'commerce_payment_id',
      \PDO::FETCH_ASSOC,
    );
  }

}
