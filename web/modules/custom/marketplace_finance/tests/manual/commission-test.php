<?php

$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();

$storage = $etm->getStorage('marketplace_auction');
$sellerStorage = $etm->getStorage('marketplace_seller');

$config = \Drupal::configFactory()
  ->getEditable('marketplace_finance.settings');

$resolver = \Drupal::service(
  'marketplace_finance.commission_resolver'
);

$originalRate = $config->get('commission_basis_points');

$transaction = $db->startTransaction();

function check(string $label, bool $passed): void {
  echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;

  if (!$passed) {
    throw new \RuntimeException($label);
  }
}

try {
  $old = $storage->load(8);
  $seller = $sellerStorage->load(1);

  check(
    'Historical auction locked at 10%',
    (int) $old->get('commission_snapshot_bps')->value === 1000
  );

  $config->set('commission_basis_points', 750)->save();

  check(
    'New marketplace commission is 7.5%',
    $resolver->resolve($seller)['basis_points'] === 750
  );

  $storage->resetCache([8]);
  $old = $storage->load(8);

  check(
    'Historical auction retains 10%',
    (int) $old->get('commission_snapshot_bps')->value === 1000
  );

  $seller->set('commission_override_bps', 500);
  $seller->save();

  check(
    'Seller override takes precedence',
    $resolver->resolve($seller)['basis_points'] === 500
  );

  $seller->set('commission_override_bps', 0);
  $seller->save();

  check(
    'Zero percent is a valid override',
    $resolver->resolve($seller)['basis_points'] === 0
  );

  $seller->set('commission_override_bps', NULL);
  $seller->save();

  check(
    'Removing override restores default',
    $resolver->resolve($seller)['basis_points'] === 750
  );

  $new = $storage->create([
    'title' => 'Commission regression test',
    'seller_id' => $seller->getUserId(),
    'seller_profile_id' => $seller->id(),
    'status' => 'open',
    'currency' => 'USD',
    'starting_price' => 1000,
    'bid_increment' => 100,
    'starts_at' => time(),
    'ends_at' => time() + 86400,
    'original_ends_at' => time() + 86400,
    'package_weight_g' => 500,
    'package_length_mm' => 200,
    'package_width_mm' => 200,
    'package_height_mm' => 200,
    'handling_days' => 2,
  ]);

  $new->save();

  check(
    'New auction automatically locks 7.5%',
    (int) $new->get('commission_snapshot_bps')->value === 750
  );

  $config->set('commission_basis_points', 1200)->save();

  $new->set('title', 'Commission regression test updated');
  $new->save();

  check(
    'Published auction retains 7.5% without bids',
    (int) $new->get('commission_snapshot_bps')->value === 750
  );

  $blocked = FALSE;

  try {
    $new->set('commission_snapshot_bps', 100);
    $new->save();
  }
  catch (\Throwable $e) {
    if (!str_contains(
      $e->getMessage(),
      'Published commission terms cannot be changed'
    )) {
      throw $e;
    }
    $blocked = TRUE;
  }

  check(
    'Direct modification of locked commission rejected',
    $blocked
  );

  echo "\nALL COMMISSION CHECKS PASSED\n";
}
finally {
  $transaction->rollBack();
  unset($transaction);

  $storage->resetCache();
  $sellerStorage->resetCache();

  $config->set(
    'commission_basis_points',
    $originalRate
  )->save();

  echo "\nTest data rolled back.\n";
}
