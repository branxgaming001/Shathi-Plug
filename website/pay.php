<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/payments.php';
$IMG = require __DIR__ . '/assets/images.php';
$u = require_login();

$result   = null;   // fulfilment result to display
$checkout = null;   // Razorpay checkout data to render

$method = $_SERVER['REQUEST_METHOD'];
$action = $_POST['action'] ?? '';

if ($method === 'POST' && $action === 'verify') {
    // ---- Razorpay callback: verify signature server-side, then fulfil ----
    csrf_check();
    $orderId = (string)($_POST['razorpay_order_id'] ?? '');
    $payId   = (string)($_POST['razorpay_payment_id'] ?? '');
    $sig     = (string)($_POST['razorpay_signature'] ?? '');
    $pay = $orderId !== '' ? payment_by_order_id($orderId) : null;
    if (!$pay || (int)$pay['user_id'] !== (int)$u['id']) {
        $result = ['ok'=>false,'error'=>'We could not match this payment to your account.'];
    } elseif (!razorpay_verify_signature($orderId, $payId, $sig)) {
        $result = ['ok'=>false,'error'=>'Payment signature could not be verified. If you were charged, it will be reconciled automatically.'];
        audit('payment_signature_failed', ['order'=>$orderId]);
    } else {
        $renew = !empty($pay['renew_license_id']) ? (int)$pay['renew_license_id'] : null;
        $result = fulfill_payment((int)$pay['id'], $renew, $payId);
        unset($_SESSION['order']);
    }
}
elseif ($method === 'POST') {
    // ---- Start checkout: create a server-priced intent ----
    csrf_check();
    $order = $_SESSION['order'] ?? null;
    if (!$order) redirect('dashboard.php');
    $plan = plan_by_id((int)$order['plan_id']);
    if (!$plan) redirect('index.php#pricing');
    $renew  = !empty($order['renew']) ? (int)$order['renew'] : null;
    $intent = payment_create((int)$u['id'], $plan, $renew);

    if ($intent['mode'] === 'test' || (int)$plan['price_inr'] === 0) {
        // Test mode / free plan: simulate a verified success then fulfil.
        $result = fulfill_payment((int)$intent['payment_id'], $renew, 'TEST-' . bin2hex(random_bytes(4)));
        unset($_SESSION['order']);
    } elseif ($intent['mode'] === 'razorpay' && !empty($intent['order_id'])) {
        $checkout = ['order_id'=>$intent['order_id'], 'amount_paise'=>(int)$intent['amount'] * 100, 'plan'=>$plan];
    } else {
        $result = ['ok'=>false,'error'=>$intent['error'] ?? 'Payment gateway is not available right now. Please try again shortly.'];
    }
}
else {
    // GET — checkout must originate from checkout.php
    if (empty($_SESSION['order'])) redirect('dashboard.php');
    redirect('checkout.php');
}

$RZP_KEY = (string)cfg('RAZORPAY_KEY_ID');
$fullName = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? ''));
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payment — Saathi</title><link rel="icon" href="<?=$IMG['logo']?>">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/app.css">
</head><body><div class="auth-wrap"><div class="auth-card">
  <a class="brand" href="dashboard.php" style="justify-content:center;margin-bottom:14px"><img src="<?=$IMG['logo']?>" alt="" style="width:34px;height:34px">Saathi</a>

<?php if ($checkout): ?>
  <h2 style="text-align:center">Complete your payment</h2>
  <p class="auth-sub" style="text-align:center"><?=e($checkout['plan']['name'])?> — ₹<?=number_format((int)$checkout['plan']['price_inr'])?></p>
  <div class="msg" id="rzp-status">Opening secure Razorpay checkout…</div>
  <button id="rzp-pay" class="btn btn-primary btn-block btn-lg" style="margin-top:8px">Pay ₹<?=number_format((int)$checkout['plan']['price_inr'])?></button>
  <form id="rzp-verify" method="post" action="pay.php" style="display:none">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="verify">
    <input type="hidden" name="razorpay_payment_id">
    <input type="hidden" name="razorpay_order_id">
    <input type="hidden" name="razorpay_signature">
  </form>
  <p class="small" style="text-align:center;margin-top:14px"><a href="dashboard.php" style="color:var(--v)">← Cancel</a></p>
  <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
  <script>
    var options = {
      key: <?=json_encode($RZP_KEY)?>,
      amount: <?=json_encode($checkout['amount_paise'])?>,
      currency: "INR",
      name: "Saathi",
      description: <?=json_encode((string)$checkout['plan']['name'] . ' plan')?>,
      image: <?=json_encode((string)$IMG['logo'])?>,
      order_id: <?=json_encode($checkout['order_id'])?>,
      prefill: {
        name: <?=json_encode($fullName)?>,
        email: <?=json_encode((string)($u['email'] ?? ''))?>,
        contact: <?=json_encode((string)($u['mobile'] ?? ''))?>
      },
      theme: { color: "#6D5DFB" },
      handler: function (resp) {
        var f = document.getElementById('rzp-verify');
        f.razorpay_payment_id.value = resp.razorpay_payment_id;
        f.razorpay_order_id.value   = resp.razorpay_order_id;
        f.razorpay_signature.value  = resp.razorpay_signature;
        document.getElementById('rzp-status').textContent = 'Verifying payment…';
        f.submit();
      },
      modal: { ondismiss: function(){ document.getElementById('rzp-status').textContent = 'Payment cancelled — you can try again.'; } }
    };
    var rzp = new Razorpay(options);
    rzp.on('payment.failed', function(r){
      document.getElementById('rzp-status').textContent = 'Payment failed: ' + (r.error && r.error.description ? r.error.description : 'please try again.');
    });
    document.getElementById('rzp-pay').onclick = function(){ rzp.open(); };
    window.addEventListener('load', function(){ rzp.open(); });
  </script>

<?php elseif ($result && !empty($result['ok'])): ?>
  <div style="text-align:center;font-size:46px">🎉</div>
  <h2 style="text-align:center"><?=!empty($result['renewed'])?'Renewed!':'Payment successful!'?></h2>
  <?php if (!empty($result['license_key'])): ?>
    <p class="auth-sub">Here is your license key — copy it now and paste it into the plugin. (Also saved in your dashboard.)</p>
    <div class="msg ok" style="text-align:center;font-size:18px;letter-spacing:1px;font-weight:800"><?=e($result['license_key'])?></div>
  <?php else: ?>
    <p class="auth-sub">Your license has been <?=!empty($result['renewed'])?'renewed':'updated'?>. See it in your dashboard.</p>
  <?php endif; ?>
  <a class="btn btn-primary btn-block" href="dashboard.php" style="margin-top:10px">Go to dashboard</a>

<?php else: ?>
  <h2 style="text-align:center">Couldn't complete</h2>
  <div class="msg err"><?=e($result['error'] ?? 'Payment could not be processed. Please try again.')?></div>
  <a class="btn btn-ghost btn-block" href="checkout.php">Try again</a>
<?php endif; ?>
</div></div></body></html>
