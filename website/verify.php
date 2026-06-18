<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';
$IMG = require __DIR__ . '/assets/images.php';

$pending = $_SESSION['otp_pending'] ?? null;
if (!$pending) redirect('login.php');
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'resend') {
        $r = otp_start($pending['channel'], $pending['dest']);
        if (isset($r['dev_code'])) $_SESSION['otp_dev'] = $r['dev_code'];
        $err = $r['ok'] ? '' : ($r['error'] ?? '');
        if ($r['ok']) $err = '';
    } else {
        $r = otp_verify($pending['channel'], $pending['dest'], $_POST['code'] ?? '');
        if ($r['ok']) {
            login_user($pending['channel'], $pending['dest'], $pending['channel']);
            unset($_SESSION['otp_pending'], $_SESSION['otp_dev']);
            $u = current_user();
            if (is_admin_email($u['email'] ?? '')) { unset($_SESSION['next']); redirect('admin.php'); }
            if (!profile_complete($u)) redirect('profile.php');   // keep 'next' for after onboarding
            $next = $_SESSION['next'] ?? 'dashboard.php';
            unset($_SESSION['next']);
            redirect($next);
        }
        $err = $r['error'];
    }
}
$dev = $_SESSION['otp_dev'] ?? null;
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify code — Saathi</title><link rel="icon" href="<?=$IMG['logo']?>">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/app.css">
</head><body><div class="auth-wrap"><div class="auth-card">
  <a class="brand" href="index.php" style="justify-content:center;margin-bottom:14px"><img src="<?=$IMG['logo']?>" alt="" style="width:34px;height:34px">Saathi</a>
  <h2>Enter your code</h2>
  <p class="auth-sub">We sent a 6-digit code to <strong><?=e($pending['dest'])?></strong>.</p>
  <?php if ($err): ?><div class="msg err"><?=e($err)?></div><?php endif; ?>
  <?php if ($dev): ?><div class="msg dev">Dev mode (no email/SMS provider set): your code is <strong style="letter-spacing:3px"><?=e($dev)?></strong></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?=csrf_field()?>
    <div class="field"><input class="otp-input" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="••••••" required autofocus></div>
    <button class="btn btn-primary btn-block">Verify &amp; continue</button>
  </form>
  <form method="post" style="margin-top:10px"><?=csrf_field()?><input type="hidden" name="action" value="resend">
    <button class="btn btn-ghost btn-block">Resend code</button>
  </form>
  <p class="small" style="text-align:center;margin-top:14px"><a href="login.php" style="color:var(--v)">← Use a different method</a></p>
</div></div></body></html>
