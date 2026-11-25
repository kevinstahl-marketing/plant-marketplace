<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\file\Validation\FileValidatorInterface;

/**
 * Manages photographs associated with marketplace auctions.
 */
final class AuctionMediaManager {

  public const MAX_IMAGES = 8;
  public const MAX_FILE_SIZE = 10485760;
  public const UPLOAD_LOCATION = 'public://auction-uploads/';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileValidatorInterface $fileValidator,
  ) {}

  /**
   * Returns the supported file validation constraints.
   */
  public static function getUploadValidators(): array {
    return [
      'FileExtension' => [
        'extensions' => 'jpg jpeg png webp',
      ],
      'FileSizeLimit' => [
        'fileLimit' => self::MAX_FILE_SIZE,
      ],
      'FileIsImage' => [],
    ];
  }

  /**
   * Validates a seller-owned temporary upload.
   */
  public function validatePhoto(
    int $userId,
    int $fileId,
    string $alt,
  ): FileInterface {

    $file = $this->entityTypeManager
      ->getStorage('file')
      ->load($fileId);

    if (!$file instanceof FileInterface
      || (int) $file->getOwnerId() !== $userId
      || !$file->isTemporary()
      || !str_starts_with(
        $file->getFileUri(),
        self::UPLOAD_LOCATION
      )) {
      throw new \DomainException(
        'Invalid or unauthorized photograph.'
      );
    }

    $alt = trim($alt);

    if ($alt === '' || mb_strlen($alt) > 255) {
      throw new \DomainException(
        'Each photograph requires alternative text.'
      );
    }

    $violations = $this->fileValidator->validate(
      $file,
      self::getUploadValidators()
    );

    if ($violations->count()) {
      throw new \DomainException(
        'The photograph does not meet upload requirements.'
      );
    }

    return $file;
  }

  /**
   * Creates image media entities from validated uploads.
   */
  public function createImages(
    int $userId,
    array $photos,
  ): array {

    if (count($photos) < 1
      || count($photos) > self::MAX_IMAGES) {
      throw new \DomainException(
        'Upload between one and eight photographs.'
      );
    }

    $validated = [];
    $seen = [];

    foreach ($photos as $photo) {
      $fileId = (int) $photo['fid'];
      $alt = trim((string) $photo['alt']);

      if (isset($seen[$fileId])) {
        throw new \DomainException(
          'Duplicate photographs are not allowed.'
        );
      }

      $seen[$fileId] = TRUE;

      $validated[] = [
        'file' => $this->validatePhoto(
          $userId,
          $fileId,
          $alt
        ),
        'alt' => $alt,
      ];
    }

    $references = [];

    foreach ($validated as $photo) {
      $file = $photo['file'];

      $media = $this->entityTypeManager
        ->getStorage('media')
        ->create([
          'bundle' => 'image',
          'uid' => $userId,
          'name' => $file->getFilename(),
          'status' => 1,
          'field_media_image' => [
            'target_id' => $file->id(),
            'alt' => $photo['alt'],
          ],
        ]);

      if ($media->validate()->count()) {
        throw new \DomainException(
          'Image media validation failed.'
        );
      }

      $media->save();

      $references[] = [
        'target_id' => $media->id(),
      ];
    }

    return $references;
  }

}
