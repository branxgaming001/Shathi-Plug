<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/payments.php';
$IMG = require __DIR__ . '/assets/images.php';
$a = require_admin();
$db = pdo();
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'save_plan') {
        $db->prepare("UPDATE plans SET name=?, price_inr=?, max_activations=?, active=? WHERE id=?")
           ->execute([trim($_POST['name']), max(0,(int)$_POST['price']), max(1,(int)$_POST['acts']), isset($_POST['active'])?1:0, (int)$_POST['id']]);
        audit('admin_plan_update', ['plan_id'=>(int)$_POST['id'],'price'=>(int)$_POST['price']]); $flash = 'Plan updated.';
    } elseif ($act === 'add_admin_email') {
        $em = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) { $flash = 'Enter a valid email address.'; }
        else {
            $list = admin_emails(); if (!in_array($em, $list, true)) $list[] = $em;
            setting_set('ADMIN_EMAILS', implode(',', $list));
            audit('admin_email_added', ['email'=>$em]); $flash = 'Admin added: ' . $em;
        }
    } elseif ($act === 'remove_admin_email') {
        $em = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        if ($em === mb_strtolower((string)($a['email'] ?? ''))) { $flash = 'You cannot remove your own admin access.'; }
        else {
            $list = array_values(array_filter(admin_emails(), fn($x) => $x !== $em));
            if (!$list) { $flash = 'At least one admin email must remain.'; }
            else { setting_set('ADMIN_EMAILS', implode(',', $list)); audit('admin_email_removed', ['email'=>$em]); $flash = 'Admin removed: ' . $em; }
        }
    } elseif ($act === 'save_settings') {
        foreach (['RAZORPAY_KEY_ID','RAZORPAY_KEY_SECRET','BREVO_API_KEY','RESEND_API_KEY','MAIL_FROM','MAIL_FROM_NAME','REMINDER_DAYS'] as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            if ($v !== '') setting_set($k, $v);   // only overwrite when a new value is provided
        }
        audit('admin_settings_update', ['keys'=>'payment/email/license']); $flash = 'Settings saved.';
    } elseif ($act === 'block_user') {
        $db->prepare("UPDATE users SET status=? WHERE id=?")->execute([$_POST['to']==='block'?'blocked':'active',(int)$_POST['id']]);
        audit('admin_user_'.$_POST['to'], ['user_id'=>(int)$_POST['id']]); $flash = 'User updated.';
    } elseif ($act === 'revoke_license') {
        $db->prepare("UPDATE licenses SET status='revoked' WHERE id=?")->execute([(int)$_POST['id']]);
        audit('admin_license_revoke', ['license_id'=>(int)$_POST['id']]); $flash = 'License revoked.';
    }
}

$tab = $_GET['tab'] ?? 'overview';
$tabs = ['overview'=>'Overview','users'=>'Users','licenses'=>'Licenses','payments'=>'Payments','pricing'=>'Pricing','admins'=>'Admins','audit'=>'Audit log','settings'=>'Settings'];
function n($qq){ return (int)pdo()->query($qq)->fetchColumn(); }
$q = trim((string)($_GET['q'] ?? ''));   // search term (users tab)

// ---- CSV export (runs before any HTML) ----
if (($_GET['export'] ?? '') === 'csv') {
    $what = in_array($tab, ['users','licenses','payments'], true) ? $tab : 'users';
    audit('admin_export_csv', ['what'=>$what]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="saathi-'.$what.'-'.date('Ymd').'.csv"');
    $out = fopen('php://output', 'w');
    if ($what === 'users') {
        fputcsv($out, ['id','email','first_name','last_name','company','website','mobile','country','use_case','industry','heard_from','provider','status','licenses','spent_inr','created_at']);
        foreach ($db->query("SELECT u.*, (SELECT COUNT(*) FROM licenses WHERE user_id=u.id) lic, (SELECT COALESCE(SUM(amount_inr),0) FROM payments WHERE user_id=u.id AND status='paid') spent FROM users u ORDER BY u.id DESC") as $r)
            fputcsv($out, [$r['id'],$r['email'],$r['first_name'],$r['last_name'],$r['company'],$r['website'],$r['mobile'],$r['country'],$r['use_case'],$r['industry'],$r['heard_from'],$r['provider'],$r['status'],$r['lic'],$r['spent'],$r['created_at']]);
    } elseif ($what === 'licenses') {
        fputcsv($out, ['id','key_prefix','owner_email','plan','status','activations','max_activations','expires_at','created_at']);
        foreach ($db->query("SELECT l.*, p.name plan_name, u.email, (SELECT COUNT(*) FROM license_activations WHERE license_id=l.id AND status='active') acts FROM licenses l JOIN plans p ON p.id=l.plan_id JOIN users u ON u.id=l.user_id ORDER BY l.id DESC") as $r)
            fputcsv($out, [$r['id'],$r['key_prefix'],$r['email'],$r['plan_name'],$r['status'],$r['acts'],$r['max_activations'],$r['expires_at'],$r['created_at']]);
    } else {
        fputcsv($out, ['id','user_email','plan','amount_inr','status','gateway','gateway_payment_id','created_at','paid_at']);
        foreach ($db->query("SELECT pay.*, p.name plan_name, u.email FROM payments pay JOIN plans p ON p.id=pay.plan_id JOIN users u ON u.id=pay.user_id ORDER BY pay.id DESC") as $r)
            fputcsv($out, [$r['id'],$r['email'],$r['plan_name'],$r['amount_inr'],$r['status'],$r['gateway'],$r['gateway_payment_id'],$r['created_at'],$r['paid_at']]);
    }
    fclose($out); exit;
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Saathi</title><link rel="icon" href="<?=$IMG['logo']?>">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/app.css">
</head><body>
<header class="topbar"><div class="wrap">
  <a class="brand" href="admin.php"><img src="<?=$IMG['logo']?>" alt="" style="width:30px;height:30px">Saathi <span class="badge v" style="margin-left:4px">ADMIN</span></a>
  <div class="sp"><span class="who"><?=e($a['username'])?> · <?=e($a['role'])?></span><a class="btn btn-ghost" href="logout.php" style="padding:8px 16px">Log out</a></div>
</div></header>
<div class="dash">
  <div class="adminnav"><?php foreach ($tabs as $k=>$lbl): ?><a href="admin.php?tab=<?=$k?>" class="<?=$tab===$k?'on':''?>"><?=$lbl?></a><?php endforeach; ?></div>
  <?php if ($flash): ?><div class="msg ok"><?=e($flash)?></div><?php endif; ?>

  <?php if ($tab==='overview'):
    $rev = (int)$db->query("SELECT COALESCE(SUM(amount_inr),0) FROM payments WHERE status='paid'")->fetchColumn();
    $activeLic = n("SELECT COUNT(*) FROM licenses WHERE status='active' AND (expires_at IS NULL OR expires_at>NOW())");
    $totUsers = n("SELECT COUNT(*) FROM users");
    $new7 = n("SELECT COUNT(*) FROM users WHERE created_at >= (NOW() - INTERVAL 7 DAY)");
    $active30 = n("SELECT COUNT(*) FROM users WHERE last_login_at >= (NOW() - INTERVAL 30 DAY)");
    $paid = n("SELECT COUNT(*) FROM payments WHERE status='paid'");
    $trend = $db->query("SELECT DATE(created_at) d, COUNT(*) c FROM users WHERE created_at >= (CURDATE() - INTERVAL 13 DAY) GROUP BY DATE(created_at)")->fetchAll(PDO::FETCH_KEY_PAIR);
    $days = []; for ($i=13;$i>=0;$i--){ $dd=date('Y-m-d', strtotime("-$i day")); $days[$dd]=(int)($trend[$dd] ?? 0); }
    $maxd = max(1, max($days));
    $logins = $db->query("SELECT * FROM audit_log WHERE action IN ('user_login','admin_login','user_signup','profile_completed') ORDER BY id DESC LIMIT 12")->fetchAll();
  ?>
    <h1>Overview</h1><p class="muted">Live platform metrics. Payment mode: <strong><?=e(payment_mode())?></strong> · Email: <strong><?=mailer_configured()?'configured':'dev mode'?></strong></p>
    <div class="stats">
      <div class="stat"><b><?=$totUsers?></b><span>Total users <?=$new7?'· +'.$new7.' (7d)':''?></span></div>
      <div class="stat"><b><?=$active30?></b><span>Active users (30d)</span></div>
      <div class="stat"><b><?=$activeLic?></b><span>Active keys running</span></div>
      <div class="stat"><b>₹<?=number_format($rev)?></b><span>Revenue · <?=$paid?> paid</span></div>
    </div>
    <div class="panel"><h3>Signups — last 14 days <span class="small" style="color:var(--muted)">(+<?=$new7?> this week)</span></h3>
      <div style="display:flex;align-items:flex-end;gap:5px;height:96px;margin-top:12px">
        <?php foreach ($days as $d=>$c): ?>
          <div title="<?=e($d)?>: <?=$c?> signup(s)" style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:5px">
            <div style="width:100%;background:linear-gradient(180deg,var(--v),#7c3aed);border-radius:4px 4px 0 0;height:<?=max(3,(int)round($c/$maxd*70))?>px"></div>
            <span style="font-size:9px;color:var(--muted)"><?=date('d',strtotime($d))?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="panel"><h3>Recent activity</h3>
      <table><tr><th>When</th><th>Type</th><th>Action</th><th>IP</th></tr>
      <?php foreach ($logins as $l): ?><tr><td class="small"><?=e($l['created_at'])?></td><td><span class="badge <?=$l['actor_type']==='admin'?'v':'g'?>"><?=e($l['actor_type'])?></span></td><td><?=e($l['action'])?></td><td class="small"><?=e($l['ip'])?></td></tr><?php endforeach; ?>
      </table>
    </div>

  <?php elseif ($tab==='users'):
    $detail = null;
    if (($uid = (int)($_GET['user'] ?? 0)) > 0) { $st=$db->prepare("SELECT * FROM users WHERE id=?"); $st->execute([$uid]); $detail=$st->fetch() ?: null; }
    if ($q !== '') {
        $like = '%'.$q.'%';
        $st = $db->prepare("SELECT u.*, (SELECT COUNT(*) FROM licenses WHERE user_id=u.id) lic, (SELECT COALESCE(SUM(amount_inr),0) FROM payments WHERE user_id=u.id AND status='paid') spent FROM users u WHERE u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.company LIKE ? OR u.website LIKE ? OR u.mobile LIKE ? ORDER BY u.id DESC LIMIT 200");
        $st->execute([$like,$like,$like,$like,$like,$like]); $users = $st->fetchAll();
    } else {
        $users = $db->query("SELECT u.*, (SELECT COUNT(*) FROM licenses WHERE user_id=u.id) lic, (SELECT COALESCE(SUM(amount_inr),0) FROM payments WHERE user_id=u.id AND status='paid') spent FROM users u ORDER BY u.id DESC LIMIT 200")->fetchAll();
    }
  ?>
    <h1>Users</h1><p class="muted">Search, open a full profile, export to CSV, and manage accounts.</p>

    <?php if ($detail):
        $dpaid = (int)$db->query("SELECT COALESCE(SUM(amount_inr),0) FROM payments WHERE status='paid' AND user_id=".(int)$detail['id'])->fetchColumn();
        $dkeys = (int)$db->query("SELECT COUNT(*) FROM licenses WHERE user_id=".(int)$detail['id'])->fetchColumn();
        $drows = ['Email'=>$detail['email'],'Mobile'=>$detail['mobile'],'Company'=>$detail['company'],'Website'=>$detail['website'],'Country'=>$detail['country'],'Use case'=>$detail['use_case'],'Industry'=>$detail['industry'],'Heard from'=>$detail['heard_from'],'Address'=>$detail['address'],'Provider'=>$detail['provider'],'Status'=>$detail['status'],'Joined'=>$detail['created_at'],'Last login'=>$detail['last_login_at'],'Goal'=>$detail['goal']];
    ?>
    <div class="panel" style="border:2px solid var(--v)">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><h3 style="margin:0">User #<?=(int)$detail['id']?> · <?=e(trim((($detail['first_name']??'').' '.($detail['last_name']??''))) ?: ($detail['email'] ?: '—'))?></h3><a class="btn btn-ghost" href="admin.php?tab=users<?=$q!==''?'&q='.urlencode($q):''?>" style="padding:6px 12px">✕ Close</a></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 18px;margin-top:10px">
        <?php foreach ($drows as $k=>$v) if (trim((string)$v)!=='') echo '<div style="display:flex;gap:8px"><span style="color:var(--muted);min-width:92px;font-size:13px">'.e($k).'</span><span style="font-weight:600;font-size:13.5px">'.e((string)$v).'</span></div>'; ?>
      </div>
      <p class="small" style="margin-top:12px"><b><?=$dkeys?></b> license(s) · spent <b>₹<?=number_format($dpaid)?></b></p>
    </div>
    <?php endif; ?>

    <div class="panel">
      <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
        <form method="get" style="display:flex;gap:8px;margin:0"><input type="hidden" name="tab" value="users"><input name="q" value="<?=e($q)?>" placeholder="Search email, name, company, domain…" style="border:1.5px solid var(--line);border-radius:10px;padding:9px 13px;font:inherit;min-width:230px"><button class="btn btn-ghost" style="padding:9px 16px">Search</button><?php if($q!==''): ?><a class="btn btn-ghost" href="admin.php?tab=users" style="padding:9px 14px">Clear</a><?php endif; ?></form>
        <a class="btn btn-primary" href="admin.php?tab=users&export=csv" style="padding:9px 16px">⬇ Export CSV</a>
      </div>
      <table><tr><th>ID</th><th>Login</th><th>Name</th><th>Company</th><th>Via</th><th>Keys</th><th>Spent</th><th>Status</th><th>Joined</th><th></th></tr>
      <?php if (!$users): ?><tr><td colspan="10" class="small">No users found<?=$q!==''?' for "'.e($q).'"':''?>.</td></tr><?php endif; ?>
      <?php foreach ($users as $u): $uname = trim((($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))); ?><tr>
        <td><a href="admin.php?tab=users&user=<?=(int)$u['id']?>" style="color:var(--v);font-weight:700">#<?=(int)$u['id']?></a></td>
        <td><?=e($u['email'] ?: $u['phone'])?><?php if (!empty($u['mobile'])): ?><div class="small"><?=e($u['mobile'])?></div><?php endif; ?></td>
        <td><a href="admin.php?tab=users&user=<?=(int)$u['id']?>" style="color:var(--ink)"><?=e($uname ?: '—')?></a></td>
        <td><?=e($u['company'] ?? '') ?: '—'?><?php if (!empty($u['website'])): ?><div class="small"><?=e($u['website'])?></div><?php endif; ?></td>
        <td class="small"><?=e($u['provider'])?></td>
        <td><?=(int)$u['lic']?></td><td>₹<?=number_format((int)$u['spent'])?></td>
        <td><span class="badge <?=$u['status']==='active'?'g':'r'?>"><?=e($u['status'])?></span></td>
        <td class="small"><?=e(date('d M Y',strtotime($u['created_at'])))?></td>
        <td><form method="post" style="margin:0" onsubmit="return confirm('<?=$u['status']==='active'?'Block':'Unblock'?> this user?')"><?=csrf_field()?><input type="hidden" name="action" value="block_user"><input type="hidden" name="id" value="<?=(int)$u['id']?>"><input type="hidden" name="to" value="<?=$u['status']==='active'?'block':'unblock'?>"><button class="btn btn-ghost" style="padding:5px 10px;font-size:12px"><?=$u['status']==='active'?'Block':'Unblock'?></button></form></td>
      </tr><?php endforeach; ?></table>
    </div>

  <?php elseif ($tab==='licenses'):
    $lic = $db->query("SELECT l.*, p.name plan_name, u.email, u.phone, (SELECT COUNT(*) FROM license_activations WHERE license_id=l.id AND status='active') acts FROM licenses l JOIN plans p ON p.id=l.plan_id JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 300")->fetchAll();
  ?>
    <h1>Licenses</h1><p class="muted">All issued keys, owners, activations and expiry. <a class="btn btn-ghost" href="admin.php?tab=licenses&export=csv" style="padding:5px 12px;font-size:13px;margin-left:8px">⬇ CSV</a></p>
    <div class="panel"><table><tr><th>Key</th><th>Owner</th><th>Plan</th><th>Status</th><th>Activations</th><th>Expires</th><th></th></tr>
      <?php foreach ($lic as $l): $valid=$l['status']==='active'&&($l['expires_at']===null||strtotime($l['expires_at'])>time()); ?><tr>
        <td><code><?=e($l['key_prefix'])?></code></td><td class="small"><?=e($l['email'] ?: $l['phone'])?></td><td><?=e($l['plan_name'])?></td>
        <td><span class="badge <?=$valid?'g':'r'?>"><?=$valid?'active':e($l['status'])?></span></td>
        <td><?=(int)$l['acts']?>/<?=(int)$l['max_activations']?></td>
        <td class="small"><?=$l['expires_at']===null?'Lifetime':e(date('d M Y',strtotime($l['expires_at'])))?></td>
        <td><?php if($l['status']!=='revoked'): ?><form method="post" style="margin:0" onsubmit="return confirm('Revoke this license?')"><?=csrf_field()?><input type="hidden" name="action" value="revoke_license"><input type="hidden" name="id" value="<?=(int)$l['id']?>"><button class="btn btn-ghost" style="padding:5px 10px;font-size:12px;color:#b3261e">Revoke</button></form><?php endif; ?></td>
      </tr><?php endforeach; ?></table></div>

  <?php elseif ($tab==='payments'):
    $pays = $db->query("SELECT pay.*, p.name plan_name, u.email, u.phone FROM payments pay JOIN plans p ON p.id=pay.plan_id JOIN users u ON u.id=pay.user_id ORDER BY pay.id DESC LIMIT 300")->fetchAll();
  ?>
    <h1>Payments</h1><p class="muted">All payment intents and their outcome. <a class="btn btn-ghost" href="admin.php?tab=payments&export=csv" style="padding:5px 12px;font-size:13px;margin-left:8px">⬇ CSV</a></p>
    <div class="panel"><table><tr><th>ID</th><th>User</th><th>Plan</th><th>Amount</th><th>Status</th><th>Gateway</th><th>When</th></tr>
      <?php foreach ($pays as $p): ?><tr><td>#<?=(int)$p['id']?></td><td class="small"><?=e($p['email'] ?: $p['phone'])?></td><td><?=e($p['plan_name'])?></td><td>₹<?=number_format((int)$p['amount_inr'])?></td><td><span class="badge <?=$p['status']==='paid'?'g':($p['status']==='failed'?'r':'m')?>"><?=e($p['status'])?></span></td><td class="small"><?=e($p['gateway'])?></td><td class="small"><?=e(date('d M Y H:i',strtotime($p['created_at'])))?></td></tr><?php endforeach; ?></table></div>

  <?php elseif ($tab==='pricing'): $plans = plans_all(false); ?>
    <h1>Pricing</h1><p class="muted">Edit plan names, prices (₹), activation limits and visibility.</p>
    <?php foreach ($plans as $p): ?>
    <div class="panel"><form method="post" class="inline-form"><?=csrf_field()?>
      <input type="hidden" name="action" value="save_plan"><input type="hidden" name="id" value="<?=(int)$p['id']?>">
      <div class="field"><label>Code</label><input value="<?=e($p['code'])?>" disabled style="width:110px;opacity:.6"></div>
      <div class="field"><label>Name</label><input name="name" value="<?=e($p['name'])?>" style="width:160px"></div>
      <div class="field"><label>Price ₹</label><input name="price" type="number" value="<?=(int)$p['price_inr']?>" style="width:110px"></div>
      <div class="field"><label>Max sites</label><input name="acts" type="number" value="<?=(int)$p['max_activations']?>" style="width:90px"></div>
      <div class="field"><label>Active</label><input type="checkbox" name="active" <?=$p['active']?'checked':''?>></div>
      <button class="btn btn-primary" style="padding:11px 18px">Save</button>
    </form></div>
    <?php endforeach; ?>

  <?php elseif ($tab==='admins'): $aemails = admin_emails(); $meEmail = mb_strtolower((string)($a['email'] ?? '')); ?>
    <h1>Admins</h1><p class="muted">Anyone who signs in (email code or Google) with one of these addresses gets the Admin panel. Everyone else gets the user dashboard.</p>
    <div class="panel"><table><tr><th>Admin email</th><th></th></tr>
      <?php foreach ($aemails as $em): ?>
      <tr><td><?=e($em)?><?= $em===$meEmail ? ' <span class="badge v">you</span>' : '' ?></td>
        <td style="text-align:right"><?php if ($em !== $meEmail): ?>
          <form method="post" onsubmit="return confirm('Remove admin access for <?=e($em)?>?')" style="display:inline">
            <?=csrf_field()?><input type="hidden" name="action" value="remove_admin_email"><input type="hidden" name="email" value="<?=e($em)?>">
            <button class="btn btn-ghost" style="padding:6px 12px;color:#c0392b">Remove</button>
          </form>
        <?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$aemails): ?><tr><td colspan="2" class="small">No admin emails set yet — the next person to sign in becomes the owner.</td></tr><?php endif; ?>
    </table></div>
    <div class="panel"><h3>Add an admin</h3>
      <form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="add_admin_email">
        <div class="field"><label>Email address</label><input name="email" type="email" placeholder="person@example.com" required></div>
        <button class="btn btn-primary" style="padding:11px 18px">Add admin</button>
      </form>
    </div>

  <?php elseif ($tab==='audit'): $logs = $db->query("SELECT * FROM audit_log ORDER BY id DESC LIMIT 100")->fetchAll(); ?>
    <h1>Audit log</h1><p class="muted">Every sensitive action is recorded for security.</p>
    <div class="panel"><table><tr><th>When</th><th>Actor</th><th>Action</th><th>Details</th><th>IP</th></tr>
      <?php foreach ($logs as $l): ?><tr><td class="small"><?=e($l['created_at'])?></td><td><span class="badge <?=$l['actor_type']==='admin'?'v':($l['actor_type']==='user'?'g':'m')?>"><?=e($l['actor_type'])?><?=$l['actor_id']?(' #'.(int)$l['actor_id']):''?></span></td><td><?=e($l['action'])?></td><td class="small" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=e($l['meta'])?></td><td class="small"><?=e($l['ip'])?></td></tr><?php endforeach; ?></table></div>

  <?php elseif ($tab==='settings'):
    $mask = function($k){ $v=setting_get($k,null); if($v) return 'set ••••'.substr($v,-4); return getenv($k)?'from env':'not set'; };
  ?>
    <h1>Settings</h1><p class="muted">Configure payment gateway, email provider and renewal reminders. Leave a field blank to keep its current value.</p>
    <div class="panel"><h3>Payment gateway (Razorpay)</h3>
      <p class="small">Current: Key ID <b><?=e($mask('RAZORPAY_KEY_ID'))?></b> · Secret <b><?=e($mask('RAZORPAY_KEY_SECRET'))?></b>. When both are set, live Razorpay checkout replaces TEST mode automatically.</p>
      <form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="save_settings">
        <div class="field"><label>Razorpay Key ID</label><input name="RAZORPAY_KEY_ID" placeholder="rzp_live_..." style="width:220px"></div>
        <div class="field"><label>Razorpay Key Secret</label><input name="RAZORPAY_KEY_SECRET" type="password" style="width:220px"></div>
        <button class="btn btn-primary" style="padding:11px 18px">Save</button>
      </form>
    </div>
    <div class="panel"><h3>Email (OTP delivery)</h3>
      <p class="small">Current: Brevo <b><?=e($mask('BREVO_API_KEY'))?></b> · Resend <b><?=e($mask('RESEND_API_KEY'))?></b>. Without a key, OTP runs in dev mode (code shown on screen).</p>
      <form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="save_settings">
        <div class="field"><label>Brevo API key</label><input name="BREVO_API_KEY" type="password" style="width:220px"></div>
        <div class="field"><label>Resend API key</label><input name="RESEND_API_KEY" type="password" style="width:220px"></div>
        <div class="field"><label>From email</label><input name="MAIL_FROM" placeholder="no-reply@yourdomain" style="width:200px"></div>
        <button class="btn btn-primary" style="padding:11px 18px">Save</button>
      </form>
    </div>
    <div class="panel"><h3>License renewals</h3>
      <p class="small">Send a reminder this many days before expiry. Current: <b><?=e(setting_get('REMINDER_DAYS', getenv('REMINDER_DAYS') ?: '7'))?></b> days.</p>
      <form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="save_settings">
        <div class="field"><label>Reminder days</label><input name="REMINDER_DAYS" type="number" placeholder="7" style="width:110px"></div>
        <button class="btn btn-primary" style="padding:11px 18px">Save</button>
      </form>
    </div>
  <?php endif; ?>
</div></body></html>
