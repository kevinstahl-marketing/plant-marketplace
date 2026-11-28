<?php

declare(strict_types=1);

namespace Drupal\marketplace_finance\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Stripe\StripeClient;

final class StripeReconciliationManager {

  private const TABLE = 'marketplace_finance_payment_snapshot';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FinancialTransactionManager $transactions,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Retrieves the latest persisted payment observation.
   */
  public function latest(int $paymentId): ?array {
    $record = $this->database
      ->select(self::TABLE, 's')
      ->fields('s')
      ->condition('commerce_payment_id', $paymentId)
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $record ?: NULL;
  }

  /**
   * Reconciles a Commerce payment against Stripe.
   */
  public function reconcile(int $paymentId): array {
    $lockName = 'marketplace_finance.payment.' . $paymentId;

    if (!$this->lock->acquire($lockName, 120.0)) {
      throw new \RuntimeException(
        'Payment reconciliation is already running.'
      );
    }

    try {
      return $this->reconcilePayment($paymentId);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  private function reconcilePayment(int $paymentId): array {
    $storage = $this->entityTypeManager
      ->getStorage('commerce_payment');

    $storage->resetCache([$paymentId]);

    $payment = $storage->load($paymentId);

    if (!$payment instanceof PaymentInterface) {
      throw new \DomainException('Commerce payment not found.');
    }

    $agreement = $this->transactions->loadByOrder(
      (int) $payment->getOrderId(),
    );

    if (!$agreement) {
      throw new \DomainException(
        'Payment has no marketplace financial agreement.'
      );
    }

    $gateway = $payment->getPaymentGateway();

    if (!$gateway) {
      throw new \DomainException('Payment gateway is missing.');
    }

    $plugin = $gateway->getPlugin();

    if (!in_array($plugin->getPluginId(), [
      'stripe',
      'stripe_payment_element',
    ], TRUE)) {
      throw new \DomainException('Unsupported payment gateway.');
    }

    if ($plugin->getMode() !== 'test'
      || $payment->getPaymentGatewayMode() !== 'test') {
      throw new \DomainException(
        'Live payment reconciliation is not enabled yet.'
      );
    }

    $remoteId = (string) $payment->getRemoteId();

    if (!str_starts_with($remoteId, 'pi_')) {
      throw new \DomainException(
        'Payment does not reference a Stripe PaymentIntent.'
      );
    }

    $configuration = $plugin->getConfiguration();

    $key = !empty($configuration['access_token'])
      ? $configuration['access_token']
      : $plugin->getSecretKey();

    if (!$key) {
      throw new \DomainException(
        'Stripe credentials are unavailable.'
      );
    }

    $stripe = new StripeClient([
      'api_key' => $key,
      'stripe_version' => $plugin->getApiVersion(),
    ]);

    // Read-only Stripe API request.
    $intent = $stripe->paymentIntents->retrieve(
      $remoteId,
      ['expand' => ['latest_charge.balance_transaction']],
    );

    $charge = $intent->latest_charge;

    if (is_string($charge)) {
      throw new \RuntimeException(
        'Stripe charge expansion was unavailable.'
      );
    }

    $balance = $charge?->balance_transaction;

    if (is_string($balance)) {
      throw new \RuntimeException(
        'Stripe balance transaction expansion was unavailable.'
      );
    }

    $amount = $payment->getAmount();

    if (!$amount) {
      throw new \DomainException('Payment amount is missing.');
    }

    $currency = $amount->getCurrencyCode();

    if ($currency !== 'USD') {
      throw new \DomainException(
        'Only USD reconciliation is currently supported.'
      );
    }

    $commerceAmount = $this->toCents($amount);

    $commerceRefunded = $payment->getRefundedAmount()
      ? $this->toCents($payment->getRefundedAmount())
      : 0;

    $stripeReceived = (int) $intent->amount_received;
    $stripeRefunded = (int) ($charge?->amount_refunded ?? 0);

    $state = $payment->getState()->getId();

    $discrepancies = [];

    if ($intent->id !== $remoteId) {
      $discrepancies[] = 'PaymentIntent identity mismatch.';
    }

    if ($intent->livemode) {
      $discrepancies[] = 'Unexpected live Stripe payment.';
    }

    if (strtoupper($intent->currency) !== $currency
      || $agreement['currency'] !== $currency) {
      $discrepancies[] = 'Payment currency mismatch.';
    }

    if ((int) $intent->amount !== $commerceAmount) {
      $discrepancies[] = 'PaymentIntent amount mismatch.';
    }

    if ($stripeRefunded !== $commerceRefunded) {
      $discrepancies[] = 'Refund amount mismatch.';
    }

    $completed = in_array($state, [
      'completed',
      'partially_refunded',
      'refunded',
    ], TRUE);

    if ($completed && $intent->status !== 'succeeded') {
      $discrepancies[] = 'Commerce and Stripe payment states disagree.';
    }

    if ($completed && $stripeReceived !== $commerceAmount) {
      $discrepancies[] = 'Received payment amount mismatch.';
    }

    if ($intent->status === 'succeeded' && !$completed) {
      $discrepancies[] = 'Stripe succeeded but Commerce is not completed.';
    }

    if ($charge) {
      if (strtoupper($charge->currency) !== $currency) {
        $discrepancies[] = 'Charge currency mismatch.';
      }

      if ($intent->status === 'succeeded'
        && (!$charge->paid || !$charge->captured)) {
        $discrepancies[] = 'Successful payment has an uncaptured charge.';
      }
    }

    if ($balance) {
      if (strtoupper($balance->currency) !== $currency) {
        $discrepancies[] = 'Balance transaction currency mismatch.';
      }

      if ((int) $balance->amount - (int) $balance->fee
        !== (int) $balance->net) {
        $discrepancies[] = 'Stripe balance arithmetic mismatch.';
      }

      if ((int) $balance->amount !== $stripeReceived) {
        $discrepancies[] = 'Balance transaction gross amount mismatch.';
      }
    }

    $status = 'pending';

    if ($discrepancies) {
      $status = 'discrepancy';
    }
    elseif ($completed
      && $intent->status === 'succeeded'
      && $charge
      && $balance) {
      $status = 'matched';
    }

    $fields = [
      'transaction_id' => (int) $agreement['id'],
      'commerce_payment_id' => $paymentId,
      'gateway_id' => (string) $gateway->id(),
      'payment_intent_id' => (string) $intent->id,
      'charge_id' => $charge?->id,
      'balance_transaction_id' => $balance?->id,
      'currency' => $currency,
      'commerce_amount' => $commerceAmount,
      'stripe_received' => $stripeReceived,
      'stripe_fee' => $balance ? (int) $balance->fee : NULL,
      'stripe_net' => $balance ? (int) $balance->net : NULL,
      'stripe_refunded' => $stripeRefunded,
      'commerce_refunded' => $commerceRefunded,
      'stripe_status' => (string) $intent->status,
      'commerce_status' => $state,
      'balance_status' => $balance?->status,
      'livemode' => (int) $intent->livemode,
      'reconciliation_status' => $status,
      'discrepancies' => json_encode(
        $discrepancies,
        JSON_THROW_ON_ERROR,
      ),
    ];

    $fields['fingerprint'] = hash(
      'sha256',
      json_encode($fields, JSON_THROW_ON_ERROR),
    );

    $latest = $this->latest($paymentId);

    if ($latest
      && $latest['fingerprint'] === $fields['fingerprint']) {
      return $latest;
    }

    $fields['observed_at'] = $this->time->getCurrentTime();

    $id = $this->database
      ->insert(self::TABLE)
      ->fields($fields)
      ->execute();

    return ['id' => $id] + $fields;
  }

  /**
   * Converts a USD decimal price to integer cents.
   */
  private function toCents(Price $price): int {
    if ($price->getCurrencyCode() !== 'USD') {
      throw new \DomainException('Unsupported price currency.');
    }

    $number = $price->getNumber();

    if (!preg_match(
      '/^([0-9]+)(?:\.([0-9]+))?$/D',
      $number,
      $matches,
    )) {
      throw new \DomainException('Invalid monetary amount.');
    }

    $fraction = $matches[2] ?? '';

    if (strlen($fraction) > 2
      && trim(substr($fraction, 2), '0') !== '') {
      throw new \DomainException(
        'Monetary amount exceeds cent precision.'
      );
    }

    return ((int) $matches[1] * 100)
      + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
  }

}
