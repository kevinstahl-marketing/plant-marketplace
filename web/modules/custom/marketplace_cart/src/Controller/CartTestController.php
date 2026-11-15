<?php

namespace Drupal\marketplace_cart\Controller;

use Drupal\Core\Controller\ControllerBase;

final class CartTestController extends ControllerBase {

  public function test(): array {

    $variant_id = 'gid://shopify/ProductVariant/67894980280419';

    return [
      '#type' => 'container',
      'heading' => [
        '#markup' => '<h1>Shopify Cart Test</h1>',
      ],
      'form' => $this->formBuilder()->getForm(
        'Drupal\marketplace_cart\Form\AddToCartForm',
        $variant_id
      ),
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
