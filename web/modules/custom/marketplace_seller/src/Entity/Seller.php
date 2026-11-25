<?php

declare(strict_types=1);

namespace Drupal\marketplace_seller\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[ContentEntityType(
  id: 'marketplace_seller',
  label: new TranslatableMarkup('Marketplace seller'),
  label_collection: new TranslatableMarkup('Marketplace sellers'),
  handlers: [
    'views_data' => 'Drupal\views\EntityViewsData',
  ],
  base_table: 'marketplace_seller',
  admin_permission: 'administer marketplace sellers',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
  ],
)]
class Seller extends ContentEntityBase {

  public const STATUS_PENDING = 'pending';
  public const STATUS_ACTIVE = 'active';
  public const STATUS_SUSPENDED = 'suspended';

  public static function baseFieldDefinitions(
    EntityTypeInterface $entity_type
  ): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Account owner'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Store name'))
      ->setSetting('max_length', 255)
      ->setRequired(TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Seller biography'));

    $fields['location'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Public location'))
      ->setSetting('max_length', 255);

    $fields['shipping_policy'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('Shipping policy'));

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Seller status'))
      ->setSetting('allowed_values', [
        self::STATUS_PENDING => 'Pending approval',
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_SUSPENDED => 'Suspended',
      ])
      ->setDefaultValue(self::STATUS_PENDING)
      ->setRequired(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

  public function getUserId(): int {
    return (int) $this->get('user_id')->target_id;
  }

  public function isActive(): bool {
    return $this->get('status')->value === self::STATUS_ACTIVE;
  }

}
