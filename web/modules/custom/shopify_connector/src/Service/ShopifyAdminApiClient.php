<?php

namespace Drupal\shopify_connector\Service;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;

final class ShopifyAdminApiClient {

  private const STATE_KEY = 'shopify_connector.oauth_credentials';
  private const LOCK_KEY = 'shopify_connector.token_refresh';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly StateInterface $state,
    private readonly LockBackendInterface $lock,
  ) {}

  private function credentials(): array {
    $credentials = $this->state->get(self::STATE_KEY, []);

    if (
      empty($credentials['shop']) ||
      empty($credentials['access_token'])
    ) {
      throw new \RuntimeException('Shopify is not connected.');
    }

    if (!preg_match(
      '/^[a-zA-Z0-9][a-zA-Z0-9-]*\.myshopify\.com$/D',
      $credentials['shop']
    )) {
      throw new \RuntimeException('Invalid Shopify shop domain.');
    }

    return $credentials;
  }

  private function encryptionKey(): string {
    return hash('sha256', Settings::getHashSalt(), TRUE);
  }

  private function decrypt(string $encrypted): string {
    $raw = base64_decode($encrypted, TRUE);

    if ($raw === FALSE || strlen($raw) < 29) {
      throw new \RuntimeException('Invalid encrypted token.');
    }

    $token = openssl_decrypt(
      substr($raw, 28),
      'aes-256-gcm',
      $this->encryptionKey(),
      OPENSSL_RAW_DATA,
      substr($raw, 0, 12),
      substr($raw, 12, 16)
    );

    if ($token === FALSE) {
      throw new \RuntimeException('Token decryption failed.');
    }

    return $token;
  }

  private function encrypt(string $value): string {
    $iv = random_bytes(12);
    $tag = '';

    $ciphertext = openssl_encrypt(
      $value,
      'aes-256-gcm',
      $this->encryptionKey(),
      OPENSSL_RAW_DATA,
      $iv,
      $tag
    );

    if ($ciphertext === FALSE) {
      throw new \RuntimeException('Token encryption failed.');
    }

    return base64_encode($iv . $tag . $ciphertext);
  }

  private function refreshToken(bool $force = FALSE): array {
    if (!$this->lock->acquire(self::LOCK_KEY, 30)) {
      throw new \RuntimeException(
        'Shopify token refresh already in progress. Retry shortly.'
      );
    }

    try {
      // Re-read after acquiring the lock.
      $credentials = $this->credentials();

      if (
        !$force &&
        (int) ($credentials['access_expires_at'] ?? 0) > time() + 120
      ) {
        return $credentials;
      }

      if (empty($credentials['refresh_token'])) {
        throw new \RuntimeException(
          'Shopify refresh token missing. Reauthorization required.'
        );
      }

      if (
        !empty($credentials['refresh_expires_at']) &&
        (int) $credentials['refresh_expires_at'] <= time()
      ) {
        throw new \RuntimeException(
          'Shopify refresh token expired. Reauthorization required.'
        );
      }

      $clientId = getenv('SHOPIFY_CLIENT_ID');
      $clientSecret = getenv('SHOPIFY_CLIENT_SECRET');

      if (!$clientId || !$clientSecret) {
        throw new \RuntimeException(
          'Shopify client credentials missing.'
        );
      }

      $response = $this->httpClient->post(
        'https://' . $credentials['shop'] . '/admin/oauth/access_token',
        [
          'form_params' => [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->decrypt(
              $credentials['refresh_token']
            ),
          ],
          'headers' => [
            'Accept' => 'application/json',
          ],
          'timeout' => 20,
        ]
      );

      $data = json_decode((string) $response->getBody(), TRUE);

      if (
        !is_array($data) ||
        empty($data['access_token']) ||
        empty($data['refresh_token']) ||
        empty($data['expires_in']) ||
        empty($data['refresh_token_expires_in'])
      ) {
        throw new \RuntimeException(
          'Invalid Shopify refresh response.'
        );
      }

      $now = time();

      $credentials['access_token'] = $this->encrypt(
        $data['access_token']
      );

      $credentials['refresh_token'] = $this->encrypt(
        $data['refresh_token']
      );

      $credentials['access_expires_at'] =
        $now + (int) $data['expires_in'];

      $credentials['refresh_expires_at'] =
        $now + (int) $data['refresh_token_expires_in'];

      $credentials['scope'] =
        $data['scope'] ?? ($credentials['scope'] ?? '');

      $this->state->set(self::STATE_KEY, $credentials);

      return $credentials;
    }
    finally {
      $this->lock->release(self::LOCK_KEY);
    }
  }

  public function query(string $graphql, array $variables = []): array {
    $credentials = $this->credentials();

    if (
      (int) ($credentials['access_expires_at'] ?? 0) <= time() + 120
    ) {
      $credentials = $this->refreshToken();
    }

    for ($attempt = 0; $attempt < 2; $attempt++) {
      try {
        $response = $this->httpClient->post(
          'https://' . $credentials['shop'] .
          '/admin/api/2026-10/graphql.json',
          [
            'headers' => [
              'X-Shopify-Access-Token' => $this->decrypt(
                $credentials['access_token']
              ),
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

        if (
          !is_array($result) ||
          !empty($result['errors'])
        ) {
          throw new \RuntimeException(
            'Shopify GraphQL request failed: ' .
            json_encode($result['errors'] ?? 'Invalid response')
          );
        }

        return $result['data'] ?? [];
      }
      catch (ClientException $exception) {
        if (
          $exception->getResponse()?->getStatusCode() !== 401 ||
          $attempt !== 0
        ) {
          throw $exception;
        }

        // Another worker may already have refreshed the token.
        $latest = $this->credentials();

        if (
          $latest['access_token'] !== $credentials['access_token']
        ) {
          $credentials = $latest;
        }
        else {
          $credentials = $this->refreshToken(TRUE);
        }
      }
    }

    throw new \RuntimeException('Shopify request failed.');
  }

}
