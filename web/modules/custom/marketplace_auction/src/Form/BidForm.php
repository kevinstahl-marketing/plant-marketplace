<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\marketplace_auction\Entity\Auction;
use Drupal\marketplace_auction\Exception\BidRejectedException;

final class BidForm extends FormBase {

  public function getFormId(): string {
    return 'marketplace_auction_bid_form';
  }

  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?Auction $auction = NULL,
  ): array {

    if (!$auction) {
      return $form;
    }

    $form_state->set('auction_id', (int) $auction->id());

    $isProxy = $auction->usesProxyBidding();
    $minimumCents = $auction->getMinimumBid();
    $existing = NULL;

    if ($isProxy) {
      $existing = \Drupal::service(
        'marketplace_auction.proxy_bid_repository'
      )->getOffer(
        (int) $auction->id(),
        (int) $this->currentUser()->id(),
      );

      $leadingBid = $auction->get('leading_bid_id')->entity;

      $isLeader = $leadingBid
        && (int) $leadingBid->get('bidder_id')->target_id
          === (int) $this->currentUser()->id();

      if ($existing !== NULL) {
        $minimumCents = $isLeader
          ? $existing['maximum'] + 1
          : max($minimumCents, $existing['maximum'] + 1);

        $form['existing_maximum'] = [
          '#type' => 'item',
          '#title' => $this->t('Your private maximum'),
          '#plain_text' => '$' . number_format(
            $existing['maximum'] / 100,
            2,
          ),
        ];
      }

      $form['proxy_explanation'] = [
        '#type' => 'item',
        '#plain_text' => $this->t(
          'Enter the most you are willing to pay. We will automatically bid on your behalf. Your maximum remains private.'
        ),
      ];
    }

    if ($minimumCents > 2147483647) {
      $form['limit_reached'] = [
        '#type' => 'item',
        '#plain_text' => $this->t(
          'No higher bid can be submitted.'
        ),
      ];

      $form['#cache']['max-age'] = 0;
      return $form;
    }

    $minimum = number_format(
      $minimumCents / 100,
      2,
      '.',
      '',
    );

    $form['amount'] = [
      '#type' => 'number',
      '#title' => $isProxy
        ? $this->t('Your maximum bid (USD)')
        : $this->t('Your bid (USD)'),
      '#min' => $minimum,
      '#max' => '21474836.47',
      '#step' => '0.01',
      '#default_value' => $minimum,
      '#required' => TRUE,
      '#description' => $this->t(
        'Minimum acceptable amount: $@amount',
        ['@amount' => $minimum],
      ),
    ];

    $form_state->set('is_proxy', $isProxy);

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $isProxy
        ? $this->t('Submit maximum bid')
        : $this->t('Place bid'),
      '#button_type' => 'primary',
    ];

    $form['#cache']['max-age'] = 0;

    return $form;
  }

  public function validateForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {

    $value = (string) $form_state->getValue('amount');

    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) {
      $form_state->setErrorByName(
        'amount',
        $this->t('Enter a valid dollar amount.')
      );
      return;
    }

    // Convert dollars to cents without floating-point arithmetic.
    $parts = explode('.', $value, 2);
    $cents = ((int) $parts[0] * 100)
      + (int) str_pad($parts[1] ?? '', 2, '0');

    if ($cents <= 0 || $cents > 2147483647) {
      $form_state->setErrorByName(
        'amount',
        $this->t('Invalid bid amount.')
      );
      return;
    }

    $form_state->set('amount_cents', $cents);
  }

  public function submitForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {

    $auctionId = (int) $form_state->get('auction_id');
    $bidderId = (int) $this->currentUser()->id();
    $amount = (int) $form_state->get('amount_cents');

    try {
      \Drupal::service('marketplace_auction.bid_manager')
        ->placeBid($auctionId, $bidderId, $amount);

      $this->messenger()->addStatus(
        $form_state->get('is_proxy')
          ? $this->t('Your private maximum of $@amount was accepted.', [
            '@amount' => number_format($amount / 100, 2),
          ])
          : $this->t('Your bid of $@amount was accepted.', [
            '@amount' => number_format($amount / 100, 2),
          ])
      );
    }
    catch (BidRejectedException $exception) {
      $this->messenger()->addError($exception->getMessage());
    }

    $form_state->setRedirect(
      'marketplace_auction.detail',
      ['marketplace_auction' => $auctionId]
    );
  }

}
