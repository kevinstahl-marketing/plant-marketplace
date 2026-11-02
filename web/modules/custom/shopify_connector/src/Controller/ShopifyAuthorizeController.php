<?php

namespace Drupal\shopify_connector\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;

final class ShopifyAuthorizeController extends ControllerBase {

  public function authorize(): TrustedRedirectResponse {
    $shop = getenv('SHOPIFY_STORE_DOMAIN');
    $clientId = getenv('SHOPIFY_CLIENT_ID');
    $redirectUri = getenv('SHOPIFY_REDIRECT_URI');

    if (!$shop || !$clientId || !$redirectUri) {
      throw new \RuntimeException(
        'Missing Shopify OAuth environment variables.'
      );
    }

    // Validate the configured Shopify domain.
    if (!preg_match(
      '/^[a-zA-Z0-9][a-zA-Z0-9-]*\.myshopify\.com$/',
      $shop
    )) {
      throw new \RuntimeException('Invalid Shopify store domain.');
    }

    // Require an HTTPS callback URL.
    if (!filter_var($redirectUri, FILTER_VALIDATE_URL)
      || parse_url($redirectUri, PHP_URL_SCHEME) !== 'https') {
      throw new \RuntimeException('Invalid Shopify redirect URI.');
    }

    // Generate a cryptographically secure OAuth state token.
    $state = bin2hex(random_bytes(32));

    // Save state for verification in the OAuth callback.
    \Drupal::service('tempstore.private')
      ->get('shopify_connector')
      ->set('oauth_state', $state);

    // Request Shopify Admin API permissions.
    $query = http_build_query([
      'client_id' => $clientId,
      'scope' => 'read_products,read_inventory,read_locations',
      'redirect_uri' => $redirectUri,
      'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);

    return new TrustedRedirectResponse(
      "https://{$shop}/admin/oauth/authorize?{$query}"
    );
  }

}
