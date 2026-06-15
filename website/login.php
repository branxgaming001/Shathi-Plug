<?php $IMG = require __DIR__ . '/assets/images.php'; ?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — Saathi</title>
<link rel="icon" href="<?=$IMG['logo']?>">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@700;800&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
<section class="hero" style="min-height:100vh;display:flex;align-items:center">
  <div class="wrap" style="display:block;max-width:440px;text-align:center">
    <a class="brand" href="index.php" style="justify-content:center;margin-bottom:18px"><img src="<?=$IMG['logo']?>" alt="">Saathi</a>
    <div class="card" style="box-shadow:var(--shadow)">
      <h2 style="font-size:24px">Secure sign in</h2>
      <p style="color:var(--ink2);margin:10px 0 20px">Login with Google, email or phone — every account verified by OTP. This secure login &amp; checkout is being wired up in the next phase.</p>
      <div style="display:flex;flex-direction:column;gap:10px">
        <button class="btn btn-ghost" disabled style="opacity:.6">Continue with Google</button>
        <button class="btn btn-ghost" disabled style="opacity:.6">Continue with Email (OTP)</button>
        <button class="btn btn-ghost" disabled style="opacity:.6">Continue with Phone (OTP)</button>
      </div>
      <p style="font-size:12px;color:var(--muted);margin-top:18px">Coming in Phase 2 · admin & user dashboards in Phase 3</p>
      <a class="btn btn-primary" href="index.php" style="margin-top:8px">← Back to site</a>
    </div>
  </div>
</section>
</body></html>
