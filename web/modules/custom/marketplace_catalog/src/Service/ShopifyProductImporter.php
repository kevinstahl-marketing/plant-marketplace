<?php

namespace Drupal\marketplace_catalog\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\shopify_connector\Service\ShopifyAdminApiClient;

final class ShopifyProductImporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ShopifyAdminApiClient $shopify,
  ) {}

  public function preview(int $limit = 5): array {
    $limit = max(1, min(50, $limit));

    $query = <<<'GRAPHQL'
query MarketplaceProducts($first: Int!) {
  products(first: $first) {
    nodes {
      id
      title
      handle
      descriptionHtml
      vendor
      productType
      status
      tags
      featuredImage {
        url
        altText
      }
      variants(first: 50) {
        nodes {
          id
          title
          sku
          price
          compareAtPrice
          inventoryQuantity
          availableForSale
          selectedOptions {
            name
            value
          }
        }
      }
    }
  }
}
GRAPHQL;

    $data = $this->shopify->query($query, [
      'first' => $limit,
    ]);

    return $data['products']['nodes'] ?? [];
  }

}
