<?php

namespace Drupal\marketplace_catalog\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\shopify_connector\Service\ShopifyAdminApiClient;

final class ShopifyProductImporter
{

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ShopifyAdminApiClient $shopify,
  ) {}

  public function preview(int $limit = 5): array
  {
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

  public function import(int $limit = 5): array
  {
    $products = $this->preview($limit);

    $storage = $this->entityTypeManager->getStorage('node');

    $results = [
      'created' => 0,
      'updated' => 0,
      'failed' => 0,
    ];

    foreach ($products as $product) {
      try {
        $shopifyId = $product['id'];

        // Find an existing Drupal node by Shopify ID.
        $existing = $storage->loadByProperties([
          'type' => 'shopify_product',
          'field_shopify_id' => $shopifyId,
        ]);

        if ($existing) {
          $node = reset($existing);
          $action = 'updated';
        } else {
          $node = $storage->create([
            'type' => 'shopify_product',
            'field_shopify_id' => $shopifyId,
          ]);
          $action = 'created';
        }

        // Only update Shopify-controlled fields.
        $node->setTitle($product['title']);
        $node->set('field_shopify_handle', $product['handle']);
        $node->set('field_vendor', $product['vendor']);
        $node->set('field_product_type', $product['productType']);
        $node->set('field_shopify_status', $product['status']);
        $node->set('field_description', [
          'value' => $product['descriptionHtml'] ?? '',
        ]);
        $node->set(
          'field_image_url',
          $product['featuredImage']['url'] ?? ''
        );
        $node->set('field_last_synced', time());

        // Keep imported products unpublished until reviewed.
        if ($action === 'created') {
          $node->setUnpublished();
        }

        $node->save();
        $results[$action]++;
      } catch (\Throwable $exception) {
        $results['failed']++;
        \Drupal::logger('marketplace_catalog')->error(
          'Failed importing product @id: @message',
          [
            '@id' => $product['id'] ?? 'unknown',
            '@message' => $exception->getMessage(),
          ]
        );
      }
    }

    return $results;
  }
}
