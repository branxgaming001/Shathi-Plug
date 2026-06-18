<?php
declare(strict_types=1);
/**
 * RSA (RS256) signing for license responses — the "authenticity" layer.
 * The license server signs every response with a PRIVATE key (kept only here,
 * base64 in config.local.php). The plugin verifies with the matching PUBLIC key
 * embedded in its code. A forged or DNS-redirected server cannot produce a valid
 * signature, so the plugin will reject it.
 *
 * Envelope (JWS-like, signs the exact transmitted string -> no canonicalization drift):
 *   { "payload": base64url(json(data)), "sig": base64url(RSA-SHA256(payload)), "alg":"RS256", "kid":"saathi-1" }
 */

function license_private_key(): ?string {
    $b64 = (string) cfg('LICENSE_PRIVATE_KEY_B64', '');
    if ($b64 === '') return null;
    $pem = base64_decode($b64, true);
    return $pem ?: null;
}

function _b64url(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function _b64url_decode(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** Sign a data array into a verifiable envelope. $ttl = token lifetime (seconds). */
function sign_envelope(array $data, int $ttl = 604800): array {
    $data['iat']   = time();
    $data['exp']   = time() + $ttl;
    $data['nonce'] = bin2hex(random_bytes(8));
    $data['iss']   = 'saathi-license';
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $payload = _b64url($json);
    $sig = '';
    $pem = license_private_key();
    if ($pem !== null) {
        $pk = openssl_pkey_get_private($pem);
        if ($pk) {
            openssl_sign($payload, $raw, $pk, OPENSSL_ALGO_SHA256);
            $sig = _b64url($raw);
        }
    }
    return ['payload' => $payload, 'sig' => $sig, 'alg' => 'RS256', 'kid' => 'saathi-1'];
}

/** Verify an envelope with a PUBLIC key PEM (used in tests; the plugin does this client-side). */
function verify_envelope(array $env, string $publicPem): ?array {
    if (empty($env['payload']) || empty($env['sig'])) return null;
    $ok = openssl_verify($env['payload'], _b64url_decode((string)$env['sig']), $publicPem, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) return null;
    $data = json_decode(_b64url_decode((string)$env['payload']), true);
    if (!is_array($data)) return null;
    if (isset($data['exp']) && time() > (int)$data['exp']) return null;
    return $data;
}
