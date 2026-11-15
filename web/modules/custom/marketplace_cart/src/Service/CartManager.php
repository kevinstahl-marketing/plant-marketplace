<?php

namespace Drupal\marketplace_cart\Service;

use Symfony\Component\HttpFoundation\RequestStack;

final class CartManager {

  private const SESSION_KEY = 'cart.shopify_cart_id';

  public function __construct(
    private readonly ShopifyStorefrontClient $client,
    private readonly RequestStack $requestStack,
  ) {}

  private function session() {
    $request = $this->requestStack->getCurrentRequest();

    if (!$request || !$request->hasSession()) {
      throw new \RuntimeException('Cart session is unavailable.');
    }

    return $request->getSession();
  }

  public function getCartId(): ?string {
    return $this->session()->get(self::SESSION_KEY);
  }

  public function clearCart(): void {
    $this->session()->remove(self::SESSION_KEY);
  }

  private function executeCartMutation(
    string $mutation,
    array $variables,
    string $operation,
  ): array {
    $data = $this->client->query($mutation, $variables);
    $result = $data[$operation] ?? [];

    if (!empty($result['userErrors'])) {
      $messages = array_map(
        static fn(array $error): string => $error['message'] ?? 'Unknown cart error',
        $result['userErrors']
      );

      throw new \RuntimeException(implode('; ', $messages));
    }

    if (empty($result['cart']['id'])) {
      throw new \RuntimeException('Shopify did not return a cart.');
    }

    return $result['cart'];
  }

  public function createCart(): array {
    $mutation = <<<'GRAPHQL'
mutation {
  cartCreate {
    cart {
      id
      checkoutUrl
      totalQuantity
    }
    userErrors {
      message
    }
  }
}
GRAPHQL;

    $cart = $this->executeCartMutation(
      $mutation,
      [],
      'cartCreate'
    );

    $this->session()->set(self::SESSION_KEY, $cart['id']);

    return $cart;
  }

  public function getCart(): ?array {
    $cartId = $this->getCartId();

    if (!$cartId) {
      return NULL;
    }

    $query = <<<'GRAPHQL'
query GetCart($id: ID!) {
  cart(id: $id) {
    id
    checkoutUrl
    totalQuantity
    cost {
      subtotalAmount {
        amount
        currencyCode
      }
      totalAmount {
        amount
        currencyCode
      }
    }
    lines(first: 100) {
      nodes {
        id
        quantity
        merchandise {
          ... on ProductVariant {
            id
            title
            product {
              title
              handle
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

    $data = $this->client->query($query, [
      'id' => $cartId,
    ]);

    $cart = $data['cart'] ?? NULL;

    if (!$cart) {
      $this->clearCart();
    }

    return $cart;
  }

  public function addItem(string $variantId, int $quantity = 1): array {
    if ($quantity < 1 || $quantity > 100) {
      throw new \InvalidArgumentException('Invalid quantity.');
    }

    $cartId = $this->getCartId();

    if (!$cartId) {
      $cartId = $this->createCart()['id'];
    }

    $mutation = <<<'GRAPHQL'
mutation AddCartLines(
  $cartId: ID!,
  $lines: [CartLineInput!]!
) {
  cartLinesAdd(cartId: $cartId, lines: $lines) {
    cart {
      id
      checkoutUrl
      totalQuantity
    }
    userErrors {
      message
    }
  }
}
GRAPHQL;

    return $this->executeCartMutation(
      $mutation,
      [
        'cartId' => $cartId,
        'lines' => [
          [
            'merchandiseId' => $variantId,
            'quantity' => $quantity,
          ],
        ],
      ],
      'cartLinesAdd'
    );
  }

  public function updateItem(string $lineId, int $quantity): array {
    if ($quantity < 1 || $quantity > 100) {
      throw new \InvalidArgumentException('Invalid quantity.');
    }

    $cartId = $this->getCartId();

    if (!$cartId) {
      throw new \RuntimeException('No active cart.');
    }

    $mutation = <<<'GRAPHQL'
mutation UpdateCartLines(
  $cartId: ID!,
  $lines: [CartLineUpdateInput!]!
) {
  cartLinesUpdate(cartId: $cartId, lines: $lines) {
    cart {
      id
      checkoutUrl
      totalQuantity
    }
    userErrors {
      message
    }
  }
}
GRAPHQL;

    return $this->executeCartMutation(
      $mutation,
      [
        'cartId' => $cartId,
        'lines' => [
          [
            'id' => $lineId,
            'quantity' => $quantity,
          ],
        ],
      ],
      'cartLinesUpdate'
    );
  }

  public function removeItem(string $lineId): array {
    $cartId = $this->getCartId();

    if (!$cartId) {
      throw new \RuntimeException('No active cart.');
    }

    $mutation = <<<'GRAPHQL'
mutation RemoveCartLines(
  $cartId: ID!,
  $lineIds: [ID!]!
) {
  cartLinesRemove(cartId: $cartId, lineIds: $lineIds) {
    cart {
      id
      checkoutUrl
      totalQuantity
    }
    userErrors {
      message
    }
  }
}
GRAPHQL;

    return $this->executeCartMutation(
      $mutation,
      [
        'cartId' => $cartId,
        'lineIds' => [$lineId],
      ],
      'cartLinesRemove'
    );
  }

}
