<?php
/**
 * alpha.php — Saathi Auto-Deploy for Hostinger
 * Place at: /public_html/saathi/alpha.php
 *
 * Trigger: GET alpha.php?token=saathi_deploy_alpha_2026
 * Or via GitHub webhook POST with X-Hub-Signature-256
 */
$DEPLOY_TOKEN = 'saathi_deploy_alpha_2026';
$REPO_URL     = 'https://github.com/branxgaming001/Shathi-Plug.git';
$BRANCH       = 'main';
$DEPLOY_PATH  = __DIR__ . '/website';
$LOG_FILE     = __DIR__ . '/deploy.log';

function log_msg($msg) {
    global $LOG_FILE;
    file_put_contents($LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function out($msg, $color = '#3b82f6') {
    echo "<div style='padding:6px 14px;margin:3px 0;border-left:4px solid $color;background:#f8fafc;border-radius:0 6px 6px 0;font-family:monospace;font-size:13px;'>$msg</div>\n";
    log_msg($msg);
}

function auth_ok() {
    global $DEPLOY_TOKEN;
    $t = $_GET['token'] ?? $_POST['token'] ?? '';
    if ($t === $DEPLOY_TOKEN) return true;
    $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if ($sig !== '') {
        $payload = file_get_contents('php://input');
        return hash_equals('sha256=' . hash_hmac('sha256', $payload, $DEPLOY_TOKEN), $sig);
    }
    return false;
}

header('Content-Type: text/html; charset=utf-8');
if (!auth_ok()) {
    http_response_code(403);
    echo "<h1>403 — Invalid token</h1><p>Usage: alpha.php?token=YOUR_TOKEN</p>";
    exit;
}

echo "<!DOCTYPE html><html><head><title>Saathi Deploy</title>
<style>body{font-family:system-ui,sans-serif;background:#f1f5f9;padding:20px;max-width:720px;margin:0 auto}
.wrap{background:#fff;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
h1{margin-top:0;font-size:22px}</style></head><body><div class='wrap'>
<h1>⚡ Saathi Auto-Deploy</h1><p style='color:#64748b'>Triggered " . date('Y-m-d H:i:s') . " via " . $_SERVER['REQUEST_METHOD'] . "</p><hr style='border:none;border-top:1px solid #e2e8f0;margin:12px 0'>\n";

$git = trim(shell_exec('which git 2>&1') ?? '');
if (!$git) { out('Git not found. Install git on Hostinger or deploy manually.', '#ef4444'); echo '</div></body></html>'; exit; }
out("✅ Git available: $git", '#22c55e');

if (is_dir($DEPLOY_PATH . '/.git')) {
    out('📦 Pulling latest from origin/' . $BRANCH . '...', '#8b5cf6');
    chdir($DEPLOY_PATH);
    out(trim(shell_exec("git fetch origin $BRANCH 2>&1") ?? 'done'), '#64748b');
    out(trim(shell_exec("git reset --hard origin/$BRANCH 2>&1") ?? 'done'), '#64748b');
} else {
    out('📦 Cloning fresh repo...', '#8b5cf6');
    $parent = dirname($DEPLOY_PATH);
    $dir = basename($DEPLOY_PATH);
    if (is_dir($DEPLOY_PATH) && count(scandir($DEPLOY_PATH)) > 2) {
        $bak = $DEPLOY_PATH . '_bak_' . date('Ymd_His');
        rename($DEPLOY_PATH, $bak);
        out('🔄 Backed up existing to ' . basename($bak), '#f59e0b');
    }
    chdir($parent);
    out(trim(shell_exec("git clone --branch $BRANCH $REPO_URL $dir 2>&1") ?? 'done'), '#64748b');
    chdir($DEPLOY_PATH);
}

$commit = trim(shell_exec('git log -1 --oneline 2>&1') ?? 'unknown');
out("📌 Deployed: <strong>$commit</strong>", '#22c55e');

if (file_exists($DEPLOY_PATH . '/config.local.php')) {
    out("✅ config.local.php present", '#22c55e');
} else {
    out("⚠️ config.local.php missing — DB may not connect", '#f59e0b');
}

if (function_exists('opcache_reset')) opcache_reset();

out('🎉 Deployment complete! Visit <a href="https://saathi.neermedia.com">saathi.neermedia.com</a>', '#22c55e');
echo "<div style='margin-top:16px;padding-top:12px;border-top:1px solid #e2e8f0;color:#94a3b8;font-size:12px'>
<p>Repo: $REPO_URL | Branch: $BRANCH | Log: deploy.log</p></div></div></body></html>";