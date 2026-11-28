<?php

declare(strict_types=1);

namespace Drupal\marketplace_finance\EventSubscriber;

use Drupal\Core\Queue\QueueFactory;
use Drupal\commerce_payment\Event\PaymentEvent;
use Drupal\commerce_payment\Event\PaymentEvents;
use Drupal\marketplace_finance\Service\FinancialTransactionManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Queues auction payments for independent Stripe reconciliation.
 */
final class PaymentSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly QueueFactory $queueFactory,
    private readonly FinancialTransactionManager $transactions,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      PaymentEvents::PAYMENT_INSERT => 'onPayment',
      PaymentEvents::PAYMENT_UPDATE => 'onPayment',
    ];
  }

  public function onPayment(PaymentEvent $event): void {
    $payment = $event->getPayment();

    if (!$payment->id() || !$payment->getOrderId()) {
      return;
    }

    if (!$this->transactions->loadByOrder(
      (int) $payment->getOrderId()
    )) {
      return;
    }

    $gateway = $payment->getPaymentGateway();

    if (!$gateway || !in_array(
      $gateway->getPlugin()->getPluginId(),
      ['stripe', 'stripe_payment_element'],
      TRUE,
    )) {
      return;
    }

    if ($payment->getPaymentGatewayMode() !== 'test') {
      return;
    }

    if (!str_starts_with(
      (string) $payment->getRemoteId(), 'pi_'
    )) {
      return;
    }

    $this->queueFactory
      ->get('marketplace_finance_reconciliation')
      ->createItem([
        'payment_id' => (int) $payment->id(),
      ]);
  }

}
