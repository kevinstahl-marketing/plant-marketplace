<?php

declare(strict_types=1);

namespace Drupal\marketplace_auction\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\marketplace_auction\Entity\Auction;
use Drupal\marketplace_auction\Form\BidForm;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AuctionController extends ControllerBase {

  public function view(Auction $marketplace_auction): array {

    $auction = $marketplace_auction;
    $account = $this->currentUser();
    $now = \Drupal::time()->getCurrentTime();

    $status = $auction->get('status')->value;

    if (in_array($status, ['draft', 'scheduled', 'cancelled'], TRUE)
      && !$account->hasPermission('administer marketplace auctions')
      && (int) $account->id() !== $auction->getSellerId()) {
      throw new NotFoundHttpException();
    }

    $open = $auction->isOpen($now) && $auction->hasEligibleSeller();
    $count = (int) $auction->get('bid_count')->value;

    $price = $count > 0
      ? (int) $auction->get('current_price')->value
      : (int) $auction->get('starting_price')->value;

    $build = [
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    $build['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => \Drupal\Component\Utility\Html::escape($auction->label()),
    ];

    $build['description'] = [
      '#type' => 'processed_text',
      '#text' => $auction->get('description')->value ?? '',
      '#format' => $auction->get('description')->format
        ?? 'plain_text',
    ];

    $build['details'] = [
      '#theme' => 'item_list',
      '#items' => [
        ($count > 0 ? 'Current bid: $' : 'Starting bid: $')
          . number_format($price / 100, 2),
        'Total bids: ' . $count,
        'Status: ' . ($open ? 'Accepting bids' : 'Not accepting bids'),
        'Ends: ' . \Drupal::service('date.formatter')->format(
          (int) $auction->get('ends_at')->value,
          'medium'
        ),
      ],
    ];

    if ($open
      && $account->isAuthenticated()
      && $account->hasPermission('place marketplace bids')
      && (int) $account->id() !== $auction->getSellerId()) {

      $build['bid_form'] = $this->formBuilder()->getForm(
        BidForm::class,
        $auction
      );
    }
    elseif ($open) {
      $build['notice'] = [
        '#plain_text' => 'Bidding is unavailable for your account.',
      ];
    }

    return $build;
  }

}
