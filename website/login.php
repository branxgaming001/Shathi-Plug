<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';
$IMG = require __DIR__ . '/assets/images.php';

// One login for everyone — admins and users land on the right place automatically.
$u = current_user();
if ($u) {
    if (is_admin_email($u['email'] ?? '')) redirect('admin.php');
    redirect(profile_complete($u) ? 'dashboard.php' : 'profile.php');
}

$err = '';
if (!empty($_GET['next'])) $_SESSION['next'] = $_GET['next'];
if (!empty($_GET['gerr'])) $err = 'Google sign-in did not complete. Try again, or use an email code.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'user_otp') {
        $r = otp_start('email', $_POST['dest'] ?? '');
        if ($r['ok']) {
            $_SESSION['otp_pending'] = ['channel' => 'email', 'dest' => mb_strtolower(trim($_POST['dest'] ?? ''))];
            if (isset($r['dev_code'])) $_SESSION['otp_dev'] = $r['dev_code'];
            redirect('verify.php');
        }
        $err = $r['error'] ?? 'Could not send the code.';
    }
}
$googleOn = (string) cfg('GOOGLE_CLIENT_ID', '') !== '';
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — Saathi</title><link rel="icon" href="<?=$IMG['logo']?>">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/app.css">
<style>.gbtn{display:flex;align-items:center;justify-content:center;gap:10px;font-weight:700}.gbtn svg{flex:0 0 auto}</style>
</head><body><div class="auth-wrap"><div class="auth-card">
  <a class="brand" href="/" style="justify-content:center;margin-bottom:14px"><img src="<?=$IMG['logo']?>" alt="" style="width:34px;height:34px">Saathi</a>
  <h2>Welcome 👋</h2>
  <p class="auth-sub">Sign in or create your account. The same login works for everyone — you'll land on the right dashboard automatically.</p>
  <?php if ($err): ?><div class="msg err"><?=e($err)?></div><?php endif; ?>

  <?php if ($googleOn): ?>
    <a class="btn btn-ghost btn-block gbtn" href="google_auth.php">
      <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.4 29.2 35.5 24 35.5c-7.2 0-13-5.8-13-13s5.8-13 13-13c3.3 0 6.3 1.2 8.6 3.3l5.7-5.7C34.6 3.5 29.6 1.5 24 1.5 12.4 1.5 3 10.9 3 22.5S12.4 43.5 24 43.5c11 0 20.5-8 20.5-21 0-1.4-.2-2.7-.9-2z"/><path fill="#FF3D00" d="M6.3 13.7l6.6 4.8C14.7 14.5 19 11.5 24 11.5c3.3 0 6.3 1.2 8.6 3.3l5.7-5.7C34.6 5.5 29.6 3.5 24 3.5 16 3.5 9.1 8.1 6.3 13.7z"/><path fill="#4CAF50" d="M24 43.5c5.5 0 10.5-2.1 14.2-5.6l-6.4-5.2c-2 1.5-4.6 2.3-7.8 2.3-5.2 0-9.6-3.3-11.2-7.9l-6.5 5C9 38.8 15.9 43.5 24 43.5z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.7 2-2 3.8-3.7 5.1l6.4 5.2c-.5.4 6.5-4.7 6.5-15.8 0-1.4-.2-2.7-.9-2z"/></svg>
      Continue with Google
    </a>
    <div class="divider">or</div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <?=csrf_field()?><input type="hidden" name="action" value="user_otp">
    <div class="field"><label>Email address</label><input type="email" name="dest" placeholder="you@example.com" required></div>
    <button class="btn btn-primary btn-block">Email me a sign-in code</button>
  </form>

  <p class="small" style="text-align:center;margin-top:16px"><a href="/" style="color:var(--v)">← Back to site</a></p>
</div></div></body></html>
