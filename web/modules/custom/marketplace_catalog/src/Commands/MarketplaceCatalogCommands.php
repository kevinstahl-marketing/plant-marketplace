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

}
