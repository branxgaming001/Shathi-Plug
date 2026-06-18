<?php
/** Google OAuth callback: verify state, exchange code, read the verified email, sign the user in. */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth.php';

function g_b64url_decode(string $s): string {
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}
function g_post(string $url, array $fields): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $r = curl_exec($ch); curl_close($ch); return $r === false ? null : $r;
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => http_build_query($fields), 'timeout' => 20, 'ignore_errors' => true]]);
    $r = @file_get_contents($url, false, $ctx); return $r === false ? null : $r;
}
function g_get_bearer(string $url, string $token): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
        $r = curl_exec($ch); curl_close($ch); return $r === false ? null : $r;
    }
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => 'Authorization: Bearer ' . $token, 'timeout' => 20, 'ignore_errors' => true]]);
    $r = @file_get_contents($url, false, $ctx); return $r === false ? null : $r;
}
function g_fail(string $why): void { error_log('[saathi-google] ' . $why); redirect('login.php?gerr=1'); }

if (!empty($_GET['error'])) g_fail('user_denied:' . preg_replace('/[^a-z_]/', '', (string) $_GET['error']));

$code  = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');
$want  = (string) ($_SESSION['g_state'] ?? '');
unset($_SESSION['g_state']);
if ($code === '' || $state === '' || $want === '' || !hash_equals($want, $state)) g_fail('bad_state');

$cid  = (string) cfg('GOOGLE_CLIENT_ID', '');
$csec = (string) cfg('GOOGLE_CLIENT_SECRET', '');
$redirect = (string) cfg('GOOGLE_REDIRECT', rtrim((string) cfg('PUBLIC_URL', 'https://saathi.railabs.in'), '/') . '/google_callback.php');
if ($cid === '' || $csec === '') g_fail('not_configured');

$resp = g_post('https://oauth2.googleapis.com/token', [
    'code' => $code, 'client_id' => $cid, 'client_secret' => $csec,
    'redirect_uri' => $redirect, 'grant_type' => 'authorization_code',
]);
$tok = $resp ? json_decode($resp, true) : null;
if (!is_array($tok)) g_fail('token_exchange_failed');

$email = ''; $verified = false; $name = null;

// Preferred: read the id_token (came directly from Google over TLS — audience + issuer checked).
if (!empty($tok['id_token'])) {
    $parts = explode('.', (string) $tok['id_token']);
    if (count($parts) === 3) {
        $p = json_decode(g_b64url_decode($parts[1]), true);
        if (is_array($p)
            && (($p['aud'] ?? '') === $cid)
            && in_array(($p['iss'] ?? ''), ['accounts.google.com', 'https://accounts.google.com'], true)) {
            $email    = (string) ($p['email'] ?? '');
            $verified = !empty($p['email_verified']);
            $name     = $p['name'] ?? null;
        }
    }
}
// Fallback: userinfo endpoint.
if ($email === '' && !empty($tok['access_token'])) {
    $ui = json_decode((string) g_get_bearer('https://openidconnect.googleapis.com/v1/userinfo', (string) $tok['access_token']), true);
    if (is_array($ui)) {
        $email    = (string) ($ui['email'] ?? '');
        $verified = !empty($ui['email_verified']);
        $name     = $ui['name'] ?? null;
    }
}

$email = mb_strtolower(trim($email));
if ($email === '' || !$verified) g_fail('email_unverified');

login_user('email', $email, 'google');
if (!empty($name)) {
    try { pdo()->prepare("UPDATE users SET name=? WHERE id=? AND (name IS NULL OR name='')")->execute([$name, (int) $_SESSION['uid']]); }
    catch (Throwable $e) { /* non-fatal */ }
}
$u = current_user();
if (is_admin_email($u['email'] ?? '')) { unset($_SESSION['next']); redirect('admin.php'); }
if (!profile_complete($u)) redirect('profile.php');   // keep 'next' for after onboarding
$next = $_SESSION['next'] ?? 'dashboard.php';
unset($_SESSION['next']);
redirect($next);
