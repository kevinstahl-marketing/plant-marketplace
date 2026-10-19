<?php

namespace Drupal\shopify_connector\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;

final class ShopifyAuthorizeController extends ControllerBase {

  public function authorize(): TrustedRedirectResponse {
    $shop = getenv('SHOPIFY_STORE_DOMAIN');
    $clientId = getenv('SHOPIFY_CLIENT_ID');

    if (!$shop || !$clientId) {
      throw new \RuntimeException('Shopify environment variables missing.');
    }

    // Generate a secure OAuth state token.
    $state = bin2hex(random_bytes(32));

    // Store state for callback verification.
    \Drupal::service('tempstore.private')
      ->get('shopify_connector')
      ->set('oauth_state', $state);

    // Our public Codespaces OAuth callback.
    $redirectUri = 'https://supreme-space-orbit-x5jq695vxxjq36gpr-80.app.github.dev/shopify/oauth/callback';

    $query = http_build_query([
      'client_id' => $clientId,
      'scope' => 'read_products,read_inventory,read_locations',
      'redirect_uri' => $redirectUri,
      'state' => $state,
    ]);

    return new TrustedRedirectResponse(
      "https://{$shop}/admin/oauth/authorize?{$query}"
    );
  }

}
