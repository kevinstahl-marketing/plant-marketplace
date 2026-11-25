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

    $minimum = number_format(
      $auction->getMinimumBid() / 100,
      2,
      '.',
      ''
    );

    $form['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Your bid (USD)'),
      '#min' => $minimum,
      '#max' => '21474836.47',
      '#step' => '0.01',
      '#default_value' => $minimum,
      '#required' => TRUE,
      '#description' => $this->t(
        'Minimum acceptable bid: $@amount',
        ['@amount' => $minimum]
      ),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Place bid'),
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
        $this->t('Your bid of $@amount was accepted.', [
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
