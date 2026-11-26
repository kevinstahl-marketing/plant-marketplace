<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Defines database constraints for auction settlements.
 */
final class AuctionSettlementStorageSchema extends SqlContentEntityStorageSchema {

  protected function getEntitySchema(
    ContentEntityTypeInterface $entity_type,
    $reset = FALSE,
  ) {
    $schema = parent::getEntitySchema($entity_type, $reset);

    $table = $entity_type->getBaseTable();

    $schema[$table]['unique keys']['unique_auction'] = [
      'auction_id',
    ];

    return $schema;
  }

}
