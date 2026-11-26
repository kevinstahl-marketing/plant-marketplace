<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\ValueObject;

/**
 * Represents validated auction package specifications.
 */
final readonly class ShippingSpecification {

  public function __construct(
    public int $weightGrams,
    public int $lengthMm,
    public int $widthMm,
    public int $heightMm,
    public int $handlingDays,
  ) {
    if ($weightGrams < 1 || $weightGrams > 50000) {
      throw new \DomainException('Invalid package weight.');
    }

    foreach ([$lengthMm, $widthMm, $heightMm] as $dimension) {
      if ($dimension < 1 || $dimension > 3000) {
        throw new \DomainException('Invalid package dimensions.');
      }
    }

    if ($handlingDays < 1 || $handlingDays > 30) {
      throw new \DomainException('Invalid handling time.');
    }
  }

  /**
   * Converts seller-facing imperial measurements to metric units.
   */
  public static function fromImperial(
    float $pounds,
    float $ounces,
    float $length,
    float $width,
    float $height,
    int $handlingDays,
  ): self {
    foreach ([$pounds, $ounces, $length, $width, $height] as $value) {
      if (!is_finite($value)) {
        throw new \DomainException('Invalid package measurement.');
      }
    }

    if ($pounds < 0 || $pounds > 110
      || $ounces < 0 || $ounces >= 16
      || $length <= 0 || $length > 118
      || $width <= 0 || $width > 118
      || $height <= 0 || $height > 118) {
      throw new \DomainException('Package measurements are outside the allowed range.');
    }

    return new self(
      (int) ceil(($pounds * 16 + $ounces) * 28.349523125),
      (int) ceil($length * 25.4),
      (int) ceil($width * 25.4),
      (int) ceil($height * 25.4),
      $handlingDays,
    );
  }

  /**
   * Returns values suitable for auction entity creation.
   */
  public function toFieldValues(): array {
    return [
      'package_weight_g' => $this->weightGrams,
      'package_length_mm' => $this->lengthMm,
      'package_width_mm' => $this->widthMm,
      'package_height_mm' => $this->heightMm,
      'handling_days' => $this->handlingDays,
    ];
  }

}
