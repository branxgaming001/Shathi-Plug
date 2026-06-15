<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';
$IMG = require __DIR__ . '/assets/images.php';

if (current_admin()) redirect('admin.php');
if (current_user())  redirect('dashboard.php');

$err = ''; $adminTab = isset($_GET['admin']);
if (!empty($_GET['next'])) $_SESSION['next'] = $_GET['next'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'user_otp') {
        $channel = (($_POST['channel'] ?? 'email') === 'phone') ? 'phone' : 'email';
        $r = otp_start($channel, $_POST['dest'] ?? '');
        if ($r['ok']) {
            $_SESSION['otp_pending'] = ['channel' => $channel, 'dest' => mb_strtolower(trim($_POST['dest']))];
            if (isset($r['dev_code'])) $_SESSION['otp_dev'] = $r['dev_code'];
            redirect('verify.php');
        }
        $err = $r['error'] ?? 'Could not send the code.';
    } elseif ($action === 'admin') {
        $r = login_admin($_POST['username'] ?? '', $_POST['password'] ?? '');
        if (!empty($r['ok'])) redirect('admin.php');
        $err = $r['error']; $adminTab = true;
    }
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — Saathi</title><link rel="icon" href="<?=$IMG['logo']?>">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/app.css">
</head><body><div class="auth-wrap"><div class="auth-card">
  <a class="brand" href="index.php" style="justify-content:center;margin-bottom:14px"><img src="<?=$IMG['logo']?>" alt="" style="width:34px;height:34px">Saathi</a>
  <div class="tabs">
    <a href="login.php" class="<?=$adminTab?'':'on'?>">User</a>
    <a href="login.php?admin=1" class="<?=$adminTab?'on':''?>">Admin</a>
  </div>
  <?php if ($err): ?><div class="msg err"><?=e($err)?></div><?php endif; ?>

  <?php if (!$adminTab): ?>
    <h2>Welcome 👋</h2>
    <p class="auth-sub">Sign in or create your account — verified by OTP.</p>
    <form method="post" autocomplete="off">
      <?=csrf_field()?><input type="hidden" name="action" value="user_otp"><input type="hidden" name="channel" value="email">
      <div class="field"><label>Email address</label><input type="email" name="dest" placeholder="you@example.com" required></div>
      <button class="btn btn-primary btn-block">Send code to email</button>
    </form>
    <div class="divider">or</div>
    <form method="post" autocomplete="off">
      <?=csrf_field()?><input type="hidden" name="action" value="user_otp"><input type="hidden" name="channel" value="phone">
      <div class="field"><label>Phone number</label><input type="tel" name="dest" placeholder="+91 98765 43210" required></div>
      <button class="btn btn-ghost btn-block">Send code to phone</button>
    </form>
    <div class="divider">or</div>
    <button class="btn btn-ghost btn-block" disabled style="opacity:.55" title="Coming soon">Continue with Google</button>
    <p class="small" style="text-align:center;margin-top:14px">Phone &amp; email codes work now. Google sign-in arrives soon.</p>
  <?php else: ?>
    <h2>Admin sign in</h2>
    <p class="auth-sub">Restricted area — staff only.</p>
    <form method="post" autocomplete="off">
      <?=csrf_field()?><input type="hidden" name="action" value="admin">
      <div class="field"><label>Username</label><input type="text" name="username" required></div>
      <div class="field"><label>Password</label><input type="password" name="password" required></div>
      <button class="btn btn-primary btn-block">Sign in</button>
    </form>
  <?php endif; ?>
  <p class="small" style="text-align:center;margin-top:16px"><a href="index.php" style="color:var(--v)">← Back to site</a></p>
</div></div></body></html>
