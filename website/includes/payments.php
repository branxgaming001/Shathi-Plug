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
function payment_by_order_id(string $orderId): ?array { $st=pdo()->prepare("SELECT * FROM payments WHERE gateway_order_id=?"); $st->execute([$orderId]); return $st->fetch() ?: null; }

function payment_mode(): string { return (cfg('RAZORPAY_KEY_ID') && cfg('RAZORPAY_KEY_SECRET')) ? 'razorpay' : 'test'; }

/**
 * Create a payment intent. Amount is ALWAYS taken from the plan server-side
 * (never trusted from the client). Idempotency key prevents duplicate issues.
 */
function payment_create(int $userId, array $plan, ?int $renewLicenseId=null): array {
    $db = pdo();
    $amount = (int)$plan['price_inr'];
    $idem = 'pay_' . $userId . '_' . $plan['id'] . '_' . bin2hex(random_bytes(6));
    $mode = payment_mode();
    // Insert the payment row FIRST so callback + webhook can always reconcile by order id.
    $db->prepare("INSERT INTO payments(user_id,plan_id,amount_inr,gateway,idempotency_key,renew_license_id) VALUES(?,?,?,?,?,?)")
       ->execute([$userId,(int)$plan['id'],$amount,$mode,$idem,$renewLicenseId]);
    $pid = (int)$db->lastInsertId();
    $orderId = null; $orderErr = null;
    if ($mode === 'razorpay' && $amount > 0) {
        $order = razorpay_create_order($amount * 100, $idem, ['payment_id'=>(string)$pid,'plan'=>(string)$plan['code'],'user_id'=>(string)$userId]); // paise
        $orderId = $order['id'] ?? null;
        if ($orderId) {
            $db->prepare("UPDATE payments SET gateway_order_id=? WHERE id=?")->execute([$orderId,$pid]);
        } else {
            $orderErr = $order['error']['description'] ?? 'order_create_failed';
        }
    }
    audit('payment_created', ['payment_id'=>$pid,'plan'=>$plan['code'],'amount'=>$amount,'mode'=>$mode]);
    return ['payment_id'=>$pid,'mode'=>$mode,'amount'=>$amount,'order_id'=>$orderId,'idem'=>$idem,'error'=>$orderErr];
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
            payment_receipt_email((int)$pay['user_id'], $plan, (int)$pay['amount_inr'], null, true);
            return ['ok'=>true,'renewed'=>true,'license_id'=>$renewLicenseId];
        }
        $lic = issue_license((int)$pay['user_id'], $plan);
        $db->prepare("UPDATE payments SET status='paid', paid_at=NOW(), gateway_payment_id=?, license_id=? WHERE id=?")->execute([$gpid,(int)$lic['id'],$paymentId]);
        $db->commit(); audit('payment_paid', ['payment_id'=>$paymentId,'license_id'=>(int)$lic['id']]);
        payment_receipt_email((int)$pay['user_id'], $plan, (int)$pay['amount_inr'], $lic['key'], false);
        return ['ok'=>true,'license_id'=>(int)$lic['id'],'license_key'=>$lic['key']];
    } catch (Throwable $x) { $db->rollBack(); return ['ok'=>false,'error'=>'server_error']; }
}

/* -------- Razorpay (ready; used only when keys configured) -------- */
function razorpay_create_order(int $amountPaise, string $receipt, array $notes=[]): array {
    $kid=cfg('RAZORPAY_KEY_ID'); $ks=cfg('RAZORPAY_KEY_SECRET');
    $payload=['amount'=>$amountPaise,'currency'=>'INR','receipt'=>$receipt,'payment_capture'=>1];
    if ($notes) $payload['notes']=$notes;
    $ch=curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_USERPWD=>$kid.':'.$ks,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($payload),
        CURLOPT_TIMEOUT=>25]);
    $res=curl_exec($ch); $err=curl_error($ch); curl_close($ch);
    $j=json_decode((string)$res,true);
    if (!is_array($j)) return ['error'=>['description'=>($err !== '' ? $err : 'invalid_response')]];
    return $j;
}
function razorpay_verify_signature(string $orderId, string $paymentId, string $signature): bool {
    $ks=cfg('RAZORPAY_KEY_SECRET'); if(!$ks) return false;
    return hash_equals(hash_hmac('sha256', $orderId.'|'.$paymentId, $ks), $signature);
}

/**
 * Best-effort payment receipt / tax invoice email (also the licence-key email
 * for new licences). Never throws — email failure must not block fulfilment.
 */
function payment_receipt_email(int $userId, array $plan, int $amountInr, ?string $licenseKey, bool $renewed): void {
    try {
        require_once __DIR__ . '/mailer.php';
        if (!function_exists('send_email')) return;
        $st = pdo()->prepare("SELECT email, first_name FROM users WHERE id=?"); $st->execute([$userId]);
        $u = $st->fetch(); if (!$u || empty($u['email'])) return;
        $to    = (string)$u['email'];
        $name  = trim((string)($u['first_name'] ?? '')) ?: 'there';
        $inv   = 'NM-' . date('Ymd') . '-' . str_pad((string)$userId, 4, '0', STR_PAD_LEFT) . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        $amt   = $amountInr > 0 ? '₹' . number_format($amountInr) : 'Free';
        $per   = $plan['period']==='month' ? ' / month' : ($plan['period']==='year' ? ' / year' : '');
        $base  = rtrim((string)(cfg('PUBLIC_URL') ?: 'https://saathi.neermedia.com'), '/');
        $title = $renewed ? 'Your Saathi renewal receipt' : ($amountInr > 0 ? 'Your Saathi payment receipt & invoice' : 'Your Saathi licence is ready');
        $intro = $renewed ? 'Your licence has been renewed — thank you!' : ($amountInr > 0 ? 'Thank you for your purchase! Here is your receipt.' : 'Your free licence is ready.');
        $keyRow = $licenseKey
            ? '<tr><td style="padding:9px 0;color:#6b7280">Licence key</td><td style="padding:9px 0;text-align:right;font-weight:800;letter-spacing:1px">' . htmlspecialchars($licenseKey) . '</td></tr>'
            : '';
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#1c1733">'
            . '<div style="background:#6D5DFB;color:#fff;padding:20px 24px;border-radius:14px 14px 0 0"><div style="font-size:22px;font-weight:800">Saathi</div><div style="opacity:.9;font-size:12px">by NEER Media</div></div>'
            . '<div style="border:1px solid #ececec;border-top:none;border-radius:0 0 14px 14px;padding:24px">'
            . '<p>Hi ' . htmlspecialchars($name) . ',</p><p>' . $intro . '</p>'
            . '<table style="width:100%;border-collapse:collapse;font-size:14px;margin:14px 0;border-top:1px solid #eee">'
            . '<tr><td style="padding:9px 0;color:#6b7280">Invoice</td><td style="padding:9px 0;text-align:right;font-weight:700">' . $inv . '</td></tr>'
            . '<tr><td style="padding:9px 0;color:#6b7280">Date</td><td style="padding:9px 0;text-align:right">' . date('d M Y') . '</td></tr>'
            . '<tr><td style="padding:9px 0;color:#6b7280">Plan</td><td style="padding:9px 0;text-align:right;font-weight:700">' . htmlspecialchars((string)$plan['name']) . $per . '</td></tr>'
            . '<tr><td style="padding:9px 0;color:#6b7280">Amount</td><td style="padding:9px 0;text-align:right;font-weight:700">' . $amt . '</td></tr>'
            . $keyRow
            . '</table>'
            . ($licenseKey ? '<p style="font-size:13px;color:#6b7280">Paste your licence key into the plugin setup to activate. It is also saved in your dashboard.</p>' : '')
            . '<p style="text-align:center;margin:22px 0"><a href="' . $base . '/dashboard.php" style="background:#6D5DFB;color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:700;display:inline-block">Go to your dashboard</a></p>'
            . '<p style="font-size:12px;color:#9ca3af;border-top:1px solid #eee;padding-top:12px">' . ($amountInr > 0 ? 'This is your payment receipt / tax invoice from NEER Media.' : 'Confirmation from NEER Media.') . ' Need help? Visit ' . $base . '/contact.php</p>'
            . '</div></div>';
        send_email($to, $title, $html);
        audit('receipt_email_sent', ['user_id'=>$userId, 'plan'=>$plan['code'] ?? '', 'amount'=>$amountInr, 'renewed'=>$renewed]);
    } catch (Throwable $e) {
        error_log('[saathi] receipt email failed: ' . $e->getMessage());
    }
}
