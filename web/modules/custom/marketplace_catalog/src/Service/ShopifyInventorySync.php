<?php

namespace Drupal\marketplace_catalog\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\shopify_connector\Service\ShopifyAdminApiClient;

final class ShopifyInventorySync {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ShopifyAdminApiClient $shopifyApi,
  ) {}

  /**
   * Synchronize existing Drupal variants from Shopify.
   *
   * Does not create or delete nodes.
   */
  public function sync(int $pageSize = 50, int $maxPages = 100): array {
    $pageSize = max(1, min(100, $pageSize));
    $maxPages = max(1, min(1000, $maxPages));

    $lock = \Drupal::lock();
    $lockName = 'marketplace_catalog.inventory_sync';

    if (!$lock->acquire($lockName, 600.0)) {
      throw new \RuntimeException('Inventory sync already running.');
    }

    try {
      return \Drupal::service('marketplace_catalog.write_lock')->run(
        fn() => $this->runSync($pageSize, $maxPages)
      );
    }
    finally {
      $lock->release($lockName);
    }
  }

  private function runSync(int $pageSize, int $maxPages): array {
    $query = <<<'GRAPHQL'
query InventorySync($first: Int!, $after: String) {
  productVariants(first: $first, after: $after) {
    nodes {
      id
      price
      compareAtPrice
      inventoryQuantity
      inventoryPolicy
      inventoryItem {
        tracked
      }
      product {
        id
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}
GRAPHQL;

    $storage = $this->entityTypeManager->getStorage('node');

    $totals = [
      'scanned' => 0,
      'updated' => 0,
      'unchanged' => 0,
      'missing_local' => 0,
      'failed' => 0,
      'pages' => 0,
      'complete' => FALSE,
    ];

    $cursor = NULL;

    do {
      $response = $this->shopifyApi->query($query, [
        'first' => $pageSize,
        'after' => $cursor,
      ]);

      $connection = $response['productVariants'] ?? NULL;

      if (!is_array($connection)) {
        throw new \RuntimeException('Invalid Shopify variants response.');
      }

      foreach ($connection['nodes'] ?? [] as $variant) {
        $totals['scanned']++;

        try {
          $existing = $storage->loadByProperties([
            'type' => 'shopify_variant',
            'field_variant_shopify_id' => $variant['id'],
          ]);

          if (!$existing) {
            $totals['missing_local']++;
            continue;
          }

          $node = reset($existing);

          $quantity = $variant['inventoryQuantity'];
          $price = $variant['price'];
          $comparePrice = $variant['compareAtPrice'];

          // CONTINUE permits checkout even when quantity is zero.
          $tracked = $variant['inventoryItem']['tracked'] ?? NULL;

          if ($tracked === NULL) {
            throw new \RuntimeException(
              'Inventory tracking status missing from Shopify response.'
            );
          }

          $available = (
            !$tracked ||
            ($quantity !== NULL && $quantity > 0) ||
            ($variant['inventoryPolicy'] ?? '') === 'CONTINUE'
          );

          $changes = [
            'field_variant_price' => $price,
            'field_variant_compare_price' => $comparePrice,
            'field_variant_inventory' => $quantity,
            'field_variant_available' => $available ? 1 : 0,
          ];

          $dirty = FALSE;

          foreach ($changes as $field => $value) {
            if (!$node->hasField($field)) {
              throw new \RuntimeException("Missing field: {$field}");
            }

            $current = $node->get($field)->value;

            if ((string) ($current ?? '') !== (string) ($value ?? '')) {
              $node->set($field, $value);
              $dirty = TRUE;
            }
          }

          if ($dirty) {
            $node->save();
            $totals['updated']++;
          }
          else {
            $totals['unchanged']++;
          }
        }
        catch (\Throwable $e) {
          $totals['failed']++;
          \Drupal::logger('marketplace_catalog')->error(
            'Inventory sync failed for @id: @message',
            [
              '@id' => $variant['id'] ?? 'unknown',
              '@message' => $e->getMessage(),
            ]
          );
        }
      }

      $totals['pages']++;

      $pageInfo = $connection['pageInfo'] ?? [];
      $hasNext = !empty($pageInfo['hasNextPage']);
      $nextCursor = $pageInfo['endCursor'] ?? NULL;

      if ($hasNext && (!$nextCursor || $nextCursor === $cursor)) {
        throw new \RuntimeException('Invalid inventory pagination cursor.');
      }

      $cursor = $nextCursor;

    } while ($hasNext && $totals['pages'] < $maxPages);

    $totals['complete'] = !$hasNext;

    return $totals;
  }

}
