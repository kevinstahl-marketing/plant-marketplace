<?php

namespace Drupal\marketplace_catalog\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Compares the Drupal catalog against Shopify.
 */
final class ShopifyCatalogReconciler {

  public function __construct(
    private readonly ShopifyProductImporter $importer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Find Drupal products missing from the Shopify catalog.
   *
   * Read-only. Requires a complete Shopify catalog scan.
   */
  public function audit(int $pageSize = 25, int $maxPages = 100): array {
    $pageSize = max(1, min(50, $pageSize));
    $maxPages = max(1, min(1000, $maxPages));

    $shopifyIds = [];
    $cursor = NULL;
    $pages = 0;

    do {
      $page = $this->importer->fetchPage($pageSize, $cursor);

      if (empty($page['products']) && !empty($page['has_next_page'])) {
        throw new \RuntimeException(
          'Shopify returned an empty page with more results pending.'
        );
      }

      foreach ($page['products'] as $product) {
        $shopifyIds[$product['id']] = TRUE;
      }

      $pages++;

      $hasMore = !empty($page['has_next_page']);
      $nextCursor = $page['end_cursor'] ?? NULL;

      if ($hasMore && (!$nextCursor || $nextCursor === $cursor)) {
        throw new \RuntimeException('Invalid Shopify pagination cursor.');
      }

      $cursor = $nextCursor;
    } while ($hasMore && $pages < $maxPages);

    if ($hasMore) {
      throw new \RuntimeException(
        'Audit incomplete: page limit reached.'
      );
    }

    $storage = $this->entityTypeManager->getStorage('node');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'shopify_product')
      ->execute();

    $missing = [];

    foreach ($storage->loadMultiple($ids) as $node) {
      $shopifyId = $node->get('field_shopify_id')->value;

      if ($shopifyId && !isset($shopifyIds[$shopifyId])) {
        $missing[] = [
          'nid' => (int) $node->id(),
          'title' => $node->label(),
          'shopify_id' => $shopifyId,
        ];
      }
    }

    return [
      'scanned' => count($shopifyIds),
      'pages' => $pages,
      'missing' => $missing,
    ];
  }

}
