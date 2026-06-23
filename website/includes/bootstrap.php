<?php
/**
 * Saathi platform core: DB, auto-schema, seeding, sessions, CSRF, guards, audit.
 * Security-first: PDO prepared statements, argon2id, hardened cookies,
 * server-side authorization, audit logging.
 */
declare(strict_types=1);
date_default_timezone_set('UTC');

/* On shared hosting (e.g. Hostinger) there are no env vars — load a local
   config file that sets DB creds + secrets via putenv(). No-op on Railway. */
if (is_file(__DIR__ . '/../config.local.php')) {
    require __DIR__ . '/../config.local.php';
}

/* ---------- DB ---------- */
function pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $cfg = (require __DIR__ . '/../config.php')['db'];
    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset={$cfg['charset']}";
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/* ---------- Schema (idempotent) ---------- */
function ensure_schema(): void {
    static $done = false;
    if ($done) return; $done = true;
    $db = pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS users(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) UNIQUE, phone VARCHAR(20) UNIQUE, name VARCHAR(120),
        provider VARCHAR(20) NOT NULL DEFAULT 'email',
        status ENUM('active','blocked') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS admins(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(60) UNIQUE NOT NULL, pass_hash VARCHAR(255) NOT NULL,
        name VARCHAR(120), role ENUM('super','admin') NOT NULL DEFAULT 'admin',
        status ENUM('active','blocked') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by BIGINT UNSIGNED NULL,
        last_login_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS otps(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        channel ENUM('email','phone') NOT NULL, destination VARCHAR(190) NOT NULL,
        code_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL,
        attempts TINYINT NOT NULL DEFAULT 0, used TINYINT NOT NULL DEFAULT 0,
        ip VARCHAR(45), created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_dest (destination, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS plans(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(40) UNIQUE NOT NULL, name VARCHAR(80) NOT NULL,
        price_inr INT UNSIGNED NOT NULL DEFAULT 0,
        price_usd INT UNSIGNED NOT NULL DEFAULT 0,
        period ENUM('month','year','lifetime') NOT NULL DEFAULT 'month',
        max_activations INT NOT NULL DEFAULT 1, features TEXT, active TINYINT NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS licenses(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL, plan_id BIGINT UNSIGNED NOT NULL,
        license_key_hash CHAR(64) UNIQUE NOT NULL, key_prefix VARCHAR(24) NOT NULL,
        status ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
        max_activations INT NOT NULL DEFAULT 1,
        expires_at DATETIME NULL, reminded_on DATE NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS license_activations(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        license_id BIGINT UNSIGNED NOT NULL, domain VARCHAR(190) NOT NULL,
        status ENUM('active','removed') NOT NULL DEFAULT 'active',
        activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen DATETIME NULL,
        UNIQUE KEY uq (license_id, domain)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS payments(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL, plan_id BIGINT UNSIGNED NOT NULL,
        license_id BIGINT UNSIGNED NULL, amount_inr INT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'INR',
        gateway VARCHAR(20) NOT NULL DEFAULT 'test', gateway_order_id VARCHAR(120), gateway_payment_id VARCHAR(120),
        status ENUM('created','paid','failed') NOT NULL DEFAULT 'created',
        idempotency_key VARCHAR(80) UNIQUE, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, paid_at DATETIME NULL,
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS audit_log(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        actor_type VARCHAR(10) NOT NULL, actor_id BIGINT UNSIGNED NULL,
        action VARCHAR(60) NOT NULL, meta TEXT, ip VARCHAR(45),
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS settings(
        k VARCHAR(60) PRIMARY KEY, v TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Profile / onboarding columns on users — added idempotently (works on existing tables).
    $profileCols = [
        'first_name'=>"VARCHAR(80)", 'last_name'=>"VARCHAR(80)", 'mobile'=>"VARCHAR(30)",
        'company'=>"VARCHAR(150)", 'website'=>"VARCHAR(190)", 'use_case'=>"VARCHAR(60)",
        'industry'=>"VARCHAR(80)", 'address'=>"VARCHAR(400)", 'country'=>"VARCHAR(80)",
        'heard_from'=>"VARCHAR(60)", 'goal'=>"VARCHAR(500)",
        'profile_completed'=>"TINYINT NOT NULL DEFAULT 0",
    ];
    $have = [];
    foreach ($db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'") as $r) { $have[$r['COLUMN_NAME']] = 1; }
    foreach ($profileCols as $col => $def) {
        if (!isset($have[$col])) { try { $db->exec("ALTER TABLE users ADD COLUMN `$col` $def"); } catch (Throwable $e) { /* ignore */ } }
    }

    seed();
}

function seed(): void {
    $db = pdo();
    // Plans
    if ((int)$db->query("SELECT COUNT(*) FROM plans")->fetchColumn() === 0) {
        $st = $db->prepare("INSERT INTO plans(code,name,price_inr,price_usd,period,max_activations,features,sort) VALUES(?,?,?,?,?,?,?,?)");
        $st->execute(['free','Free',0,0,'lifetime',1,'Core agentic AI chat|Bring your own AI provider (free or paid)|Basic persona|Default mascot + colour|Custom mascot upload|Multilingual replies|1 website',1]);
        $st->execute(['pro','Pro',499,6,'month',3,'Everything in Free|All 8 mascots + full theming|Multiple & AI-built personas|Memory + site navigation|Content moderation|Priority support|3 websites',2]);
        $st->execute(['max','Max',699,9,'month',5,'Everything in Pro|WooCommerce product showcase|Deep scan (site knowledge)|Self-improving AI|Smart follow-up questions|Direct add-to-cart in chat|5 websites',3]);
    }
    // Initial admin (from env, else default — change after first login)
    if ((int)$db->query("SELECT COUNT(*) FROM admins")->fetchColumn() === 0) {
        $u = getenv('ADMIN_USER') ?: 'admin';
        $p = getenv('ADMIN_PASS') ?: 'Saathi@Admin#2026';
        $st = $db->prepare("INSERT INTO admins(username,pass_hash,name,role) VALUES(?,?,?, 'super')");
        $st->execute([$u, password_hash($p, PASSWORD_DEFAULT), 'Owner']);
    }
}

/* ---------- Sessions ---------- */
function boot_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['HTTPS'] ?? '') === 'on');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','httponly'=>true,'samesite'=>'Lax','secure'=>$https]);
    session_name('saathi_sess');
    session_start();
}

/* ---------- Helpers ---------- */
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect(string $to): void { header('Location: ' . $to); exit; }
function json_out($d, int $code=200): void { http_response_code($code); header('Content-Type: application/json'); echo json_encode($d); exit; }
function client_ip(): string { return substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),0,45); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">'; }
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Invalid session token. Refresh and try again.'); }
}

function audit(string $action, array $meta = [], ?string $actorType=null, ?int $actorId=null): void {
    try {
        if ($actorType === null) {
            if (!empty($_SESSION['admin_id'])) { $actorType='admin'; $actorId=(int)$_SESSION['admin_id']; }
            elseif (!empty($_SESSION['uid'])) { $actorType='user'; $actorId=(int)$_SESSION['uid']; }
            else { $actorType='system'; }
        }
        $st = pdo()->prepare("INSERT INTO audit_log(actor_type,actor_id,action,meta,ip) VALUES(?,?,?,?,?)");
        $st->execute([$actorType,$actorId,$action,json_encode($meta),client_ip()]);
    } catch (Throwable $x) { /* never break flow on audit failure */ }
}

/* ---------- Current actors + guards ---------- */
function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $st = pdo()->prepare("SELECT * FROM users WHERE id=? AND status='active'"); $st->execute([(int)$_SESSION['uid']]);
    return $st->fetch() ?: null;
}
/* Admin is now identified purely by the signed-in user's email (single login). */
function current_admin(): ?array {
    $u = current_user();
    if (!$u || !is_admin_email($u['email'] ?? '')) return null;
    return $u + ['username' => $u['email'], 'role' => 'super'];
}
function require_login(): array { $u = current_user(); if (!$u) redirect('login.php'); return $u; }
function require_admin(): array {
    $u = require_login();
    if (!is_admin_email($u['email'] ?? '')) redirect('dashboard.php');
    return $u + ['username' => $u['email'], 'role' => 'super'];
}

/* ---------- Admin-by-email ---------- */
function admin_emails(): array {
    $raw = (string) cfg('ADMIN_EMAILS', '');
    return preg_split('/[\s,;]+/', mb_strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}
function is_admin_email(?string $email): bool {
    $email = mb_strtolower(trim((string) $email));
    return $email !== '' && in_array($email, admin_emails(), true);
}

/* ---------- Profile / onboarding ---------- */
function profile_complete(?array $u): bool {
    if (!$u) return false;
    if ((int)($u['profile_completed'] ?? 0) !== 1) return false;
    foreach (['first_name','last_name','mobile','company','website','use_case','address','country','heard_from'] as $f) {
        if (trim((string)($u[$f] ?? '')) === '') return false;
    }
    return true;
}
/** Gate user-only pages: incomplete profiles are sent to onboarding. Admins skip. */
function require_profile(array $u): void {
    if (is_admin_email($u['email'] ?? '')) return;
    if (!profile_complete($u)) redirect('profile.php');
}

/* ---------- Settings ---------- */
function setting_get(string $k, $default=null) {
    $st = pdo()->prepare("SELECT v FROM settings WHERE k=?"); $st->execute([$k]);
    $v = $st->fetchColumn(); return $v === false ? $default : $v;
}
function setting_set(string $k, string $v): void {
    pdo()->prepare("INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)")->execute([$k,$v]);
}
/** Config value: prefer admin-set DB setting, fall back to environment variable. */
function cfg(string $k, $default=null) {
    $v = setting_get($k, null);
    if ($v !== null && $v !== '') return $v;
    $e = getenv($k);
    return ($e !== false && $e !== '') ? $e : $default;
}

/* boot */
boot_session();
ensure_schema();
