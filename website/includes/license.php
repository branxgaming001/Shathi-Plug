<?php
declare(strict_types=1);
/** License key generation, issuing, validation, activations. */

function _key_hash(string $plain): string { return hash_hmac('sha256', strtoupper(trim($plain)), _otp_pepper()); }

function gen_license_key(): array {
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no ambiguous chars
    $grp = function() use ($alpha) { $s=''; for($i=0;$i<4;$i++) $s.=$alpha[random_int(0,strlen($alpha)-1)]; return $s; };
    $plain = 'SAATHI-' . $grp() . '-' . $grp() . '-' . $grp();
    return ['plain'=>$plain, 'hash'=>_key_hash($plain), 'prefix'=>substr($plain,0,11)];
}

function issue_license(int $userId, array $plan): array {
    $db = pdo();
    $k = gen_license_key();
    $expiresSql = 'NULL';
    if ($plan['period'] === 'month') $expiresSql = '(NOW() + INTERVAL 1 MONTH)';
    elseif ($plan['period'] === 'year') $expiresSql = '(NOW() + INTERVAL 1 YEAR)';
    $db->prepare("INSERT INTO licenses(user_id,plan_id,license_key_hash,key_prefix,max_activations,expires_at)
                  VALUES(?,?,?,?,?, $expiresSql)")
       ->execute([$userId,(int)$plan['id'],$k['hash'],$k['prefix'],(int)$plan['max_activations']]);
    $id = (int)$db->lastInsertId();
    audit('license_issued', ['license_id'=>$id,'plan'=>$plan['code'],'prefix'=>$k['prefix']]);
    return ['id'=>$id, 'key'=>$k['plain']];
}

function license_by_key(string $plain): ?array {
    $st = pdo()->prepare("SELECT l.*, p.code plan_code, p.name plan_name, p.period FROM licenses l JOIN plans p ON p.id=l.plan_id WHERE l.license_key_hash=?");
    $st->execute([_key_hash($plain)]); return $st->fetch() ?: null;
}

function license_is_valid(array $lic): bool {
    if ($lic['status'] !== 'active') return false;
    if ($lic['expires_at'] !== null && strtotime($lic['expires_at']) < time()) return false;
    return true;
}

/** Validate (optionally activate a domain). Used by the plugin's license API. */
function license_validate(string $plain, ?string $domain=null): array {
    $lic = license_by_key($plain);
    if (!$lic) return ['valid'=>false,'reason'=>'not_found'];
    if (!license_is_valid($lic)) {
        $reason = $lic['status']!=='active' ? $lic['status'] : 'expired';
        return ['valid'=>false,'reason'=>$reason,'expires_at'=>$lic['expires_at']];
    }
    $db = pdo();
    if ($domain) {
        $domain = mb_strtolower(preg_replace('#^https?://#','',trim($domain)));
        $domain = explode('/',$domain)[0];
        $ex = $db->prepare("SELECT id FROM license_activations WHERE license_id=? AND domain=? AND status='active'");
        $ex->execute([(int)$lic['id'],$domain]);
        if (!$ex->fetch()) {
            $cst = $db->prepare("SELECT COUNT(*) FROM license_activations WHERE license_id=? AND status='active'");
            $cst->execute([(int)$lic['id']]);
            $cnt = (int)$cst->fetchColumn();
            if ($cnt >= (int)$lic['max_activations']) return ['valid'=>false,'reason'=>'activation_limit','max'=>(int)$lic['max_activations']];
            $db->prepare("INSERT INTO license_activations(license_id,domain,last_seen) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE status='active', last_seen=NOW()")
               ->execute([(int)$lic['id'],$domain]);
            audit('license_activated', ['license_id'=>(int)$lic['id'],'domain'=>$domain], 'system');
        } else {
            $db->prepare("UPDATE license_activations SET last_seen=NOW() WHERE license_id=? AND domain=?")->execute([(int)$lic['id'],$domain]);
        }
    }
    return ['valid'=>true,'plan'=>$lic['plan_code'],'expires_at'=>$lic['expires_at'],'prefix'=>$lic['key_prefix']];
}

/** Cron: mark due licenses expired. Returns count. */
function expire_due_licenses(): int {
    return (int)pdo()->exec("UPDATE licenses SET status='expired' WHERE status='active' AND expires_at IS NOT NULL AND expires_at < NOW()");
}

/** Licenses needing a renewal reminder (within N days, not lifetime). */
function licenses_expiring(int $days=7): array {
    $st = pdo()->prepare("SELECT l.*, u.email, p.name plan_name FROM licenses l JOIN users u ON u.id=l.user_id JOIN plans p ON p.id=l.plan_id
                          WHERE l.status='active' AND l.expires_at IS NOT NULL AND l.expires_at BETWEEN NOW() AND (NOW() + INTERVAL ? DAY)");
    $st->execute([$days]); return $st->fetchAll();
}
