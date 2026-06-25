<?php
/**
 * Customer account dashboard — persistent left sidebar + right content panel
 * (Glaido-style), with sections swapped via ?section=. Everything a customer
 * needs lives here: Overview cards, inline Plan & Billing (no bounce to home),
 * Licenses, Downloads, Settings and Help.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/payments.php';
$IMG = require __DIR__ . '/assets/images.php';
$u = require_login();
require_profile($u);

$section = preg_replace('/[^a-z]/', '', (string)($_GET['section'] ?? 'overview'));
$SECTIONS = ['overview','plan','licenses','downloads','settings','help'];
if (!in_array($section, $SECTIONS, true)) $section = 'overview';

// ── Data ──────────────────────────────────────────────────────────────
$uid = (int)$u['id'];
$lst = pdo()->prepare("SELECT l.*, p.name plan_name, p.code plan_code, p.price_inr, p.period,
        (SELECT COUNT(*) FROM license_activations WHERE license_id=l.id AND status='active') acts
        FROM licenses l JOIN plans p ON p.id=l.plan_id WHERE l.user_id=? ORDER BY l.id DESC");
$lst->execute([$uid]); $licenses = $lst->fetchAll();

$pst = pdo()->prepare("SELECT pay.*, p.name plan_name FROM payments pay JOIN plans p ON p.id=pay.plan_id WHERE pay.user_id=? ORDER BY pay.id DESC");
$pst->execute([$uid]); $payments = $pst->fetchAll();

$tierRank = ['free'=>1,'pro'=>2,'pro_annual'=>2,'max'=>3,'lifetime'=>3,'agency'=>3];
$activeLicense = null; $activeKeys = 0; $activations = 0; $paidTotal = 0;
foreach ($licenses as $l) {
    $valid = $l['status']==='active' && ($l['expires_at']===null || strtotime($l['expires_at'])>time());
    if ($valid) {
        $activeKeys++;
        if (!$activeLicense || ($tierRank[$l['plan_code']] ?? 0) > ($tierRank[$activeLicense['plan_code']] ?? 0)) $activeLicense = $l;
    }
    $activations += (int)$l['acts'];
}
foreach ($payments as $p) if ($p['status']==='paid') $paidTotal += (int)$p['amount_inr'];

$currentCode = $activeLicense['plan_code'] ?? '';
$currentName = $activeLicense['plan_name'] ?? 'No active plan';
$maxSites    = $activeLicense ? (int)$activeLicense['max_activations'] : 0;
$renewal     = ($activeLicense && $activeLicense['expires_at']!==null) ? strtotime($activeLicense['expires_at']) : null;
$daysLeft    = $renewal ? (int)ceil(($renewal - time())/86400) : null;
$hasLicense  = count($licenses) > 0;
$tierLabel   = ['free'=>'Free','pro'=>'Pro','max'=>'Max','lifetime'=>'Max','pro_annual'=>'Pro','agency'=>'Max'][$currentCode] ?? '—';
$isMax       = in_array($currentCode, ['max','lifetime','agency'], true);

$name = trim((string)($u['first_name'] ?? '')) ?: (trim((string)($u['name'] ?? '')) ?: explode('@', (string)($u['email'] ?: 'there'))[0]);
$who  = $u['email'] ?: $u['mobile'];
$initial = strtoupper(substr($name, 0, 1) ?: 'U');

// Getting-started checklist
$steps = [
  ['Verify your email', true],
  ['Complete your profile', profile_complete($u)],
  ['Choose a plan & get your license', $hasLicense],
  ['Download & activate Saathi on your site', $activations > 0],
];
$stepsDone = count(array_filter($steps, fn($s) => $s[1]));
$allDone = $stepsDone === count($steps);

$plans = plans_all();   // active, sorted
$navItems = [
  'overview'  => ['Overview', 'M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z'],
  'plan'      => ['Plan & Billing', 'M2 7h20v4H2zM2 13h20v4H2z'],
  'licenses'  => ['Licenses', 'M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z'],
  'downloads' => ['Downloads', 'M12 3v12m0 0l-4-4m4 4l4-4M4 21h16'],
  'settings'  => ['Settings', 'M12 8a4 4 0 100 8 4 4 0 000-8z'],
  'help'      => ['Help & Support', 'M12 2a10 10 0 100 20 10 10 0 000-20zm0 15h.01M12 7a3 3 0 013 3c0 2-3 2-3 4'],
];

function badge_class(int $days=null): string { if ($days===null) return 'g'; if ($days<0) return 'r'; if ($days<=7) return 'm'; return 'g'; }

$css = <<<CSS
*{box-sizing:border-box}
.acc{display:flex;min-height:100vh;background:var(--bg,#f6f5fb)}
.acc-side{width:248px;flex:0 0 248px;background:#fff;border-right:1px solid var(--line);display:flex;flex-direction:column;position:sticky;top:0;height:100vh}
.acc-brand{display:flex;align-items:center;gap:9px;font-family:'Baloo 2';font-weight:800;font-size:21px;padding:18px 20px;border-bottom:1px solid var(--line)}
.acc-brand img{width:30px;height:30px}
.acc-nav{padding:12px 10px;flex:1;overflow:auto}
.acc-nav a{display:flex;align-items:center;gap:11px;padding:11px 13px;border-radius:11px;color:var(--ink2,#4b4668);font-weight:600;font-size:14.5px;margin-bottom:3px;transition:.15s}
.acc-nav a svg{width:19px;height:19px;stroke:currentColor;fill:none;stroke-width:2;flex:0 0 auto}
.acc-nav a:hover{background:#f4f2fe;color:var(--v)}
.acc-nav a.on{background:linear-gradient(135deg,var(--v),#7c3aed);color:#fff;box-shadow:0 8px 18px -10px var(--v)}
.acc-user{border-top:1px solid var(--line);padding:13px 16px;display:flex;align-items:center;gap:10px}
.acc-av{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--v),#7c3aed);color:#fff;display:grid;place-items:center;font-weight:800;font-family:'Baloo 2'}
.acc-user .nm{font-weight:700;font-size:13.5px;line-height:1.1}
.acc-user .pl{font-size:11.5px;color:var(--muted)}
.acc-main{flex:1;min-width:0;display:flex;flex-direction:column}
.acc-top{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 28px;background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:4}
.acc-top h1{font-family:'Baloo 2';font-size:22px;margin:0}
.acc-body{padding:24px 28px;max-width:1080px;width:100%}
.acc-banner{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:13px;padding:12px 16px;margin-bottom:18px;font-size:14px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 10px 30px -24px rgba(40,30,90,.5)}
.card.span2{grid-column:span 2}
@media(max-width:720px){.card.span2{grid-column:span 1}.acc-side{display:none}}
.card h3{font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin:0 0 10px;font-family:'Plus Jakarta Sans';font-weight:700}
.card .big{font-family:'Baloo 2';font-size:30px;font-weight:800;line-height:1}
.card .sub{color:var(--muted);font-size:13px;margin-top:4px}
.pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700}
.pill.g{background:#ecfdf3;color:#15803d}.pill.m{background:#fff7ed;color:#9a3412}.pill.r{background:#fef2f2;color:#b3261e}.pill.v{background:#efeafe;color:var(--v)}
.keyrow{display:flex;align-items:center;gap:8px;background:#f6f5fb;border:1px solid var(--line);border-radius:10px;padding:9px 12px;font-family:monospace;font-size:14px}
.keyrow button{border:none;background:none;cursor:pointer;color:var(--v);font-weight:700;font:inherit;font-size:12px}
.barwrap{height:8px;background:#ece9fb;border-radius:99px;overflow:hidden;margin:8px 0 4px}
.barwrap>i{display:block;height:100%;background:linear-gradient(90deg,var(--v),#FF6B5E)}
.ck{list-style:none;padding:0;margin:6px 0 0}
.ck li{display:flex;align-items:center;gap:10px;padding:7px 0;font-size:14px;color:var(--ink2)}
.ck .dot{width:21px;height:21px;border-radius:50%;display:grid;place-items:center;font-size:12px;font-weight:800;flex:0 0 auto}
.ck .dot.y{background:#19C37D;color:#fff}.ck .dot.n{background:#ece9fb;color:#b3acd9}
.plans3{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px}
.plan{background:#fff;border:2px solid var(--line);border-radius:18px;padding:22px;position:relative;display:flex;flex-direction:column}
.plan.cur{border-color:var(--v);box-shadow:0 0 0 3px rgba(109,93,251,.14)}
.plan.pop{border-color:#FF6B5E}
.plan h4{font-family:'Baloo 2';font-size:20px;margin:0 0 2px}
.plan .amt{font-family:'Baloo 2';font-size:30px;font-weight:800;margin:6px 0}
.plan .amt span{font-size:13px;color:var(--muted);font-weight:600}
.plan ul{list-style:none;padding:0;margin:10px 0 16px;flex:1}
.plan li{font-size:13.5px;color:var(--ink2);margin:7px 0;display:flex;gap:7px}
.plan li svg{width:15px;height:15px;stroke:#15803d;fill:none;stroke-width:3;flex:0 0 auto;margin-top:2px}
.curbadge{position:absolute;top:14px;right:14px}
.tbl{width:100%;border-collapse:collapse}
.tbl th,.tbl td{text-align:left;padding:11px 10px;border-bottom:1px solid var(--line);font-size:14px}
.tbl th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
.subtabs{display:flex;gap:6px;border-bottom:1px solid var(--line);margin-bottom:18px}
.subtabs a{padding:10px 14px;font-weight:600;color:var(--muted);border-bottom:2px solid transparent;font-size:14px}
.subtabs a.on{color:var(--v);border-bottom-color:var(--v)}
.sect-head{margin-bottom:18px}
.sect-head h2{font-family:'Baloo 2';font-size:26px;margin:0}
.sect-head p{color:var(--muted);margin:4px 0 0}
.btn-sm{padding:9px 16px;font-size:13.5px}
.empty{text-align:center;color:var(--muted);padding:30px;border:1px dashed var(--line);border-radius:14px}
CSS;

// ── Section renderers ───────────────────────────────────────────────────
$check = '<svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>';
ob_start();

if ($section === 'overview'):
?>
  <div class="sect-head"><h2>Welcome, <?=e($name)?> 👋</h2><p>Here's everything about your Saathi account.</p></div>
  <?php if ($renewal !== null && $daysLeft !== null && $daysLeft <= 14): ?>
    <div class="acc-banner"><span>⏳ Your <strong><?=e($currentName)?></strong> plan <?=$daysLeft<0?'expired':'renews in <strong>'.$daysLeft.' day'.($daysLeft==1?'':'s').'</strong>'?>.</span><a class="btn btn-primary btn-sm" href="dashboard.php?section=plan">Renew / manage</a></div>
  <?php endif; ?>
  <div class="cards">
    <div class="card">
      <h3>Current plan</h3>
      <div class="big"><?=e($tierLabel)?></div>
      <div class="sub"><?php if($activeLicense){ echo $renewal? ('Renews '.date('d M Y',$renewal)) : 'Lifetime'; } else { echo 'No active plan yet'; } ?></div>
      <a class="btn btn-ghost btn-sm" style="margin-top:12px" href="dashboard.php?section=plan"><?=$isMax?'Manage plan':'Upgrade plan'?></a>
    </div>
    <div class="card">
      <h3>License status</h3>
      <?php if($activeLicense): $vb=badge_class($daysLeft); ?>
        <div class="keyrow"><span><?=e($activeLicense['key_prefix'])?>-••••</span></div>
        <div class="sub" style="margin-top:8px"><span class="pill <?=$vb?>"><?=$daysLeft!==null&&$daysLeft<0?'Expired':'Active'?></span> · <?=e($activeLicense['plan_name'])?></div>
      <?php else: ?>
        <div class="sub">No license yet. <a href="dashboard.php?section=plan" style="color:var(--v);font-weight:700">Choose a plan →</a></div>
      <?php endif; ?>
    </div>
    <div class="card">
      <h3>Active sites</h3>
      <div class="big"><?=$activations?> <span style="font-size:15px;color:var(--muted)">/ <?=$maxSites?:1?></span></div>
      <div class="barwrap"><i style="width:<?=$maxSites?min(100,(int)round($activations/max(1,$maxSites)*100)):0?>%"></i></div>
      <div class="sub">Each license activates 1 website.</div>
    </div>
    <div class="card">
      <h3>Get the plugin</h3>
      <?php if($hasLicense): ?>
        <a class="btn btn-primary btn-sm" href="download.php">Download Saathi ↓</a>
        <div class="sub" style="margin-top:10px">Install on WordPress, then paste your license key.</div>
      <?php else: ?>
        <div class="sub">Choose a plan to unlock your download.</div>
        <a class="btn btn-primary btn-sm" style="margin-top:10px" href="dashboard.php?section=plan">Choose a plan</a>
      <?php endif; ?>
    </div>
    <?php if(!$allDone): ?>
    <div class="card span2">
      <h3>Getting started · <?=$stepsDone?>/<?=count($steps)?></h3>
      <div class="barwrap"><i style="width:<?=(int)round($stepsDone/count($steps)*100)?>%"></i></div>
      <ul class="ck"><?php foreach($steps as $s): ?><li><span class="dot <?=$s[1]?'y':'n'?>"><?=$s[1]?'✓':'•'?></span><?=e($s[0])?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
    <div class="card">
      <h3>Total spent</h3>
      <div class="big">₹<?=number_format($paidTotal)?></div>
      <div class="sub"><?=count(array_filter($payments,fn($p)=>$p['status']==='paid'))?> payment(s)</div>
    </div>
    <div class="card">
      <h3>Need a hand?</h3>
      <div class="sub" style="margin-bottom:10px">Docs, setup help and support — we reply fast.</div>
      <a class="btn btn-ghost btn-sm" href="dashboard.php?section=help">Help & support</a>
    </div>
  </div>
<?php
elseif ($section === 'plan'):
?>
  <div class="sect-head"><h2>Plan & Billing</h2><p>Choose or upgrade your plan — changes apply to your account instantly.</p></div>
  <div class="card" style="margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div><div style="font-weight:700;font-size:16px"><?=$activeLicense?('You\'re on the '.e($currentName).' plan'):'No active subscription'?></div>
    <div class="sub"><?php if($activeLicense){echo $renewal?('Renews '.date('d M Y',$renewal)):'Lifetime access';}else{echo 'Pick a plan below to get started — Free works too.';}?></div></div>
    <?php if($activeLicense): ?><span class="pill v"><?=e($tierLabel)?></span><?php endif; ?>
  </div>
  <div class="plans3">
    <?php foreach($plans as $p): $code=$p['code']; $isCur=$code===$currentCode; $pop=$code==='max';
      $feats=array_filter(array_map('trim',explode('|',(string)$p['features']))); $price=(int)$p['price_inr']; ?>
    <div class="plan <?=$isCur?'cur':''?> <?=$pop&&!$isCur?'pop':''?>">
      <?php if($isCur): ?><span class="curbadge pill v">Current plan</span><?php endif; ?>
      <h4><?=e($p['name'])?></h4>
      <div class="amt">₹<?=number_format($price)?><span> <?=$p['period']==='month'?'/mo':($p['period']==='year'?'/yr':'forever')?></span></div>
      <ul><?php foreach($feats as $f): ?><li><?=$check?> <?=e($f)?></li><?php endforeach; ?></ul>
      <?php if($isCur): ?>
        <button class="btn btn-ghost btn-block" disabled style="opacity:.7">Your current plan</button>
      <?php else: ?>
        <a class="btn <?=$pop?'btn-primary':'btn-ghost'?> btn-block" href="checkout.php?plan=<?=urlencode($code)?>"><?=$price===0?'Get Free':(($tierRank[$code]??0)>($tierRank[$currentCode]??0)?'Upgrade to '.e($p['name']):'Switch to '.e($p['name']))?></a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="card" style="margin-top:20px">
    <h3>Payment history</h3>
    <?php if(!$payments): ?><p class="sub">No payments yet.</p><?php else: ?>
    <table class="tbl"><tr><th>Date</th><th>Plan</th><th>Amount</th><th>Status</th></tr>
    <?php foreach($payments as $p): ?><tr><td><?=e(date('d M Y',strtotime($p['created_at'])))?></td><td><?=e($p['plan_name'])?></td><td>₹<?=number_format((int)$p['amount_inr'])?></td><td><span class="pill <?=$p['status']==='paid'?'g':($p['status']==='failed'?'r':'m')?>"><?=e(ucfirst($p['status']))?></span></td></tr><?php endforeach; ?>
    </table><?php endif; ?>
  </div>
<?php
elseif ($section === 'licenses'):
?>
  <div class="sect-head"><h2>Your licenses</h2><p>Manage your license keys and the sites they're activated on.</p></div>
  <?php if(!$licenses): ?>
    <div class="empty">No licenses yet.<br><a class="btn btn-primary btn-sm" style="margin-top:12px" href="dashboard.php?section=plan">Choose a plan →</a></div>
  <?php else: ?>
    <div class="card">
      <table class="tbl"><tr><th>Key</th><th>Plan</th><th>Status</th><th>Sites</th><th>Expires</th><th></th></tr>
      <?php foreach($licenses as $l): $valid=$l['status']==='active'&&($l['expires_at']===null||strtotime($l['expires_at'])>time()); ?>
        <tr>
          <td><span style="font-family:monospace"><?=e($l['key_prefix'])?>-••••</span></td>
          <td><?=e($l['plan_name'])?></td>
          <td><span class="pill <?=$valid?'g':($l['status']==='revoked'?'r':'m')?>"><?=$valid?'Active':e(ucfirst($l['status']))?></span></td>
          <td><?=(int)$l['acts']?>/<?=(int)$l['max_activations']?></td>
          <td><?=$l['expires_at']===null?'Lifetime':e(date('d M Y',strtotime($l['expires_at'])))?></td>
          <td><?php if($l['expires_at']!==null): ?><a class="btn btn-ghost btn-sm" href="renew.php?license=<?=(int)$l['id']?>">Renew</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </table>
      <p class="sub" style="margin-top:12px">🔒 Your full license key is shown once at purchase and emailed to you — keep it safe. Paste it into the plugin (Saathi → Setup) to activate.</p>
    </div>
  <?php endif; ?>
<?php
elseif ($section === 'downloads'):
?>
  <div class="sect-head"><h2>Downloads</h2><p>Install Saathi on your WordPress site.</p></div>
  <div class="card">
    <?php if($hasLicense): ?>
      <h3>Saathi Agentic AI plugin</h3>
      <a class="btn btn-primary" href="download.php" style="margin:4px 0 8px">Download plugin (.zip) ↓</a>
      <ol style="margin:10px 0 0;padding-left:18px;color:var(--ink2);font-size:14px;line-height:1.9">
        <li>In WordPress: <strong>Plugins → Add New → Upload Plugin</strong> → choose the zip → Install &amp; Activate.</li>
        <li>Open <strong>Saathi AI → Setup</strong>, paste your license key, click Activate.</li>
        <li>Add your AI provider key &amp; build your assistant — see the <a href="docs.php" style="color:var(--v)">Docs</a>.</li>
      </ol>
    <?php else: ?>
      <div class="empty">Your download unlocks once you have a plan.<br><a class="btn btn-primary btn-sm" style="margin-top:12px" href="dashboard.php?section=plan">Choose a plan (Free works too) →</a></div>
    <?php endif; ?>
  </div>
<?php
elseif ($section === 'settings'):
  $tab = preg_replace('/[^a-z]/','',(string)($_GET['tab'] ?? 'profile')); if(!in_array($tab,['profile','account'],true)) $tab='profile';
?>
  <div class="sect-head"><h2>Settings</h2><p>Manage your account details.</p></div>
  <div class="subtabs">
    <a href="dashboard.php?section=settings&tab=profile" class="<?=$tab==='profile'?'on':''?>">Profile</a>
    <a href="dashboard.php?section=settings&tab=account" class="<?=$tab==='account'?'on':''?>">Account</a>
  </div>
  <?php if($tab==='profile'): ?>
  <div class="card">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px"><span class="acc-av" style="width:46px;height:46px;font-size:20px"><?=e($initial)?></span>
      <div><div style="font-weight:700;font-size:16px"><?=e(trim(($u['first_name']??'').' '.($u['last_name']??'')) ?: $name)?></div><div class="sub"><?=e($who)?></div></div></div>
    <table class="tbl">
      <tr><th>Company</th><td><?=e($u['company']??'') ?: '—'?></td></tr>
      <tr><th>Website</th><td><?=e($u['website']??'') ?: '—'?></td></tr>
      <tr><th>Mobile</th><td><?=e($u['mobile']??'') ?: '—'?></td></tr>
      <tr><th>Country</th><td><?=e($u['country']??'') ?: '—'?></td></tr>
      <tr><th>Sign-in</th><td><?=e(strtoupper((string)$u['provider']))?></td></tr>
    </table>
    <a class="btn btn-ghost btn-sm" style="margin-top:14px" href="profile.php?edit=1">Edit profile</a>
  </div>
  <?php else: ?>
  <div class="card">
    <h3>Account</h3>
    <p class="sub">Signed in as <strong><?=e($who)?></strong> · member since <?=e(date('d M Y',strtotime($u['created_at'])))?>.</p>
    <a class="btn btn-ghost btn-sm" style="margin-top:10px" href="logout.php">Log out</a>
  </div>
  <?php endif; ?>
<?php
elseif ($section === 'help'):
?>
  <div class="sect-head"><h2>Help & Support</h2><p>We're here to help you get the most from Saathi.</p></div>
  <div class="cards">
    <div class="card"><h3>System status</h3><div class="sub"><span class="pill g">● All systems operational</span></div></div>
    <div class="card"><h3>Documentation</h3><div class="sub" style="margin-bottom:10px">Setup guides, FAQs and how-tos.</div><a class="btn btn-ghost btn-sm" href="docs.php">Open docs</a></div>
    <div class="card"><h3>Contact us</h3><div class="sub" style="margin-bottom:10px">Questions about your account or billing.</div><a class="btn btn-ghost btn-sm" href="contact.php">Contact support</a></div>
  </div>
<?php
endif;
$content = ob_get_clean();
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard — Saathi</title><link rel="icon" href="<?=$IMG['logo']?>">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/app.css">
<link rel="stylesheet" href="assets/css/acc.css?v=5">
</head><body>
<div class="acc">
  <aside class="acc-side">
    <a class="acc-brand" href="index.php"><img src="<?=$IMG['logo']?>" alt="">Saathi</a>
    <nav class="acc-nav">
      <?php foreach($navItems as $key=>$it): ?>
        <a href="dashboard.php?section=<?=$key?>" class="<?=$section===$key?'on':''?>"><svg viewBox="0 0 24 24"><path d="<?=$it[1]?>"/></svg><?=e($it[0])?></a>
      <?php endforeach; ?>
      <?php if(!$isMax): ?><a href="dashboard.php?section=plan" style="margin-top:8px;background:#fff6f4;color:#FF6B5E"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h7l-1 8 10-12h-7z"/></svg>Go <?=$currentCode==='pro'?'Max':'Pro'?></a><?php endif; ?>
    </nav>
    <div class="acc-user"><span class="acc-av"><?=e($initial)?></span><div><div class="nm"><?=e($name)?></div><div class="pl" title="<?=e($who)?>"><?=e($who)?></div></div></div>
  </aside>
  <main class="acc-main">
    <header class="acc-top"><h1><?=e($navItems[$section][0])?></h1>
      <?php if($hasLicense): ?><a class="btn btn-primary btn-sm" href="download.php">Download plugin ↓</a><?php else: ?><a class="btn btn-primary btn-sm" href="dashboard.php?section=plan">Choose a plan</a><?php endif; ?>
    </header>
    <div class="acc-body"><?=$content?></div>
  </main>
</div>
<script>
document.querySelectorAll('[data-copy]').forEach(function(b){b.onclick=function(){navigator.clipboard.writeText(b.getAttribute('data-copy'));var t=b.textContent;b.textContent='Copied!';setTimeout(function(){b.textContent=t;},1500);};});
</script>
</body></html>
