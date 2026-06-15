<?php
require __DIR__ . '/db.php';

$cfg     = (require __DIR__ . '/config.php')['db'];
$dbOk    = false;
$dbVer   = '';
$dbNote  = '';
try {
    $dbVer = db()->query('SELECT VERSION()')->fetchColumn();
    $dbOk  = true;
} catch (Throwable $e) {
    // Don't crash the page if the DB isn't attached yet — show a friendly note.
    $dbNote = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Saathi Website — Live</title>
  <style>
    body{font-family:'Plus Jakarta Sans',system-ui,sans-serif;margin:0;background:linear-gradient(135deg,#ece8ff,#fdeee9);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
    .card{background:#fff;border-radius:20px;box-shadow:0 18px 50px rgba(80,60,200,.18);padding:34px 30px;max-width:520px;width:100%}
    h1{margin:.2rem 0 .4rem;font-size:1.5rem;color:#1f1147}
    .pill{display:inline-block;font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px}
    .ok{background:#e7f9f0;color:#0e9f6e}.warn{background:#fff4e5;color:#b25e00}
    .row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f0eefb;font-size:14px}
    .row b{color:#6D5DFB}
    .muted{color:#8a86a3;font-size:12.5px;margin-top:14px;line-height:1.5}
  </style>
</head>
<body>
  <div class="card">
    <h1>Saathi website is live 🚀</h1>
    <p class="pill <?= $dbOk ? 'ok' : 'warn' ?>"><?= $dbOk ? 'PHP + MySQL connected' : 'PHP running · database pending' ?></p>
    <div style="margin-top:18px">
      <div class="row"><span>PHP version</span><b><?= htmlspecialchars(PHP_VERSION) ?></b></div>
      <div class="row"><span>Database host</span><b><?= htmlspecialchars($cfg['host']) ?>:<?= htmlspecialchars($cfg['port']) ?></b></div>
      <div class="row"><span>Database</span><b><?= $dbOk ? htmlspecialchars($dbVer) : 'not connected yet' ?></b></div>
    </div>
    <?php if (!$dbOk): ?>
      <p class="muted">Add a MySQL service in Railway and reference its variables on this service — the page will turn green automatically on next load.</p>
    <?php else: ?>
      <p class="muted">Deployment pipeline working. Ready to build the real website on this same live URL.</p>
    <?php endif; ?>
  </div>
</body>
</html>
