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


  /**
   * Unpublish missing Shopify products, or preview the changes.
   */
  public function reconcile(
    bool $apply = FALSE,
    int $pageSize = 25,
    int $maxPages = 100,
  ): array {
    // Audit must complete before any changes are allowed.
    $audit = $this->audit($pageSize, $maxPages);

    $result = [
      'scanned' => $audit['scanned'],
      'pages' => $audit['pages'],
      'missing' => $audit['missing'],
      'unpublished' => 0,
      'already_unpublished' => 0,
      'dry_run' => !$apply,
    ];

    if (!$apply || !$audit['missing']) {
      return $result;
    }

    $storage = $this->entityTypeManager->getStorage('node');

    foreach ($audit['missing'] as $product) {
      $node = $storage->load($product['nid']);

      if (!$node || $node->bundle() !== 'shopify_product') {
        continue;
      }

      // Recheck the Shopify ID before modifying the node.
      if ($node->get('field_shopify_id')->value !== $product['shopify_id']) {
        throw new \RuntimeException(
          'Shopify ID changed during reconciliation. Aborting.'
        );
      }

      if (!$node->isPublished()) {
        $result['already_unpublished']++;
        continue;
      }

      $node->setUnpublished();
      $node->save();
      $result['unpublished']++;
    }

    return $result;
  }

}
