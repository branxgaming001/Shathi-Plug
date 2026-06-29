<?php
/**
 * Razorpay webhook — server-to-server fulfilment.
 * Guarantees a license is issued even if the buyer closes the tab before the
 * browser callback returns. Verifies the X-Razorpay-Signature HMAC, then calls
 * the same idempotent fulfill_payment() used by the checkout callback.
 *
 * Configure in Razorpay Dashboard -> Settings -> Webhooks:
 *   URL:     https://saathi.neermedia.com/razorpay_webhook.php
 *   Events:  payment.captured, order.paid
 *   Secret:  must equal RAZORPAY_WEBHOOK_SECRET in config.local.php
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/payments.php';
header('Content-Type: application/json');

$secret = (string) cfg('RAZORPAY_WEBHOOK_SECRET');
$body   = file_get_contents('php://input') ?: '';
$sig    = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if ($secret === '' || $sig === '' || !hash_equals(hash_hmac('sha256', $body, $secret), $sig)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_signature']);
    exit;
}

$evt     = json_decode($body, true) ?: [];
$type    = (string)($evt['event'] ?? '');
$orderId = $evt['payload']['payment']['entity']['order_id']
        ?? ($evt['payload']['order']['entity']['id'] ?? '');
$payId   = $evt['payload']['payment']['entity']['id'] ?? null;

try {
    if (in_array($type, ['payment.captured', 'order.paid'], true) && $orderId) {
        $pay = payment_by_order_id((string)$orderId);
        if ($pay && $pay['status'] !== 'paid') {
            $renew = !empty($pay['renew_license_id']) ? (int)$pay['renew_license_id'] : null;
            fulfill_payment((int)$pay['id'], $renew, $payId);
            audit('webhook_fulfilled', ['order' => $orderId, 'event' => $type], 'system');
        }
    }
} catch (Throwable $e) {
    error_log('[saathi] webhook error: ' . $e->getMessage());
}

http_response_code(200);
echo json_encode(['ok' => true]);
