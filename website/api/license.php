<?php
/**
 * License validation API for the Saathi WordPress plugin.
 * The plugin sets its "license server URL" to this endpoint and calls it to
 * validate / activate a key against this platform.
 *
 *   GET|POST  ?action=validate&key=SAATHI-XXXX-XXXX-XXXX&domain=example.com
 *   action: validate (checks + activates the domain) | status (check only)
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/license.php';
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

$key    = trim((string)($_REQUEST['key'] ?? ''));
$domain = trim((string)($_REQUEST['domain'] ?? ''));
$action = (string)($_REQUEST['action'] ?? 'validate');

if ($key === '') json_out(['valid' => false, 'reason' => 'missing_key'], 400);

// Light rate-limit per IP to deter brute force.
$ip = client_ip();
$cnt = pdo()->prepare("SELECT COUNT(*) FROM audit_log WHERE action='license_check' AND ip=? AND created_at > (NOW() - INTERVAL 1 MINUTE)");
$cnt->execute([$ip]);
if ((int)$cnt->fetchColumn() > 60) json_out(['valid' => false, 'reason' => 'rate_limited'], 429);
audit('license_check', ['action' => $action], 'system');

$res = ($action === 'status') ? license_validate($key, null) : license_validate($key, $domain ?: null);
json_out($res);
