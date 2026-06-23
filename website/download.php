<?php
/**
 * Gated plugin download. The user must be signed in, have a completed profile,
 * AND have chosen a plan (i.e. hold at least one licence) before the plugin
 * zip is served. Direct access to downloads/*.zip is blocked by .htaccess, so
 * this script is the only way to obtain the file.
 */
require __DIR__ . '/includes/bootstrap.php';
$u = require_login();
require_profile($u);

// Must have chosen a plan (any licence — free counts) to unlock the download.
$has = pdo()->prepare("SELECT COUNT(*) FROM licenses WHERE user_id=?");
$has->execute([(int)$u['id']]);
if ((int)$has->fetchColumn() === 0) {
    audit('plugin_download_blocked', ['user_id' => (int)$u['id'], 'reason' => 'no_plan'], 'user', (int)$u['id']);
    redirect('pricing.php?need=plan');
}

$file = __DIR__ . '/downloads/saathi-agentic-ai.zip';
if (!is_file($file)) {
    http_response_code(404);
    exit('Plugin file is not available right now. Please contact support.');
}

audit('plugin_download', ['user_id' => (int)$u['id']], 'user', (int)$u['id']);

// Stream the zip.
if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { ob_end_clean(); } }
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="saathi-agentic-ai.zip"');
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($file);
exit;
