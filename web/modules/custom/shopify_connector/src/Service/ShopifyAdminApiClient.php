<?php

namespace Drupal\shopify_connector\Service;

use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;

final class ShopifyAdminApiClient {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly StateInterface $state,
  ) {}

  private function credentials(): array {
    $credentials = $this->state->get(
      'shopify_connector.oauth_credentials',
      []
    );

    if (empty($credentials['access_token']) || empty($credentials['shop'])) {
      throw new \RuntimeException('Shopify is not connected.');
    }

    return $credentials;
  }

  private function decrypt(string $encrypted): string {
    $raw = base64_decode($encrypted, TRUE);

    if ($raw === FALSE || strlen($raw) < 29) {
      throw new \RuntimeException('Invalid encrypted token.');
    }

    $key = hash('sha256', Settings::getHashSalt(), TRUE);

    $token = openssl_decrypt(
      substr($raw, 28),
      'aes-256-gcm',
      $key,
      OPENSSL_RAW_DATA,
      substr($raw, 0, 12),
      substr($raw, 12, 16)
    );

    if ($token === FALSE) {
      throw new \RuntimeException('Unable to decrypt Shopify token.');
    }

    return $token;
  }

  public function query(string $graphql, array $variables = []): array {
    $credentials = $this->credentials();

    if (time() >= (int) ($credentials['access_expires_at'] ?? 0)) {
      throw new \RuntimeException(
        'Shopify access token expired. Refresh support is not implemented yet.'
      );
    }

    $response = $this->httpClient->post(
      'https://' . $credentials['shop'] . '/admin/api/2026-10/graphql.json',
      [
        'headers' => [
          'X-Shopify-Access-Token' => $this->decrypt($credentials['access_token']),
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'query' => $graphql,
          'variables' => (object) $variables,
        ],
        'timeout' => 20,
      ]
    );

    $result = json_decode((string) $response->getBody(), TRUE);

    if (!is_array($result) || !empty($result['errors'])) {
      throw new \RuntimeException(
        'Shopify GraphQL request failed: ' .
        json_encode($result['errors'] ?? 'Invalid response')
      );
    }

    return $result['data'] ?? [];
  }

}
