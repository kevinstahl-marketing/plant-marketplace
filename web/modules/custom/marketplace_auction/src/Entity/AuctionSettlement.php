<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[ContentEntityType(
  id: 'marketplace_auction_settlement',
  label: new TranslatableMarkup('Auction settlement'),
  label_collection: new TranslatableMarkup('Auction settlements'),
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
    'storage_schema' => 'Drupal\marketplace_auction\AuctionSettlementStorageSchema',
  ],
  base_table: 'marketplace_auction_settlement',
  admin_permission: 'administer marketplace auctions',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
)]
final class AuctionSettlement extends ContentEntityBase {

  public const OUTCOME_SOLD = 'sold';
  public const OUTCOME_UNSOLD = 'unsold';

  public static function baseFieldDefinitions(
    EntityTypeInterface $entity_type,
  ): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['auction_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Auction'))
      ->setSetting('target_type', 'marketplace_auction')
      ->setRequired(TRUE);

    $fields['seller_profile_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Seller profile'))
      ->setSetting('target_type', 'marketplace_seller')
      ->setRequired(TRUE);

    $fields['winner_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Winning buyer'))
      ->setSetting('target_type', 'user');

    $fields['winning_bid_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Winning bid'))
      ->setSetting('target_type', 'marketplace_bid');

    $fields['final_price'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Final sale price (cents)'))
      ->setSetting('min', 0)
      ->setDefaultValue(0)
      ->setRequired(TRUE);

    $fields['currency'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Currency'))
      ->setSetting('max_length', 3)
      ->setRequired(TRUE);

    $fields['outcome'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Settlement outcome'))
      ->setSetting('allowed_values', [
        self::OUTCOME_SOLD => 'Sold',
        self::OUTCOME_UNSOLD => 'Unsold',
      ])
      ->setRequired(TRUE);

    $fields['settled_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Settlement time'))
      ->setRequired(TRUE);

    return $fields;
  }

  /**
   * Prevents settlement records from being rewritten.
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if (!$this->isNew()) {
      throw new \DomainException(
        'Auction settlement records cannot be modified.'
      );
    }

    if ((int) $this->get('auction_id')->target_id <= 0) {
      throw new \DomainException(
        'A settlement requires a valid auction ID.'
      );
    }
  }

  public function isSold(): bool {
    return $this->get('outcome')->value === self::OUTCOME_SOLD;
  }

}
