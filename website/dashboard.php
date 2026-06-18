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
$act = pdo()->prepare("SELECT COUNT(*) FROM license_activations la JOIN licenses l ON l.id=la.license_id WHERE l.user_id=? AND la.status='active'");
$act->execute([(int)$u['id']]); $activations = (int)$act->fetchColumn();

$hasLicense = count($licenses) > 0;
$name = trim((string)($u['first_name'] ?? '')) ?: (trim((string)($u['name'] ?? '')) ?: explode('@', (string)($u['email'] ?: 'there'))[0]);
$who  = $u['email'] ?: $u['mobile'];

$steps = [
  ['Verify your email', true],
  ['Complete your profile', true],
  ['Get a license (free or paid)', $hasLicense],
  ['Activate Saathi on your website', $activations > 0],
];
$done = count(array_filter($steps, fn($s) => $s[1]));
$pct = (int) round($done / count($steps) * 100);
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard — Saathi</title><link rel="icon" href="<?=$IMG['logo']?>">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/app.css">
<style>
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:760px){.grid2{grid-template-columns:1fr}}
  .ck{list-style:none;padding:0;margin:10px 0 0}
  .ck li{display:flex;align-items:center;gap:10px;padding:8px 0;color:var(--ink2);font-size:15px}
  .ck .dot{width:22px;height:22px;border-radius:50%;flex:0 0 auto;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800}
  .ck .dot.y{background:#19C37D;color:#fff}.ck .dot.n{background:#ece9fb;color:#b3acd9}
  .ck li.y{color:var(--ink)}
  .bar{height:8px;background:#ece9fb;border-radius:99px;overflow:hidden;margin:4px 0 2px}
  .bar>i{display:block;height:100%;background:linear-gradient(90deg,var(--v),var(--c2,#FF6B5E))}
  .cta-card{background:linear-gradient(135deg,var(--v),#7c3aed);color:#fff;border-radius:18px;padding:22px 24px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
  .cta-card h3{color:#fff;margin:0 0 4px}.cta-card p{margin:0;opacity:.9;font-size:14px}
  .cta-card .btn{background:#fff;color:var(--v);white-space:nowrap}
  .kv{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
  .kv .k{font-size:12.5px;color:var(--muted);min-width:96px}.kv .v{font-size:13.5px;color:var(--ink);font-weight:600}
  .steps3{counter-reset:s;margin:8px 0 0;padding:0;list-style:none}
  .steps3 li{position:relative;padding:6px 0 6px 30px;color:var(--ink2);font-size:14px}
  .steps3 li:before{counter-increment:s;content:counter(s);position:absolute;left:0;top:5px;width:21px;height:21px;border-radius:50%;background:var(--lilac,#efeaff);color:var(--v);font-weight:800;font-size:12px;display:flex;align-items:center;justify-content:center}
</style>
</head><body>
<header class="topbar"><div class="wrap">
  <a class="brand" href="index.php"><img src="<?=$IMG['logo']?>" alt="" style="width:30px;height:30px">Saathi</a>
  <div class="sp"><span class="who"><?=e($who)?> <span class="badge v"><?=e(strtoupper((string)$u['provider']))?></span></span>
  <a class="btn btn-ghost" href="logout.php" style="padding:8px 16px">Log out</a></div>
</div></header>
<div class="dash">
  <h1>Welcome, <?=e($name)?> 👋</h1>
  <p class="muted">Signed in as <strong><?=e($who)?></strong> · member since <?=e(date('d M Y', strtotime($u['created_at'])))?></p>

  <?php if ($done < count($steps)): ?>
  <div class="panel">
    <h3>Getting started</h3>
    <div class="bar"><i style="width:<?=$pct?>%"></i></div>
    <p class="small" style="margin:4px 0 0"><?=$done?>/<?=count($steps)?> done</p>
    <ul class="ck">
      <?php foreach ($steps as $s): ?>
      <li class="<?=$s[1]?'y':'n'?>"><span class="dot <?=$s[1]?'y':'n'?>"><?=$s[1]?'✓':'•'?></span><?=e($s[0])?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div class="cta-card" style="margin-bottom:16px">
    <?php if (!$hasLicense): ?>
      <div><h3>Activate your free license</h3><p>Pick a plan to get your license key — start free in seconds.</p></div>
      <a class="btn btn-lg" href="checkout.php?plan=free">Get my license →</a>
    <?php elseif ($activations === 0): ?>
      <div><h3>Download Saathi & activate it</h3><p>Install the plugin on WordPress, then paste your license key.</p></div>
      <a class="btn btn-lg" href="downloads/saathi-agentic-ai.zip">Download plugin ↓</a>
    <?php else: ?>
      <div><h3>You're all set 🎉</h3><p>Saathi is active on your site. Manage licenses & billing below.</p></div>
      <a class="btn btn-lg" href="index.php#pricing">Upgrade plan</a>
    <?php endif; ?>
  </div>

  <div class="stats">
    <div class="stat"><b><?=count($licenses)?></b><span>Total licenses</span></div>
    <div class="stat"><b><?=$activeKeys?></b><span>Active keys</span></div>
    <div class="stat"><b><?=$activations?></b><span>Active sites</span></div>
    <div class="stat"><b>₹<?=number_format($paidTotal)?></b><span>Total spent</span></div>
  </div>

  <div class="grid2">
    <div class="panel">
      <h3>Get the plugin</h3>
      <p class="small">Install Saathi on WordPress and activate it with your license key.</p>
      <a class="btn btn-primary" href="downloads/saathi-agentic-ai.zip" style="margin:6px 0 4px">Download plugin (.zip) ↓</a>
      <ol class="steps3">
        <li>In WordPress: <strong>Plugins → Add New → Upload Plugin</strong>, choose the zip, Install &amp; Activate.</li>
        <li>Open <strong>Saathi AI → License</strong>, paste your key, click <strong>Activate</strong>.</li>
        <li>Add your AI key &amp; run the scan — see the <a href="docs.php" style="color:var(--v)">Docs</a>.</li>
      </ol>
    </div>
    <div class="panel">
      <h3>Your profile</h3>
      <div class="kv"><span class="k">Name</span><span class="v"><?=e(trim((($u['first_name']??'').' '.($u['last_name']??''))) ?: '—')?></span></div>
      <div class="kv"><span class="k">Company</span><span class="v"><?=e($u['company']??'') ?: '—'?></span></div>
      <div class="kv"><span class="k">Website</span><span class="v"><?=e($u['website']??'') ?: '—'?></span></div>
      <div class="kv"><span class="k">Mobile</span><span class="v"><?=e($u['mobile']??'') ?: '—'?></span></div>
      <a class="btn btn-ghost" href="profile.php?edit=1" style="margin-top:12px;padding:8px 16px">Edit profile</a>
    </div>
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
    <p class="small" style="margin-top:10px">Your full key is shown once at purchase and emailed to you — keep it safe.</p>
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
    <h3>Need help?</h3>
    <p class="small">Setup questions are answered in our docs, or reach our team anytime.</p>
    <a class="btn btn-ghost" href="docs.php" style="padding:8px 16px">Docs &amp; Help</a>
    <a class="btn btn-ghost" href="contact.php" style="padding:8px 16px">Contact support</a>
  </div>
</div></body></html>
