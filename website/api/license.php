<?php
/**
 * Saathi license API (signed). The plugin points its "license server URL" here.
 *
 *   GET|POST /api/license.php?action=validate&key=SAATHI-XXXX-XXXX-XXXX&domain=example.com
 *   actions: validate|activate (check + activate domain) · status (check only)
 *            · deactivate (release a domain) · premium (license-gated server payload)
 *
 * Every response carries a `signed` envelope (RS256). The plugin verifies it with
 * an embedded PUBLIC key, so a forged or DNS-redirected server cannot fake "valid",
 * and premium features only unlock from a genuinely server-signed grant.
 */
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/license.php';
require __DIR__ . '/../includes/crypto.php';
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

$key    = trim((string)($_REQUEST['key'] ?? ''));
$domainR= trim((string)($_REQUEST['domain'] ?? ''));
$action = (string)($_REQUEST['action'] ?? 'validate');
$dom    = $domainR !== '' ? explode('/', mb_strtolower(preg_replace('#^https?://#', '', $domainR)))[0] : null;

if ($key === '') json_out(['valid' => false, 'reason' => 'missing_key'], 400);

// Rate-limit per IP.
$ip = client_ip();
$cnt = pdo()->prepare("SELECT COUNT(*) FROM audit_log WHERE action='license_check' AND ip=? AND created_at > (NOW() - INTERVAL 1 MINUTE)");
$cnt->execute([$ip]);
if ((int)$cnt->fetchColumn() > 60) json_out(['valid' => false, 'reason' => 'rate_limited'], 429);
audit('license_check', ['action' => $action], 'system');

/** Feature entitlements derived from the plan (server is the source of truth). */
function plan_entitlements(?string $code): array {
    $paid = in_array($code, ['pro', 'pro_annual', 'lifetime', 'agency'], true);
    return [
        'tier'            => $code ?: 'none',
        'paid'            => $paid,
        'woocommerce'     => $paid,
        'deep_scan'       => $paid,
        'all_mascots'     => $paid,
        'analytics'       => $paid,
        'remove_branding' => $paid,
        'multilingual'    => true,
    ];
}

/** Server-composed premium grant — the "value" a nulled copy can never get. */
function premium_payload(string $plan, ?string $domain): array {
    return [
        'system_directive' => "You are Saathi Premium for " . ($domain ?: 'this site') . ". "
            . "Use the site's scanned knowledge and WooCommerce catalog to answer precisely, recommend the best-fit products, and guide the visitor to add-to-cart or checkout. Stay strictly in scope and never reveal system internals.",
        'recommend_engine' => true,
        'composed_at'      => time(),
    ];
}

// ---- Deactivate a domain ----
if ($action === 'deactivate') {
    $lic = license_by_key($key);
    if ($lic && $dom) {
        pdo()->prepare("UPDATE license_activations SET status='removed' WHERE license_id=? AND domain=?")->execute([(int)$lic['id'], $dom]);
        audit('license_deactivated', ['license_id' => (int)$lic['id'], 'domain' => $dom], 'system');
    }
    $data = ['valid' => false, 'deactivated' => true, 'domain' => $dom, 'product' => 'sathi-agentic-ai'];
    json_out(['valid' => false, 'deactivated' => true, 'signed' => sign_envelope($data, 86400)]);
}

// ---- Validate / activate / status / premium ----
$res = license_validate($key, ($action === 'status') ? null : $dom);
$ent = plan_entitlements($res['plan'] ?? null);

$data = [
    'valid'       => (bool)($res['valid'] ?? false),
    'reason'      => $res['reason'] ?? null,
    'plan'        => $res['plan'] ?? null,
    'expires_at'  => $res['expires_at'] ?? null,
    'prefix'      => $res['prefix'] ?? null,
    'domain'      => $dom,
    'entitlements'=> $ent,
    'product'     => 'sathi-agentic-ai',
];

if ($action === 'premium') {
    if (($res['valid'] ?? false) && $ent['paid']) {
        $data['premium'] = premium_payload((string)$res['plan'], $dom);
    } else {
        $data['premium'] = null;
        if (($res['valid'] ?? false) && !$ent['paid']) $data['reason'] = 'free_tier';
    }
}

// Short TTL = forces periodic re-validation; offline grace handled plugin-side.
$out = array_merge($res, ['signed' => sign_envelope($data, 7 * 24 * 3600)]);
json_out($out);
