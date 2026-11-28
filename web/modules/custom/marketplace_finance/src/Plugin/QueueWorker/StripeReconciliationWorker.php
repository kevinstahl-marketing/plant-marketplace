<?php

declare(strict_types=1);

namespace Drupal\marketplace_finance\Plugin\QueueWorker;

use Drupal\Core\Annotation\QueueWorker;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\marketplace_finance\Service\StripeReconciliationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reconciles auction payments against Stripe.
 *
 * @QueueWorker(
 *   id = "marketplace_finance_reconciliation",
 *   title = @Translation("Marketplace Stripe reconciliation"),
 *   cron = {"time" = 30}
 * )
 */
final class StripeReconciliationWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly StripeReconciliationManager $reconciliation,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
    );
  }

  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(
        'marketplace_finance.stripe_reconciliation'
      ),
    );
  }

  public function processItem($data): void {
    $this->reconciliation->reconcile(
      (int) $data['payment_id']
    );
  }

}
