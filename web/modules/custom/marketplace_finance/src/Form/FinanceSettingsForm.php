<?php

declare(strict_types=1);

namespace Drupal\marketplace_finance\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

final class FinanceSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'marketplace_finance_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['marketplace_finance.settings'];
  }

  public function buildForm(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $config = $this->config('marketplace_finance.settings');

    $form['commission_percent'] = [
      '#type' => 'number',
      '#title' => $this->t('Marketplace commission'),
      '#default_value' =>
        $config->get('commission_basis_points') / 100,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.01,
      '#field_suffix' => '%',
      '#required' => TRUE,
      '#description' => $this->t(
        'Estimated commission on the auction merchandise price, excluding shipping and taxes.'
      ),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $percent = (float) $form_state->getValue(
      'commission_percent'
    );

    $this->config('marketplace_finance.settings')
      ->set(
        'commission_basis_points',
        (int) round($percent * 100)
      )
      ->save();

    parent::submitForm($form, $form_state);
  }

}
