<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[ContentEntityType(
  id: 'marketplace_bid',
  label: new TranslatableMarkup('Auction bid'),
  label_collection: new TranslatableMarkup('Auction bids'),
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
  ],
  base_table: 'marketplace_bid',
  admin_permission: 'administer marketplace auctions',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
)]
class Bid extends ContentEntityBase {

  public static function baseFieldDefinitions(
    EntityTypeInterface $entity_type
  ): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['auction_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Auction'))
      ->setSetting('target_type', 'marketplace_auction')
      ->setRequired(TRUE);

    $fields['bidder_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Bidder'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);

    $fields['amount'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Bid amount (cents)'))
      ->setSetting('min', 1)
      ->setRequired(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Placed at'));

    return $fields;
  }

}
