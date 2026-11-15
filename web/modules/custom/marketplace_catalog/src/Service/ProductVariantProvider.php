<?php

namespace Drupal\marketplace_catalog\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Provides Shopify variants associated with a Drupal product node.
 */
final class ProductVariantProvider {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns variants for a Shopify Product node.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Associated Shopify Variant nodes.
   */
  public function getVariants(NodeInterface $product): array {
    if ($product->bundle() !== 'shopify_product') {
      return [];
    }

    return $this->entityTypeManager
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'shopify_variant',
        'field_parent_product' => $product->id(),
      ]);
  }

  /**
   * Returns a price and availability summary.
   */
  public function getSummary(NodeInterface $product): array {
    $variants = $this->getVariants($product);

    $prices = [];
    $available = FALSE;

    foreach ($variants as $variant) {
      $price = $variant->get('field_variant_price')->value;

      if ($price === NULL || $price === '') {
        continue;
      }

      $prices[] = (float) $price;

      if ((bool) $variant->get('field_variant_available')->value) {
        $available = TRUE;
      }
    }

    return [
      'price' => $prices
        ? (count(array_unique($prices)) > 1 ? 'From ' : '')
          . '$' . number_format(min($prices), 2)
        : '',
      'available' => $available,
      'variants' => $variants,
    ];
  }

}
