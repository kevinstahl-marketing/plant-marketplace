<?php

namespace Drupal\shopify_connector\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ShopifyOAuthCallbackController extends ControllerBase {

  public function callback(Request $request): Response {
    $shop = (string) $request->query->get('shop', '');
    $code = (string) $request->query->get('code', '');
    $state = (string) $request->query->get('state', '');
    $hmac = (string) $request->query->get('hmac', '');

    $expectedShop = getenv('SHOPIFY_STORE_DOMAIN');
    $clientId = getenv('SHOPIFY_CLIENT_ID');
    $clientSecret = getenv('SHOPIFY_CLIENT_SECRET');

    // Only accept the configured Shopify store.
    if (
      !$expectedShop ||
      !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*\.myshopify\.com$/D', $shop) ||
      !hash_equals(strtolower($expectedShop), strtolower($shop))
    ) {
      return new Response('Invalid Shopify store.', 403);
    }

    // Verify the OAuth state from our authorization controller.
    $tempstore = \Drupal::service('tempstore.private')
      ->get('shopify_connector');

    $expectedState = $tempstore->get('oauth_state');

    if (
      !is_string($expectedState) ||
      !$state ||
      !hash_equals($expectedState, $state)
    ) {
      return new Response('Invalid OAuth state.', 403);
    }

    // Verify Shopify's HMAC signature.
    $params = $request->query->all();
    unset($params['hmac']);

    ksort($params);

    $message = http_build_query(
      $params,
      '',
      '&',
      PHP_QUERY_RFC3986
    );

    $calculatedHmac = hash_hmac(
      'sha256',
      $message,
      $clientSecret
    );

    if (
      !$hmac ||
      !hash_equals($calculatedHmac, $hmac)
    ) {
      return new Response('Invalid Shopify HMAC.', 403);
    }

    if (!$code || !$clientId || !$clientSecret) {
      return new Response('Missing OAuth credentials.', 400);
    }

    // Exchange the authorization code for an offline token.
    try {
      $response = \Drupal::httpClient()->post(
        "https://{$shop}/admin/oauth/access_token",
        [
          'form_params' => [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'expiring' => '1',
          ],
          'timeout' => 15,
        ]
      );

      $data = json_decode(
        (string) $response->getBody(),
        TRUE
      );

      if (
        !is_array($data) ||
        empty($data['access_token']) ||
        empty($data['refresh_token'])
      ) {
        throw new \RuntimeException('Invalid token response.');
      }

      // Encrypt tokens before saving them to Drupal State.
      $key = hash(
        'sha256',
        Settings::getHashSalt(),
        TRUE
      );

      $encrypt = static function (string $value) use ($key): string {
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
          $value,
          'aes-256-gcm',
          $key,
          OPENSSL_RAW_DATA,
          $iv,
          $tag
        );

        if ($ciphertext === FALSE) {
          throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
      };

      \Drupal::state()->set(
        'shopify_connector.oauth_credentials',
        [
          'shop' => $shop,
          'access_token' => $encrypt($data['access_token']),
          'refresh_token' => $encrypt($data['refresh_token']),
          'access_expires_at' => time() + (int) ($data['expires_in'] ?? 3600),
          'refresh_expires_at' => time() + (int) ($data['refresh_token_expires_in'] ?? 7776000),
          'scope' => $data['scope'] ?? '',
        ]
      );

      // OAuth state is single-use.
      $tempstore->delete('oauth_state');

      return new Response(
        'Shopify authorization successful. Offline credentials saved.',
        200
      );
    }
    catch (\Throwable $exception) {
      \Drupal::logger('shopify_connector')->error(
        'Shopify OAuth exchange failed: @error',
        ['@error' => $exception->getMessage()]
      );

      return new Response(
        'Shopify authorization failed. Check Drupal logs.',
        500
      );
    }
  }

}
