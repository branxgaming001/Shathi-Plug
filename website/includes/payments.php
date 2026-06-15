<?php
declare(strict_types=1);
require_once __DIR__ . '/license.php';
/** Plans + payments. Test mode now; Razorpay-ready (server-priced, idempotent). */

function plans_all(bool $activeOnly=true): array {
    $sql = "SELECT * FROM plans " . ($activeOnly ? "WHERE active=1 " : "") . "ORDER BY sort";
    return pdo()->query($sql)->fetchAll();
}
function plan_by_code(string $code): ?array { $st=pdo()->prepare("SELECT * FROM plans WHERE code=?"); $st->execute([$code]); return $st->fetch() ?: null; }
function plan_by_id(int $id): ?array { $st=pdo()->prepare("SELECT * FROM plans WHERE id=?"); $st->execute([$id]); return $st->fetch() ?: null; }

function payment_mode(): string { return (cfg('RAZORPAY_KEY_ID') && cfg('RAZORPAY_KEY_SECRET')) ? 'razorpay' : 'test'; }

/**
 * Create a payment intent. Amount is ALWAYS taken from the plan server-side
 * (never trusted from the client). Idempotency key prevents duplicate issues.
 */
function payment_create(int $userId, array $plan): array {
    $db = pdo();
    $amount = (int)$plan['price_inr'];
    $idem = 'pay_' . $userId . '_' . $plan['id'] . '_' . bin2hex(random_bytes(6));
    $mode = payment_mode();
    $orderId = null;
    if ($mode === 'razorpay') {
        $order = razorpay_create_order($amount * 100, $idem); // paise
        $orderId = $order['id'] ?? null;
    }
    $db->prepare("INSERT INTO payments(user_id,plan_id,amount_inr,gateway,gateway_order_id,idempotency_key) VALUES(?,?,?,?,?,?)")
       ->execute([$userId,(int)$plan['id'],$amount,$mode,$orderId,$idem]);
    $pid = (int)$db->lastInsertId();
    audit('payment_created', ['payment_id'=>$pid,'plan'=>$plan['code'],'amount'=>$amount,'mode'=>$mode]);
    return ['payment_id'=>$pid,'mode'=>$mode,'amount'=>$amount,'order_id'=>$orderId,'idem'=>$idem];
}

/** Mark a payment paid + issue license. Idempotent (safe to call twice). */
function payment_mark_paid(int $paymentId, ?string $gatewayPaymentId=null): array {
    $db = pdo();
    $db->beginTransaction();
    try {
        $st = $db->prepare("SELECT * FROM payments WHERE id=? FOR UPDATE"); $st->execute([$paymentId]);
        $pay = $st->fetch();
        if (!$pay) { $db->rollBack(); return ['ok'=>false,'error'=>'payment_not_found']; }
        if ($pay['status'] === 'paid') {
            $db->commit();
            return ['ok'=>true,'already'=>true,'license_id'=>(int)$pay['license_id']];
        }
        $plan = plan_by_id((int)$pay['plan_id']);
        $lic = issue_license((int)$pay['user_id'], $plan);
        $db->prepare("UPDATE payments SET status='paid', paid_at=NOW(), gateway_payment_id=?, license_id=? WHERE id=?")
           ->execute([$gatewayPaymentId,(int)$lic['id'],$paymentId]);
        $db->commit();
        audit('payment_paid', ['payment_id'=>$paymentId,'license_id'=>(int)$lic['id']]);
        return ['ok'=>true,'license_id'=>(int)$lic['id'],'license_key'=>$lic['key']];
    } catch (Throwable $x) {
        $db->rollBack();
        return ['ok'=>false,'error'=>'server_error'];
    }
}

function extend_license(int $licenseId, array $plan): void {
    if ($plan['period'] === 'lifetime') {
        pdo()->prepare("UPDATE licenses SET status='active', expires_at=NULL WHERE id=?")->execute([$licenseId]);
        return;
    }
    $add = $plan['period'] === 'year' ? '1 YEAR' : '1 MONTH';
    // Extend from whichever is later: current expiry or now.
    pdo()->prepare("UPDATE licenses SET status='active', expires_at = GREATEST(COALESCE(expires_at, NOW()), NOW()) + INTERVAL $add WHERE id=?")
         ->execute([$licenseId]);
}

/** Fulfil a payment: issue a new license OR renew an existing one. Idempotent. */
function fulfill_payment(int $paymentId, ?int $renewLicenseId=null, ?string $gpid=null): array {
    $db = pdo(); $db->beginTransaction();
    try {
        $st = $db->prepare("SELECT * FROM payments WHERE id=? FOR UPDATE"); $st->execute([$paymentId]); $pay = $st->fetch();
        if (!$pay) { $db->rollBack(); return ['ok'=>false,'error'=>'not_found']; }
        if ($pay['status'] === 'paid') { $db->commit(); return ['ok'=>true,'already'=>true,'license_id'=>(int)$pay['license_id']]; }
        $plan = plan_by_id((int)$pay['plan_id']);
        if ($renewLicenseId) {
            $lc = $db->prepare("SELECT id FROM licenses WHERE id=? AND user_id=?"); $lc->execute([$renewLicenseId,(int)$pay['user_id']]);
            if (!$lc->fetch()) { $db->rollBack(); return ['ok'=>false,'error'=>'bad_license']; }
            extend_license($renewLicenseId, $plan);
            $db->prepare("UPDATE payments SET status='paid', paid_at=NOW(), gateway_payment_id=?, license_id=? WHERE id=?")->execute([$gpid,$renewLicenseId,$paymentId]);
            $db->commit(); audit('payment_paid', ['payment_id'=>$paymentId,'renew_license'=>$renewLicenseId]);
            return ['ok'=>true,'renewed'=>true,'license_id'=>$renewLicenseId];
        }
        $lic = issue_license((int)$pay['user_id'], $plan);
        $db->prepare("UPDATE payments SET status='paid', paid_at=NOW(), gateway_payment_id=?, license_id=? WHERE id=?")->execute([$gpid,(int)$lic['id'],$paymentId]);
        $db->commit(); audit('payment_paid', ['payment_id'=>$paymentId,'license_id'=>(int)$lic['id']]);
        return ['ok'=>true,'license_id'=>(int)$lic['id'],'license_key'=>$lic['key']];
    } catch (Throwable $x) { $db->rollBack(); return ['ok'=>false,'error'=>'server_error']; }
}

/* -------- Razorpay (ready; used only when keys configured) -------- */
function razorpay_create_order(int $amountPaise, string $receipt): array {
    $kid=cfg('RAZORPAY_KEY_ID'); $ks=cfg('RAZORPAY_KEY_SECRET');
    $ch=curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_USERPWD=>$kid.':'.$ks,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['amount'=>$amountPaise,'currency'=>'INR','receipt'=>$receipt,'payment_capture'=>1]),
        CURLOPT_TIMEOUT=>25]);
    $res=curl_exec($ch); curl_close($ch);
    return json_decode((string)$res,true) ?: [];
}
function razorpay_verify_signature(string $orderId, string $paymentId, string $signature): bool {
    $ks=cfg('RAZORPAY_KEY_SECRET'); if(!$ks) return false;
    return hash_equals(hash_hmac('sha256', $orderId.'|'.$paymentId, $ks), $signature);
}
