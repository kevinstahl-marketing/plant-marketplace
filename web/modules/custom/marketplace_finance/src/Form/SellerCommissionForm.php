<?php

declare(strict_types=1);

namespace Drupal\marketplace_finance\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\marketplace_seller\Entity\Seller;

/**
 * Administrative management of seller commission policies.
 */
final class SellerCommissionForm extends FormBase {

  public function getFormId(): string {
    return 'marketplace_finance_seller_commissions';
  }

  public function buildForm(
    array $form,
    FormStateInterface $form_state,
  ): array {

    $storage = \Drupal::entityTypeManager()
      ->getStorage('marketplace_seller');

    $resolver = \Drupal::service(
      'marketplace_finance.commission_resolver'
    );

    $form['description'] = [
      '#markup' => '<p>Changes apply only to newly published auctions. '
        . 'Leave an override empty to inherit the marketplace default. '
        . 'Enter zero for a commission-free seller.</p>',
    ];

    $form['sellers'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('Seller'),
        $this->t('Status'),
        $this->t('Effective rate'),
        $this->t('Policy source'),
        $this->t('Override (%)'),
      ],
      '#empty' => $this->t('No sellers found.'),
    ];

    foreach ($storage->loadMultiple() as $seller) {
      $id = $seller->id();

      $terms = $resolver->resolve($seller);

      $override = $seller
        ->get('commission_override_bps')->value;

      $form['sellers'][$id]['name'] = [
        '#plain_text' => $seller->label(),
      ];

      $form['sellers'][$id]['status'] = [
        '#plain_text' => $seller->get('status')->value,
      ];

      $form['sellers'][$id]['effective'] = [
        '#plain_text' => ($terms['basis_points'] / 100) . '%',
      ];

      $form['sellers'][$id]['source'] = [
        '#plain_text' => $terms['source'] === 'seller_override'
          ? 'Seller override'
          : 'Marketplace default',
      ];

      $form['sellers'][$id]['override'] = [
        '#type' => 'number',
        '#title' => $this->t('Commission override for @seller', [
          '@seller' => $seller->label(),
        ]),
        '#title_display' => 'invisible',
        '#default_value' => $override === NULL
          ? ''
          : ((int) $override / 100),
        '#min' => 0,
        '#max' => 100,
        '#step' => 0.01,
        '#field_suffix' => '%',
      ];
    }

    $form['actions']['#type'] = 'actions';

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save commission policies'),
      '#button_type' => 'primary',
    ];

    $form['#cache']['max-age'] = 0;

    return $form;
  }

  public function validateForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {

    foreach ($form_state->getValue('sellers', []) as $id => $row) {
      $value = $row['override'] ?? '';

      if ($value === '' || $value === NULL) {
        continue;
      }

      if (!is_numeric($value)
        || (float) $value < 0
        || (float) $value > 100) {
        $form_state->setError(
          $form['sellers'][$id]['override'],
          $this->t('Commission must be between 0 and 100 percent.'),
        );
      }
    }
  }

  public function submitForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {

    $storage = \Drupal::entityTypeManager()
      ->getStorage('marketplace_seller');

    $transaction = \Drupal::database()->startTransaction();

    $updated = 0;

    try {
      foreach ($form_state->getValue('sellers', []) as $id => $row) {
        $seller = $storage->load($id);

        if (!$seller instanceof Seller) {
          continue;
        }

        $value = $row['override'] ?? '';

        $rate = $value === '' || $value === NULL
          ? NULL
          : (int) round((float) $value * 100);

        $current = $seller
          ->get('commission_override_bps')->value;

        $current = $current === NULL ? NULL : (int) $current;

        if ($current === $rate) {
          continue;
        }

        $seller->set('commission_override_bps', $rate);
        $seller->save();

        $updated++;
      }

      unset($transaction);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      $storage->resetCache();
      throw $exception;
    }

    $this->messenger()->addStatus(
      $this->t('Updated @count seller commission policies.', [
        '@count' => $updated,
      ]),
    );

    $form_state->setRedirect(
      'marketplace_finance.seller_commissions'
    );
  }

}
