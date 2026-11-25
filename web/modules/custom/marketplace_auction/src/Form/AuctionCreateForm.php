<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Allows eligible sellers to create auctions.
 */
final class AuctionCreateForm extends FormBase {

  public function getFormId(): string {
    return 'marketplace_auction_create_form';
  }

  public function buildForm(
    array $form,
    FormStateInterface $form_state,
  ): array {

    $seller = \Drupal::service('marketplace_seller.manager')
      ->getActiveSellerForUser(
        (int) $this->currentUser()->id()
      );

    if (!$seller) {
      throw new AccessDeniedHttpException();
    }

    $config = $this->config('marketplace_auction.settings');

    $form['#cache']['max-age'] = 0;

    $form['seller'] = [
      '#type' => 'item',
      '#title' => $this->t('Selling as'),
      '#plain_text' => $seller->label(),
    ];

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Listing title'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#description' => $this->t(
        'Describe the specimen, its condition and origin.'
      ),
      '#required' => TRUE,
      '#rows' => 6,
    ];

    $form['starting_price'] = [
      '#type' => 'number',
      '#title' => $this->t('Starting price ($)'),
      '#required' => TRUE,
      '#min' => 0.01,
      '#max' => 100000,
      '#step' => 0.01,
    ];

    $form['bid_increment'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum bid increment ($)'),
      '#required' => TRUE,
      '#min' => 0.01,
      '#max' => 100000,
      '#step' => 0.01,
      '#default_value' => number_format(
        ((int) ($config->get('default_bid_increment') ?? 100)) / 100,
        2,
        '.',
        ''
      ),
    ];

    $form['duration'] = [
      '#type' => 'select',
      '#title' => $this->t('Auction duration'),
      '#options' => [
        24 => $this->t('1 day'),
        72 => $this->t('3 days'),
        120 => $this->t('5 days'),
        168 => $this->t('7 days'),
      ],
      '#default_value' => 168,
      '#required' => TRUE,
    ];

    $form['notice'] = [
      '#type' => 'item',
      '#plain_text' => $this->t(
        'Your auction will begin immediately after publishing.'
      ),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Publish auction'),
    ];

    return $form;
  }

  public function validateForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {

    foreach (['starting_price', 'bid_increment'] as $field) {
      $value = (string) $form_state->getValue($field);

      if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $value)) {
        $form_state->setErrorByName(
          $field,
          $this->t('Enter a valid monetary amount.')
        );
        continue;
      }

      $parts = explode('.', $value, 2);

      $cents = ((int) $parts[0] * 100)
        + (int) str_pad($parts[1] ?? '', 2, '0');

      if ($cents < 1 || $cents > 10000000) {
        $form_state->setErrorByName(
          $field,
          $this->t('Amount is outside the allowed range.')
        );
        continue;
      }

      $form_state->set($field . '_cents', $cents);
    }

    if (trim((string) $form_state->getValue('title')) === '') {
      $form_state->setErrorByName(
        'title',
        $this->t('Enter a listing title.')
      );
    }

    $description = trim(
      (string) $form_state->getValue('description')
    );

    if ($description === '' || mb_strlen($description) > 20000) {
      $form_state->setErrorByName(
        'description',
        $this->t('Enter a description of up to 20,000 characters.')
      );
    }
  }

  public function submitForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {

    try {
      $auction = \Drupal::service('marketplace_auction.manager')
        ->createAuction(
          (int) $this->currentUser()->id(),
          (string) $form_state->getValue('title'),
          (string) $form_state->getValue('description'),
          (int) $form_state->get('starting_price_cents'),
          (int) $form_state->get('bid_increment_cents'),
          (int) $form_state->getValue('duration'),
        );
    }
    catch (\DomainException $exception) {
      $this->messenger()->addError($exception->getMessage());
      $form_state->setRebuild(TRUE);
      return;
    }

    $this->messenger()->addStatus(
      $this->t('Your auction has been published.')
    );

    $form_state->setRedirect(
      'marketplace_auction.detail',
      ['marketplace_auction' => $auction->id()]
    );
  }

}
