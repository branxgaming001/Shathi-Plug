<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/payments.php';
$IMG = require __DIR__ . '/assets/images.php';
$u = require_login();

$order = $_SESSION['order'] ?? null;
if (!$order) redirect('dashboard.php');

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $plan = plan_by_id((int)$order['plan_id']);
    if (!$plan) redirect('index.php#pricing');
    $intent = payment_create((int)$u['id'], $plan);   // amount fixed server-side
    if (payment_mode() === 'test' || (int)$plan['price_inr'] === 0) {
        // Test mode: simulate a verified success, then fulfil.
        $result = fulfill_payment((int)$intent['payment_id'], $order['renew'] ?: null, 'TEST-' . bin2hex(random_bytes(4)));
        unset($_SESSION['order']);
    } else {
        // Razorpay mode would render checkout.js here; falls back to test until keys are set.
        $result = ['ok' => false, 'error' => 'gateway_not_configured'];
    }
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payment — Saathi</title><link rel="icon" href="<?=$IMG['logo']?>">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/app.css">
</head><body><div class="auth-wrap"><div class="auth-card">
  <a class="brand" href="dashboard.php" style="justify-content:center;margin-bottom:14px"><img src="<?=$IMG['logo']?>" alt="" style="width:34px;height:34px">Saathi</a>
  <?php if ($result && !empty($result['ok'])): ?>
    <div style="text-align:center;font-size:46px">🎉</div>
    <h2 style="text-align:center"><?=!empty($result['renewed'])?'Renewed!':'Payment successful!'?></h2>
    <?php if (!empty($result['license_key'])): ?>
      <p class="auth-sub">Here is your license key — copy it now and paste it into the plugin. (Also saved in your dashboard.)</p>
      <div class="msg ok" style="text-align:center;font-size:18px;letter-spacing:1px;font-weight:800"><?=e($result['license_key'])?></div>
    <?php else: ?>
      <p class="auth-sub">Your license has been <?=!empty($result['renewed'])?'renewed':'updated'?>. See it in your dashboard.</p>
    <?php endif; ?>
    <a class="btn btn-primary btn-block" href="dashboard.php" style="margin-top:10px">Go to dashboard</a>
  <?php else: ?>
    <h2 style="text-align:center">Couldn't complete</h2>
    <div class="msg err"><?=e($result['error'] ?? 'Payment could not be processed. Please try again.')?></div>
    <a class="btn btn-ghost btn-block" href="checkout.php">Try again</a>
  <?php endif; ?>
</div></div></body></html>
