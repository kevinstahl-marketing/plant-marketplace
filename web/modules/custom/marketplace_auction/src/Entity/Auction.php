<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[ContentEntityType(
  id: 'marketplace_auction',
  label: new TranslatableMarkup('Auction'),
  label_collection: new TranslatableMarkup('Auctions'),
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
  ],
  base_table: 'marketplace_auction',
  admin_permission: 'administer marketplace auctions',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'title',
  ],
)]
class Auction extends ContentEntityBase {

  public const STATUS_DRAFT = 'draft';
  public const STATUS_SCHEDULED = 'scheduled';
  public const STATUS_OPEN = 'open';
  public const STATUS_CLOSED = 'closed';
  public const STATUS_CANCELLED = 'cancelled';

  public static function baseFieldDefinitions(
    EntityTypeInterface $entity_type
  ): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['seller_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Seller'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);

    $fields['seller_profile_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Seller profile'))
      ->setSetting('target_type', 'marketplace_seller');

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Description'));

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Auction status'))
      ->setSetting('allowed_values', [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SCHEDULED => 'Scheduled',
        self::STATUS_OPEN => 'Open',
        self::STATUS_CLOSED => 'Closed',
        self::STATUS_CANCELLED => 'Cancelled',
      ])
      ->setDefaultValue(self::STATUS_DRAFT)
      ->setRequired(TRUE);

    $fields['currency'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Currency'))
      ->setSetting('max_length', 3)
      ->setDefaultValue('USD')
      ->setRequired(TRUE);

    $fields['starting_price'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Starting price (cents)'))
      ->setSetting('min', 1)
      ->setRequired(TRUE);

    $fields['current_price'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Current price (cents)'))
      ->setSetting('min', 0)
      ->setDefaultValue(0)
      ->setRequired(TRUE);

    $fields['bid_increment'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Minimum bid increment (cents)'))
      ->setSetting('min', 1)
      ->setDefaultValue(100)
      ->setRequired(TRUE);

    $fields['bid_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Bid count'))
      ->setSetting('min', 0)
      ->setDefaultValue(0);

    $fields['leading_bid_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Leading bid'))
      ->setSetting('target_type', 'marketplace_bid');

    $fields['starts_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Start time'))
      ->setRequired(TRUE);

    $fields['ends_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('End time'))
      ->setRequired(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

  public function isOpen(int $now): bool {
    return $this->get('status')->value === self::STATUS_OPEN
      && $now >= (int) $this->get('starts_at')->value
      && $now < (int) $this->get('ends_at')->value;
  }

  public function getMinimumBid(): int {
    if ((int) $this->get('bid_count')->value === 0) {
      return (int) $this->get('starting_price')->value;
    }

    return (int) $this->get('current_price')->value
      + (int) $this->get('bid_increment')->value;
  }

  public function getSellerProfile(): ?\Drupal\marketplace_seller\Entity\Seller {
    $seller = $this->get('seller_profile_id')->entity;

    return $seller instanceof \Drupal\marketplace_seller\Entity\Seller
      ? $seller
      : NULL;
  }

  public function getSellerId(): int {
    return $this->getSellerProfile()?->getUserId()
      ?? (int) $this->get('seller_id')->target_id;
  }

  public function hasEligibleSeller(): bool {
    $seller = $this->getSellerProfile();

    if (!$seller || !$seller->isActive()) {
      return FALSE;
    }

    $account = $seller->get('user_id')->entity;

    return $seller->getUserId() > 0
      && $seller->getUserId() === (int) $this->get('seller_id')->target_id
      && $account
      && $account->isActive();
  }

}
