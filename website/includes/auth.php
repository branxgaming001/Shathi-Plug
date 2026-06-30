<?php
declare(strict_types=1);
require_once __DIR__ . '/mailer.php';

function _otp_pepper(): string {
    $db_pepper = setting_get('APP_SECRET', '');
    if ($db_pepper !== null && $db_pepper !== '') return $db_pepper;
    $env = getenv('APP_SECRET');
    if ($env) return $env;
    // Auto-generate and persist on first call
    $generated = bin2hex(random_bytes(32));
    setting_set('APP_SECRET', $generated);
    return $generated;
}
function _otp_hash(string $code): string { return hash('sha256', $code . '|' . _otp_pepper()); }

/**
 * Generate + (try to) send an OTP. Rate-limited per destination.
 * @return array{ok:bool, error?:string, dev_code?:string, sent?:bool}
 */
function otp_start(string $channel, string $destination): array {
    $destination = mb_strtolower(trim($destination));
    if ($channel === 'email' && !filter_var($destination, FILTER_VALIDATE_EMAIL)) return ['ok'=>false,'error'=>'Enter a valid email address.'];
    if ($channel === 'phone' && !preg_match('/^[0-9+\-\s]{7,20}$/', $destination)) return ['ok'=>false,'error'=>'Enter a valid phone number.'];

    $db = pdo();
    // Rate limit: max 5 sends / hour / destination, and 20s cooldown.
    $st = $db->prepare("SELECT COUNT(*) c, MAX(created_at) last FROM otps WHERE destination=? AND created_at > (NOW() - INTERVAL 1 HOUR)");
    $st->execute([$destination]); $r = $st->fetch();
    if ((int)$r['c'] >= 5) return ['ok'=>false,'error'=>'Too many codes requested. Try again later.'];
    if ($r['last'] && (time() - strtotime($r['last'])) < 20) return ['ok'=>false,'error'=>'Please wait a few seconds before requesting another code.'];

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $db->prepare("INSERT INTO otps(channel,destination,code_hash,expires_at,ip) VALUES(?,?,?, NOW() + INTERVAL 10 MINUTE, ?)")
       ->execute([$channel,$destination,_otp_hash($code),client_ip()]);
    audit('otp_sent', ['channel'=>$channel,'destination'=>$destination], 'system');

    $out = ['ok'=>true];
    if ($channel === 'email' && mailer_configured()) {
        $out['sent'] = send_email($destination, 'Your Saathi sign-in code', otp_email_html($code));
    } else {
        // Dev delivery (no mailer / phone mocked): surface the code so it is testable now.
        $out['sent'] = false; $out['dev_code'] = $code;
    }
    return $out;
}

/** Verify a code. @return array{ok:bool, error?:string} */
function otp_verify(string $channel, string $destination, string $code): array {
    $destination = mb_strtolower(trim($destination));
    $code = preg_replace('/\D/', '', $code);
    $db = pdo();
    $st = $db->prepare("SELECT * FROM otps WHERE destination=? AND channel=? AND used=0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $st->execute([$destination,$channel]); $row = $st->fetch();
    if (!$row) return ['ok'=>false,'error'=>'Code expired or not found. Request a new one.'];
    if ((int)$row['attempts'] >= 5) return ['ok'=>false,'error'=>'Too many attempts. Request a new code.'];
    if (!hash_equals($row['code_hash'], _otp_hash($code))) {
        $db->prepare("UPDATE otps SET attempts=attempts+1 WHERE id=?")->execute([$row['id']]);
        return ['ok'=>false,'error'=>'Incorrect code. Try again.'];
    }
    $db->prepare("UPDATE otps SET used=1 WHERE id=?")->execute([$row['id']]);
    return ['ok'=>true];
}

/** Find or create a user by verified email/phone, then log them in. */
function login_user(string $channel, string $destination, string $provider='email'): array {
    $destination = mb_strtolower(trim($destination));
    $db = pdo();
    $col = $channel === 'phone' ? 'phone' : 'email';
    $st = $db->prepare("SELECT * FROM users WHERE $col=?"); $st->execute([$destination]); $u = $st->fetch();
    if (!$u) {
        $db->prepare("INSERT INTO users($col,provider) VALUES(?,?)")->execute([$destination,$provider]);
        $id = (int)$db->lastInsertId();
        audit('user_signup', [$col=>$destination,'provider'=>$provider], 'user', $id);
    } else { $id = (int)$u['id']; }
    $db->prepare("UPDATE users SET last_login_at=NOW() WHERE id=?")->execute([$id]);
    // Bootstrap: the very first sign-in (when no admin email is configured yet)
    // claims the owner/admin seat. After that, admins are managed in Admin → Admins.
    if ($col === 'email' && $destination !== '' && !admin_emails()) {
        setting_set('ADMIN_EMAILS', $destination);
        audit('admin_bootstrap', ['email' => $destination], 'system', $id);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = $id; unset($_SESSION['admin_id']);
    audit('user_login', [$col=>$destination], 'user', $id);
    return ['id'=>$id];
}

/** Admin login with username + password (separate from users). */
function login_admin(string $username, string $password): array {
    $db = pdo();
    $st = $db->prepare("SELECT * FROM admins WHERE username=? AND status='active'"); $st->execute([trim($username)]);
    $a = $st->fetch();
    if (!$a || !password_verify($password, $a['pass_hash'])) {
        audit('admin_login_fail', ['username'=>$username], 'system');
        return ['ok'=>false,'error'=>'Invalid admin username or password.'];
    }
    $db->prepare("UPDATE admins SET last_login_at=NOW() WHERE id=?")->execute([(int)$a['id']]);
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$a['id']; unset($_SESSION['uid']);
    audit('admin_login', ['username'=>$username], 'admin', (int)$a['id']);
    return ['ok'=>true];
}
