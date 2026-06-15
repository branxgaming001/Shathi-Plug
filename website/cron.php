<?php
/**
 * Scheduled maintenance: expire due licenses + email renewal reminders.
 * Protect with a token:  /cron.php?token=YOUR_CRON_TOKEN  (set CRON_TOKEN env).
 * Run daily (e.g. an external cron / uptime ping).
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/payments.php';
require __DIR__ . '/includes/mailer.php';
header('Content-Type: application/json');

$token = cfg('CRON_TOKEN', '');
if ($token === '' || !hash_equals((string)$token, (string)($_GET['token'] ?? ''))) {
    json_out(['ok' => false, 'error' => 'unauthorized'], 403);
}

$expired = expire_due_licenses();

$days = (int)cfg('REMINDER_DAYS', 7);
$base = (getenv('PUBLIC_URL') ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')));
$sent = 0;
foreach (licenses_expiring($days) as $l) {
    // one reminder per day per license
    if ($l['reminded_on'] === date('Y-m-d')) continue;
    if (!empty($l['email'])) {
        $link = $base . '/renew.php?license=' . (int)$l['id'];
        $html = '<div style="font-family:system-ui,sans-serif;max-width:480px;margin:auto">'
            . '<h2 style="color:#6D5DFB">Your Saathi ' . e($l['plan_name']) . ' is expiring soon</h2>'
            . '<p>Key <b>' . e($l['key_prefix']) . '</b> expires on <b>' . e(date('d M Y', strtotime($l['expires_at']))) . '</b>.</p>'
            . '<p><a href="' . e($link) . '" style="background:#6D5DFB;color:#fff;padding:12px 22px;border-radius:10px;text-decoration:none;display:inline-block">Renew now</a></p>'
            . '<p style="color:#888;font-size:12px">Renew before expiry to avoid any interruption.</p></div>';
        send_email($l['email'], 'Your Saathi license is expiring soon', $html);
    }
    pdo()->prepare("UPDATE licenses SET reminded_on=CURDATE() WHERE id=?")->execute([(int)$l['id']]);
    audit('reminder_sent', ['license_id' => (int)$l['id']], 'system');
    $sent++;
}
json_out(['ok' => true, 'expired' => $expired, 'reminders_sent' => $sent]);
