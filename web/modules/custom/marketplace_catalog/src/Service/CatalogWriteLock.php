<?php

namespace Drupal\marketplace_catalog\Service;

use Drupal\Core\Lock\LockBackendInterface;

final class CatalogWriteLock {

  private const LOCK_NAME = 'marketplace_catalog.catalog_write';

  public function __construct(
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Execute a catalog write operation under a shared lock.
   */
  public function run(callable $operation): mixed {
    if (!$this->lock->acquire(self::LOCK_NAME, 600.0)) {
      throw new \RuntimeException(
        'Another catalog write operation is running. Retry shortly.'
      );
    }

    try {
      return $operation();
    }
    finally {
      $this->lock->release(self::LOCK_NAME);
    }
  }

}
