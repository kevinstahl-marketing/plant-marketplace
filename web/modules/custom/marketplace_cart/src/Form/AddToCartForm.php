<?php

namespace Drupal\marketplace_cart\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\marketplace_cart\Service\CartManager;
use Drupal\marketplace_catalog\Service\ProductVariantProvider;
use Drupal\node\NodeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds a Shopify variant to the customer's cart.
 */
final class AddToCartForm extends FormBase {

  public function __construct(
    private readonly CartManager $cartManager,
    private readonly ProductVariantProvider $variantProvider,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('marketplace_cart.cart_manager'),
      $container->get('marketplace_catalog.variant_provider'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'marketplace_add_to_cart_form';
  }

  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?string $variant_id = NULL,
    ?int $product_id = NULL,
  ): array {

    $form['#product_id'] = $product_id;
    $form['#test_variant_id'] = $variant_id;

    if ($product_id !== NULL) {
      $product = $this->loadProduct($product_id);

      if (!$product) {
        $form['unavailable'] = [
          '#markup' => $this->t('Product unavailable.'),
        ];
        return $form;
      }

      $variants = $this->variantProvider->getVariants($product);
      $options = [];

      foreach ($variants as $variant) {
        if (!(bool) $variant->get('field_variant_available')->value) {
          continue;
        }

        $id = (string) $variant->get('field_variant_shopify_id')->value;
        $price = (float) $variant->get('field_variant_price')->value;

        if (!preg_match('#^gid://shopify/ProductVariant/[0-9]+$#', $id)) {
          continue;
        }

        $options[$id] = $variant->label()
          . ' — $' . number_format($price, 2);
      }

      if (!$options) {
        $form['unavailable'] = [
          '#markup' => $this->t('Currently out of stock.'),
        ];
        return $form;
      }

      $form['variant_id'] = [
        '#type' => 'select',
        '#title' => $this->t('Choose an option'),
        '#options' => $options,
        '#required' => TRUE,
      ];

      if (count($options) === 1) {
        $form['variant_id']['#type'] = 'hidden';
        $form['variant_id']['#value'] = array_key_first($options);
      }

      $form['#cache']['tags'] = array_merge(
        $product->getCacheTags(),
        ['node_list:shopify_variant']
      );

      foreach ($variants as $variant) {
        $form['#cache']['tags'] = array_merge(
          $form['#cache']['tags'],
          $variant->getCacheTags()
        );
      }
    }
    else {
      // Compatibility with the existing cart test route.
      $form['variant_id'] = [
        '#type' => 'hidden',
        '#value' => $variant_id,
      ];
    }

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
      return;
    }

    $product_id = $form['#product_id'] ?? NULL;

    if ($product_id !== NULL) {
      $product = $this->loadProduct((int) $product_id);
      $valid = FALSE;

      if ($product) {
        foreach ($this->variantProvider->getVariants($product) as $variant) {
          if (
            (string) $variant->get('field_variant_shopify_id')->value === $variant_id
            && (bool) $variant->get('field_variant_available')->value
          ) {
            $valid = TRUE;
            break;
          }
        }
      }

      if (!$valid) {
        $form_state->setErrorByName(
          'variant_id',
          $this->t('This option is no longer available.')
        );
      }
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
      $cart = $this->cartManager->addItem($variant_id, $quantity);

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

  private function loadProduct(int $product_id): ?NodeInterface {
    $product = $this->entityTypeManager
      ->getStorage('node')
      ->load($product_id);

    return $product instanceof NodeInterface
      && $product->bundle() === 'shopify_product'
      && $product->isPublished()
        ? $product
        : NULL;
  }

}
