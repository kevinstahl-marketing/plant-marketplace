<?php

namespace Drupal\marketplace_catalog\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\shopify_connector\Service\ShopifyAdminApiClient;

/**
 * Imports Shopify products and variants into Drupal.
 *
 * Shopify remains the source of truth for commerce data.
 */
final class ShopifyProductImporter
{

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ShopifyAdminApiClient $shopify,
  ) {}

  /**
   * Fetch a small batch of products from Shopify.
   */
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

  /**
   * Fetch one page of Shopify products using cursor pagination.
   *
   * Returns products and pagination information.
   */
  public function fetchPage(int $limit = 25, ?string $cursor = NULL): array
  {
    $limit = max(1, min(50, $limit));

    $query = <<<'GRAPHQL'
query MarketplaceProductPage($first: Int!, $after: String) {
  products(first: $first, after: $after) {
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
        pageInfo {
          hasNextPage
          endCursor
        }
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}
GRAPHQL;

    $data = $this->shopify->query($query, [
      'first' => $limit,
      'after' => $cursor,
    ]);

    $connection = $data['products'] ?? [];

    return [
      'products' => $connection['nodes'] ?? [],
      'has_next_page' => $connection['pageInfo']['hasNextPage'] ?? FALSE,
      'end_cursor' => $connection['pageInfo']['endCursor'] ?? NULL,
    ];
  }

  /**
   * Import products and their variants.
   */
  public function import(int $limit = 5): array
  {
    return $this->importProducts($this->preview($limit));
  }

  /**
   * Import a batch of products already fetched from Shopify.
   */
  public function importProducts(array $products): array
  {
    $storage = $this->entityTypeManager->getStorage('node');

    $results = [
      'created' => 0,
      'updated' => 0,
      'failed' => 0,
      'variants_created' => 0,
      'variants_updated' => 0,
      'variants_failed' => 0,
    ];

    foreach ($products as $product) {
      try {
        $shopifyId = $product['id'];

        // Find an existing product by its Shopify ID.
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

          $node->setUnpublished();
          $action = 'created';
        }

        // Update only Shopify-controlled fields.
        $node->setTitle($product['title']);
        $node->set('field_shopify_handle', $product['handle'] ?? '');
        $node->set('field_vendor', $product['vendor'] ?? '');
        $node->set('field_product_type', $product['productType'] ?? '');
        $node->set('field_shopify_status', $product['status'] ?? '');

        $node->set('field_description', [
          'value' => $product['descriptionHtml'] ?? '',
        ]);

        $node->set(
          'field_image_url',
          $product['featuredImage']['url'] ?? ''
        );

        $node->set('field_last_synced', time());

        $node->save();
        $results[$action]++;

        // Sync variants using the saved parent product ID.
        $variantResults = $this->syncVariants(
          $product,
          (int) $node->id()
        );

        $results['variants_created'] += $variantResults['created'];
        $results['variants_updated'] += $variantResults['updated'];
        $results['variants_failed'] += $variantResults['failed'];
      } catch (\Throwable $exception) {
        $results['failed']++;

        \Drupal::logger('marketplace_catalog')->error(
          'Product import failed for @id: @message',
          [
            '@id' => $product['id'] ?? 'unknown',
            '@message' => $exception->getMessage(),
          ]
        );
      }
    }

    return $results;
  }

  
  /**
   * Import all Shopify products, page by page.
   *
   * $maxPages is a safety limit for testing.
   */
  public function syncAll(int $pageSize = 25, int $maxPages = 2): array {
    $pageSize = max(1, min(50, $pageSize));
    $maxPages = max(1, min(1000, $maxPages));

    $totals = [
      'pages' => 0,
      'created' => 0,
      'updated' => 0,
      'failed' => 0,
      'variants_created' => 0,
      'variants_updated' => 0,
      'variants_failed' => 0,
      'has_more' => FALSE,
    ];

    $cursor = NULL;

    do {
      $page = $this->fetchPage($pageSize, $cursor);

      if (empty($page['products'])) {
        break;
      }

      $results = $this->importProducts($page['products']);

      foreach ($results as $key => $value) {
        $totals[$key] += $value;
      }

      $totals['pages']++;

      $hasNextPage = !empty($page['has_next_page']);
      $nextCursor = $page['end_cursor'] ?? NULL;

      if ($hasNextPage && (!$nextCursor || $nextCursor === $cursor)) {
        throw new \RuntimeException(
          'Shopify pagination returned an invalid or repeated cursor.'
        );
      }

      $cursor = $nextCursor;

    } while ($hasNextPage && $totals['pages'] < $maxPages);

    $totals['has_more'] = $hasNextPage ?? FALSE;

    return $totals;
  }

  /**
   * Create or update variants belonging to a product.
   */
  private function syncVariants(array $product, int $parentNodeId): array
  {
    $storage = $this->entityTypeManager->getStorage('node');

    $results = [
      'created' => 0,
      'updated' => 0,
      'failed' => 0,
    ];

    foreach ($product['variants']['nodes'] ?? [] as $variant) {
      try {
        $variantId = $variant['id'];

        // Match variants using their unique Shopify IDs.
        $existing = $storage->loadByProperties([
          'type' => 'shopify_variant',
          'field_variant_shopify_id' => $variantId,
        ]);

        if ($existing) {
          $node = reset($existing);
          $action = 'updated';
        } else {
          $node = $storage->create([
            'type' => 'shopify_variant',
            'field_variant_shopify_id' => $variantId,
          ]);

          $node->setUnpublished();
          $action = 'created';
        }

        $node->setTitle(
          $product['title'] . ' — ' . ($variant['title'] ?? 'Default')
        );

        // Reference the parent Drupal product.
        $node->set('field_parent_product', [
          'target_id' => $parentNodeId,
        ]);

        // Shopify-controlled variant data.
        $node->set('field_variant_sku', $variant['sku'] ?? '');

        $node->set(
          'field_variant_price',
          $variant['price'] ?? NULL
        );

        $node->set(
          'field_variant_compare_price',
          $variant['compareAtPrice'] ?? NULL
        );

        $node->set(
          'field_variant_available',
          !empty($variant['availableForSale']) ? 1 : 0
        );

        $node->set(
          'field_variant_inventory',
          $variant['inventoryQuantity'] ?? NULL
        );

        $node->set(
          'field_variant_options',
          json_encode(
            $variant['selectedOptions'] ?? [],
            JSON_THROW_ON_ERROR
          )
        );

        $node->save();
        $results[$action]++;
      } catch (\Throwable $exception) {
        $results['failed']++;

        \Drupal::logger('marketplace_catalog')->error(
          'Variant import failed for @id: @message',
          [
            '@id' => $variant['id'] ?? 'unknown',
            '@message' => $exception->getMessage(),
          ]
        );
      }
    }

    return $results;
  }
}
