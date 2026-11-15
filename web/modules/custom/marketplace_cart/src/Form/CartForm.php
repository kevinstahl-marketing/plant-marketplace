<?php

namespace Drupal\marketplace_cart\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\marketplace_cart\Service\CartManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class CartForm extends FormBase {

  public function __construct(
    private readonly CartManager $cartManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('marketplace_cart.cart_manager'),
    );
  }

  public function getFormId(): string {
    return 'marketplace_cart_form';
  }

  public function buildForm(
    array $form,
    FormStateInterface $form_state,
  ): array {

    // Never publicly cache a visitor's cart.
    $form['#cache']['max-age'] = 0;

    try {
      $cart = $this->cartManager->getCart();
    }
    catch (\Throwable $e) {
      \Drupal::logger('marketplace_cart')->error(
        'Cart retrieval failed: @message',
        ['@message' => $e->getMessage()]
      );

      $form['message'] = [
        '#markup' => '<p>Unable to load your cart. Please try again.</p>',
      ];

      return $form;
    }

    $lines = $cart['lines']['nodes'] ?? [];

    if (!$lines) {
      $form['empty'] = [
        '#markup' => '<p>Your cart is empty.</p>',
      ];
      return $form;
    }

    $form['items'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['cart__items']],
    ];

    foreach ($lines as $index => $line) {
      $variant = $line['merchandise'] ?? [];
      $title = $variant['product']['title'] ?? 'Product';

      $form['items'][$index] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['cart__item']],
      ];

      $form['items'][$index]['title'] = [
        '#plain_text' => $title,
      ];

      $form['items'][$index]['quantity'] = [
        '#type' => 'number',
        '#title' => $this->t('Quantity'),
        '#default_value' => $line['quantity'],
        '#min' => 1,
        '#max' => 100,
      ];

      $form['items'][$index]['update'] = [
        '#type' => 'submit',
        '#value' => $this->t('Update'),
        '#cart_action' => 'update',
        '#cart_line_id' => $line['id'],
      ];

      $form['items'][$index]['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#cart_action' => 'remove',
        '#cart_line_id' => $line['id'],
        '#limit_validation_errors' => [],
      ];
    }

    $subtotal = $cart['cost']['subtotalAmount'] ?? [];
    $amount = $subtotal['amount'] ?? '0.00';
    $currency = $subtotal['currencyCode'] ?? 'USD';

    $form['subtotal'] = [
      '#markup' => '<p class="cart__subtotal"><strong>' .
        $this->t('Subtotal: @amount @currency', [
          '@amount' => $amount,
          '@currency' => $currency,
        ]) .
        '</strong></p>',
    ];

    if (!empty($cart['checkoutUrl'])) {
      $checkout_url = $cart['checkoutUrl'];
      $host = parse_url($checkout_url, PHP_URL_HOST);

      // Only render checkout links on our Shopify store.
      if (
        parse_url($checkout_url, PHP_URL_SCHEME) === 'https' &&
        $host === 'unhg0x-fm.myshopify.com'
      ) {
        $form['checkout'] = [
          '#type' => 'link',
          '#title' => $this->t('Proceed to Checkout'),
          '#url' => Url::fromUri($checkout_url),
          '#attributes' => [
            'class' => ['button', 'button--primary', 'cart__checkout'],
          ],
        ];
      }
    }

    return $form;
  }

  public function submitForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {

    $button = $form_state->getTriggeringElement();
    $action = $button['#cart_action'] ?? '';
    $line_id = $button['#cart_line_id'] ?? '';

    if (!$line_id) {
      return;
    }

    try {
      if ($action === 'remove') {
        $this->cartManager->removeItem($line_id);
        $this->messenger()->addStatus($this->t('Item removed.'));
      }
      elseif ($action === 'update') {
        $parents = $button['#parents'];
        $index = $parents[1] ?? NULL;

        $quantity = (int) $form_state->getValue([
          'items', $index, 'quantity',
        ]);

        $this->cartManager->updateItem($line_id, $quantity);
        $this->messenger()->addStatus($this->t('Cart updated.'));
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('marketplace_cart')->error(
        'Cart operation failed: @message',
        ['@message' => $e->getMessage()]
      );

      $this->messenger()->addError(
        $this->t('Unable to update your cart.')
      );
    }

    $form_state->setRedirect('marketplace_cart.cart');
  }

}
