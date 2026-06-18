<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/payments.php';
$IMG = require __DIR__ . '/assets/images.php';
$u = require_login();
require_profile($u);

$lics = pdo()->prepare("SELECT l.*, p.name plan_name, p.code plan_code FROM licenses l JOIN plans p ON p.id=l.plan_id WHERE l.user_id=? ORDER BY l.id DESC");
$lics->execute([(int)$u['id']]); $licenses = $lics->fetchAll();

$pays = pdo()->prepare("SELECT pay.*, p.name plan_name FROM payments pay JOIN plans p ON p.id=pay.plan_id WHERE pay.user_id=? ORDER BY pay.id DESC");
$pays->execute([(int)$u['id']]); $payments = $pays->fetchAll();

$activeKeys = 0; $paidTotal = 0;
foreach ($licenses as $l) if ($l['status']==='active' && ($l['expires_at']===null || strtotime($l['expires_at'])>time())) $activeKeys++;
foreach ($payments as $p) if ($p['status']==='paid') $paidTotal += (int)$p['amount_inr'];
$who = $u['email'] ?: $u['phone'];
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard — Saathi</title><link rel="icon" href="<?=$IMG['logo']?>">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/app.css">
</head><body>
<header class="topbar"><div class="wrap">
  <a class="brand" href="index.php"><img src="<?=$IMG['logo']?>" alt="" style="width:30px;height:30px">Saathi</a>
  <div class="sp"><span class="who"><?=e($who)?> <span class="badge v"><?=e(strtoupper($u['provider']))?></span></span>
  <a class="btn btn-ghost" href="logout.php" style="padding:8px 16px">Log out</a></div>
</div></header>
<div class="dash">
  <h1>Your dashboard</h1>
  <p class="muted">Signed in as <strong><?=e($who)?></strong> · member since <?=e(date('d M Y', strtotime($u['created_at'])))?></p>

  <div class="stats">
    <div class="stat"><b><?=count($licenses)?></b><span>Total licenses</span></div>
    <div class="stat"><b><?=$activeKeys?></b><span>Active keys</span></div>
    <div class="stat"><b><?=count($payments)?></b><span>Payments</span></div>
    <div class="stat"><b>₹<?=number_format($paidTotal)?></b><span>Total spent</span></div>
  </div>

  <div class="panel">
    <h3>My licenses</h3>
    <?php if (!$licenses): ?>
      <p class="small">No licenses yet. <a href="index.php#pricing" style="color:var(--v)">Choose a plan →</a></p>
    <?php else: ?>
    <table><tr><th>Key</th><th>Plan</th><th>Status</th><th>Expires</th><th></th></tr>
      <?php foreach ($licenses as $l):
        $valid = $l['status']==='active' && ($l['expires_at']===null || strtotime($l['expires_at'])>time());
        $badge = $valid?'g':($l['status']==='revoked'?'r':'m'); ?>
        <tr>
          <td><code><?=e($l['key_prefix'])?>-••••</code></td>
          <td><?=e($l['plan_name'])?></td>
          <td><span class="badge <?=$badge?>"><?=$valid?'Active':e(ucfirst($l['status']))?></span></td>
          <td><?=$l['expires_at']===null?'Lifetime':e(date('d M Y', strtotime($l['expires_at'])))?></td>
          <td><?php if ($l['expires_at']!==null): ?><a class="btn btn-ghost" style="padding:6px 12px;font-size:13px" href="renew.php?license=<?=(int)$l['id']?>">Renew</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="small" style="margin-top:10px">Full keys are shown once at purchase and emailed to you. Keep them safe.</p>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Payment history</h3>
    <?php if (!$payments): ?><p class="small">No payments yet.</p><?php else: ?>
    <table><tr><th>Date</th><th>Plan</th><th>Amount</th><th>Status</th><th>Mode</th></tr>
      <?php foreach ($payments as $p): ?>
        <tr><td><?=e(date('d M Y', strtotime($p['created_at'])))?></td><td><?=e($p['plan_name'])?></td>
        <td>₹<?=number_format((int)$p['amount_inr'])?></td>
        <td><span class="badge <?=$p['status']==='paid'?'g':($p['status']==='failed'?'r':'m')?>"><?=e(ucfirst($p['status']))?></span></td>
        <td class="small"><?=e($p['gateway'])?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Get the plugin</h3>
    <p class="small">Install Saathi on WordPress, then paste your license key in the plugin's License settings to activate.</p>
    <a class="btn btn-primary" href="index.php#pricing" style="margin-top:8px">Upgrade / buy a plan</a>
  </div>
</div></body></html>
