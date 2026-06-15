<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/payments.php';
$IMG = require __DIR__ . '/assets/images.php';

$code = $_GET['plan'] ?? '';
if ($code) {
    $plan = plan_by_code($code);
    if (!$plan) redirect('index.php#pricing');
    if (!current_user()) { $_SESSION['next'] = 'checkout.php?plan=' . urlencode($code); redirect('login.php'); }
    $_SESSION['order'] = ['plan_id' => (int)$plan['id'], 'renew' => null];
} else {
    if (!current_user()) redirect('login.php');
    $order = $_SESSION['order'] ?? null;
    if (!$order) redirect('index.php#pricing');
    $plan = plan_by_id((int)$order['plan_id']);
    if (!$plan) redirect('index.php#pricing');
}
$u = current_user();
$order = $_SESSION['order'];
$isRenew = !empty($order['renew']);
$amount = (int)$plan['price_inr'];
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Checkout — Saathi</title><link rel="icon" href="<?=$IMG['logo']?>">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/app.css">
</head><body><div class="auth-wrap"><div class="auth-card">
  <a class="brand" href="dashboard.php" style="justify-content:center;margin-bottom:14px"><img src="<?=$IMG['logo']?>" alt="" style="width:34px;height:34px">Saathi</a>
  <h2><?=$isRenew?'Renew plan':'Checkout'?></h2>
  <p class="auth-sub"><?=e($u['email'] ?: $u['phone'])?></p>

  <div class="panel" style="box-shadow:none;border:1px solid var(--line)">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div><strong style="font-size:17px"><?=e($plan['name'])?></strong><div class="small"><?=e(ucfirst($plan['period']))?> plan</div></div>
      <div style="font-family:var(--display);font-size:30px;font-weight:800">₹<?=number_format($amount)?></div>
    </div>
    <ul style="list-style:none;padding:0;margin:12px 0 0">
      <?php foreach (explode('|', (string)$plan['features']) as $f): if(trim($f)==='')continue; ?>
        <li class="small" style="margin:5px 0">✓ <?=e($f)?></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <?php if (payment_mode()==='test'): ?>
    <div class="msg dev">TEST MODE — no real charge. This simulates a successful payment so you can test the full flow. Add Razorpay keys later to go live.</div>
  <?php endif; ?>

  <form method="post" action="pay.php"><?=csrf_field()?>
    <button class="btn btn-primary btn-block btn-lg">
      <?php if ($amount===0): ?>Activate free license<?php elseif (payment_mode()==='test'): ?>Pay ₹<?=number_format($amount)?> (Test)<?php else: ?>Pay ₹<?=number_format($amount)?><?php endif; ?>
    </button>
  </form>
  <p class="small" style="text-align:center;margin-top:14px"><a href="dashboard.php" style="color:var(--v)">← Back to dashboard</a></p>
</div></div></body></html>
