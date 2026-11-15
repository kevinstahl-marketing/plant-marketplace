<?php

namespace Drupal\marketplace_cart\Service;

use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;

final class ShopifyStorefrontClient {

  private const API_VERSION = '2026-10';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly StateInterface $state,
  ) {}

  public function query(
    string $graphql,
    array $variables = [],
  ): array {

    $credentials = $this->state->get(
      'shopify_connector.oauth_credentials',
      []
    );

    $shop = $credentials['shop'] ?? '';

    if (!preg_match(
      '/^[a-zA-Z0-9][a-zA-Z0-9-]*\.myshopify\.com$/D',
      $shop
    )) {
      throw new \RuntimeException(
        'Shopify shop domain is not configured.'
      );
    }

    $url = sprintf(
      'https://%s/api/%s/graphql.json',
      $shop,
      self::API_VERSION
    );

    try {
      $response = $this->httpClient->post($url, [
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
        ],
        'json' => [
          'query' => $graphql,
          'variables' => (object) $variables,
        ],
        'timeout' => 20,
      ]);
    }
    catch (\GuzzleHttp\Exception\GuzzleException $e) {
      throw new \RuntimeException(
        'Shopify Storefront API request failed.',
        0,
        $e
      );
    }

    $result = json_decode(
      (string) $response->getBody(),
      TRUE
    );

    if (!is_array($result)) {
      throw new \RuntimeException(
        'Invalid Shopify Storefront API response.'
      );
    }

    if (!empty($result['errors'])) {
      throw new \RuntimeException(
        'Shopify Storefront GraphQL error: ' .
        json_encode($result['errors'])
      );
    }

    return $result['data'] ?? [];
  }

}
