<?php

namespace Drupal\marketplace_cart\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\marketplace_cart\Service\CartManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class AddToCartForm extends FormBase {

  public function __construct(
    private readonly CartManager $cartManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('marketplace_cart.cart_manager'),
    );
  }

  public function getFormId(): string {
    return 'marketplace_add_to_cart_form';
  }

  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?string $variant_id = NULL,
  ): array {

    $form['variant_id'] = [
      '#type' => 'hidden',
      '#value' => $variant_id,
    ];

    $form['quantity'] = [
      '#type' => 'number',
      '#title' => $this->t('Quantity'),
      '#default_value' => 1,
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add to Cart'),
    ];

    return $form;
  }

  public function validateForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {

    $variant_id = (string) $form_state->getValue('variant_id');

    if (!preg_match(
      '#^gid://shopify/ProductVariant/[0-9]+$#',
      $variant_id
    )) {
      $form_state->setErrorByName(
        'variant_id',
        $this->t('Invalid product variant.')
      );
    }

    $quantity = (int) $form_state->getValue('quantity');

    if ($quantity < 1 || $quantity > 100) {
      $form_state->setErrorByName(
        'quantity',
        $this->t('Quantity must be between 1 and 100.')
      );
    }
  }

  public function submitForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {

    $variant_id = (string) $form_state->getValue('variant_id');
    $quantity = (int) $form_state->getValue('quantity');

    try {
      $cart = $this->cartManager->addItem(
        $variant_id,
        $quantity
      );

      $this->messenger()->addStatus(
        $this->t(
          'Added to cart! Your cart now contains @count item(s).',
          ['@count' => $cart['totalQuantity']]
        )
      );
    }
    catch (\Throwable $e) {
      \Drupal::logger('cart')->error(
        'Add to cart failed: @message',
        ['@message' => $e->getMessage()]
      );

      $this->messenger()->addError(
        $this->t('Unable to add this product to your cart.')
      );
    }
  }

}
