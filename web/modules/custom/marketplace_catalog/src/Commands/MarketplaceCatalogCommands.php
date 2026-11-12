<?php

namespace Drupal\marketplace_catalog\Commands;

use Drush\Commands\DrushCommands;
use Drupal\marketplace_catalog\Service\ShopifyProductImporter;

/**
 * Marketplace catalog CLI commands.
 */
final class MarketplaceCatalogCommands extends DrushCommands {

  public function __construct(
    protected ShopifyProductImporter $importer,
  ) {
    parent::__construct();
  }

  /**
   * Synchronize Shopify products into Drupal.
   *
   * @command marketplace:sync
   * @aliases marketplace-sync
   * @option page-size Products to fetch per page.
   * @option max-pages Maximum pages to import.
   * @usage marketplace:sync --page-size=5 --max-pages=2
   *   Import up to ten products for testing.
   */
  public function sync(
    array $options = [
      'page-size' => 25,
      'max-pages' => 2,
    ],
  ): void {
    $pageSize = (int) $options['page-size'];
    $maxPages = (int) $options['max-pages'];

    $this->io()->title('Shopify → Drupal Catalog Sync');

    $this->io()->text(sprintf(
      'Importing up to %d pages of %d products...',
      $maxPages,
      $pageSize,
    ));

    $results = $this->importer->syncAll($pageSize, $maxPages);

    $this->io()->table(
      ['Metric', 'Count'],
      [
        ['Pages processed', $results['pages']],
        ['Products created', $results['created']],
        ['Products updated', $results['updated']],
        ['Products failed', $results['failed']],
        ['Variants created', $results['variants_created']],
        ['Variants updated', $results['variants_updated']],
        ['Variants failed', $results['variants_failed']],
      ],
    );

    if ($results['has_more']) {
      $this->io()->warning('Page limit reached. More Shopify products remain.');
    }
    else {
      $this->io()->text('Reached the end of the Shopify product catalog.');
    }

    if ($results['failed'] || $results['variants_failed']) {
      throw new \RuntimeException(
        'Catalog synchronization finished with record failures.'
      );
    }

    $this->io()->success(
      $results['has_more']
        ? 'Batch synchronized successfully.'
        : 'Full product pagination completed successfully.'
    );
  }

  /**
   * Synchronize Shopify products changed since the last checkpoint.
   *
   * @command marketplace:sync-incremental
   * @option page-size Products to fetch per page.
   * @option max-pages Maximum pages to process.
   * @usage marketplace:sync-incremental --page-size=25 --max-pages=100
   *   Synchronize changed Shopify products.
   */
  public function syncIncremental(
    array $options = [
      'page-size' => 25,
      'max-pages' => 100,
    ],
  ): void {
    $results = $this->importer->syncIncremental(
      (int) $options['page-size'],
      (int) $options['max-pages'],
    );

    $this->io()->table(
      ['Metric', 'Count'],
      [
        ['Pages', $results['pages']],
        ['Products created', $results['created']],
        ['Products updated', $results['updated']],
        ['Products failed', $results['failed']],
        ['Variants created', $results['variants_created']],
        ['Variants updated', $results['variants_updated']],
        ['Variants failed', $results['variants_failed']],
      ],
    );

    if ($results['failed'] || $results['variants_failed']) {
      throw new \RuntimeException(
        'Incremental synchronization failed. Checkpoint unchanged.'
      );
    }

    if ($results['has_more']) {
      $this->io()->warning(
        'Page limit reached. Checkpoint unchanged.'
      );
      return;
    }

    if ($results['checkpoint_advanced']) {
      $this->io()->success(
        'Incremental synchronization complete. Checkpoint saved.'
      );
    }
  }


  /**
   * Audit Drupal products missing from the Shopify catalog.
   *
   * @command marketplace:audit-missing
   * @option page-size Shopify products per page.
   * @option max-pages Maximum pages to scan.
   */
  public function auditMissingProducts(
    array $options = [
      'page-size' => 25,
      'max-pages' => 100,
    ],
  ): void {
    $pageSize = max(1, min(50, (int) $options['page-size']));
    $maxPages = max(1, min(1000, (int) $options['max-pages']));

    $shopifyIds = [];
    $cursor = NULL;
    $pages = 0;
    $hasMore = FALSE;

    do {
      $page = $this->importer->fetchPage($pageSize, $cursor);

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
        'Audit incomplete: page limit reached. No conclusions made.'
      );
    }

    $storage = \Drupal::entityTypeManager()->getStorage('node');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'shopify_product')
      ->execute();

    $missing = [];

    foreach ($storage->loadMultiple($ids) as $node) {
      $shopifyId = $node->get('field_shopify_id')->value;

      if ($shopifyId && !isset($shopifyIds[$shopifyId])) {
        $missing[] = [
          $node->id(),
          $node->label(),
          $shopifyId,
        ];
      }
    }

    $this->io()->title('Shopify Catalog Reconciliation Audit');
    $this->io()->text(sprintf(
      'Scanned %d Shopify products across %d pages.',
      count($shopifyIds),
      $pages
    ));

    if (!$missing) {
      $this->io()->success('No missing Drupal products detected.');
      return;
    }

    $this->io()->table(
      ['Drupal node ID', 'Product', 'Shopify ID'],
      $missing
    );

    $this->io()->warning(sprintf(
      '%d Drupal products are missing from Shopify. No nodes were modified.',
      count($missing)
    ));
  }

}
