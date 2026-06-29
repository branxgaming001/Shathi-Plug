<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/payments.php';
$IMG = require __DIR__ . '/assets/images.php';
$u = require_login();

$result   = null;   // fulfilment result to display
$checkout = null;   // Razorpay checkout data to render

$result = null;
$razorpay = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'razorpay_verify') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $rzpOrder  = trim((string)($_POST['razorpay_order_id'] ?? ''));
        $rzpPay    = trim((string)($_POST['razorpay_payment_id'] ?? ''));
        $rzpSig    = trim((string)($_POST['razorpay_signature'] ?? ''));

        $st = pdo()->prepare("SELECT * FROM payments WHERE id=? AND user_id=? AND status='created'");
        $st->execute([$paymentId, (int)$u['id']]);
        $pay = $st->fetch();

        if (!$pay) {
            $result = ['ok' => false, 'error' => 'payment_not_found'];
        } elseif ((string)$pay['gateway_order_id'] !== $rzpOrder) {
            $result = ['ok' => false, 'error' => 'order_mismatch'];
        } elseif (!razorpay_verify_signature($rzpOrder, $rzpPay, $rzpSig)) {
            $result = ['ok' => false, 'error' => 'signature_failed'];
        } else {
            $result = fulfill_payment($paymentId, $order['renew'] ?: null, $rzpPay);
            if (!empty($result['ok'])) unset($_SESSION['order']);
        }
    } else {
        $plan = plan_by_id((int)$order['plan_id']);
        if (!$plan) redirect('index.php#pricing');

        $intent = payment_create((int)$u['id'], $plan);

        if (payment_mode() === 'test' || (int)$plan['price_inr'] === 0) {
            $result = fulfill_payment((int)$intent['payment_id'], $order['renew'] ?: null, 'TEST-' . bin2hex(random_bytes(4)));
            unset($_SESSION['order']);
        } elseif (empty($intent['order_id'])) {
            $result = ['ok' => false, 'error' => 'razorpay_order_failed'];
        } else {
            $razorpay = [
                'key'        => cfg('RAZORPAY_KEY_ID'),
                'amount'     => (int)$intent['amount'] * 100,
                'currency'   => 'INR',
                'name'       => 'Saathi',
                'description'=> $plan['name'] . ' plan',
                'order_id'   => $intent['order_id'],
                'payment_id' => (int)$intent['payment_id'],
                'prefill'    => [
                    'name'  => trim((string)(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?: ($u['name'] ?? ''),
                    'email' => $u['email'] ?? '',
                    'contact' => $u['mobile'] ?? '',
                ],
                'theme' => ['color' => '#6D5DFB'],
            ];
        }
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
<title>Payment - Saathi</title><link rel="icon" href="<?=$IMG['logo']?>">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/app.css">
</head><body><div class="auth-wrap"><div class="auth-card">
  <a class="brand" href="dashboard.php" style="justify-content:center;margin-bottom:14px"><img src="<?=$IMG['logo']?>" alt="" style="width:34px;height:34px">Saathi</a>

  <?php if ($razorpay): ?>
    <h2 style="text-align:center">Complete payment</h2>
    <p class="auth-sub">Razorpay will open securely. After payment, Saathi verifies the signature on the server before issuing your license.</p>
    <button class="btn btn-primary btn-block btn-lg" id="rzp-open">Pay securely</button>
    <a class="btn btn-ghost btn-block" href="checkout.php" style="margin-top:10px">Back</a>
    <form id="rzp-verify" method="post" style="display:none">
      <?=csrf_field()?>
      <input type="hidden" name="action" value="razorpay_verify">
      <input type="hidden" name="payment_id" value="<?=(int)$razorpay['payment_id']?>">
      <input type="hidden" name="razorpay_order_id">
      <input type="hidden" name="razorpay_payment_id">
      <input type="hidden" name="razorpay_signature">
    </form>
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <script>
    (function(){
      var options = <?=json_encode($razorpay, JSON_UNESCAPED_SLASHES)?>;
      options.handler = function (response) {
        var form = document.getElementById('rzp-verify');
        form.elements.razorpay_order_id.value = response.razorpay_order_id || options.order_id;
        form.elements.razorpay_payment_id.value = response.razorpay_payment_id || '';
        form.elements.razorpay_signature.value = response.razorpay_signature || '';
        form.submit();
      };
      options.modal = { ondismiss: function(){ document.getElementById('rzp-open').disabled = false; } };
      var checkout = new Razorpay(options);
      var btn = document.getElementById('rzp-open');
      btn.addEventListener('click', function(e){ e.preventDefault(); btn.disabled = true; checkout.open(); });
      setTimeout(function(){ btn.click(); }, 250);
    })();
    </script>

  <?php elseif ($result && !empty($result['ok'])): ?>
    <div style="text-align:center;font-size:46px">&#10003;</div>
    <h2 style="text-align:center"><?=!empty($result['renewed'])?'Renewed!':'Payment successful!'?></h2>
    <?php if (!empty($result['license_key'])): ?>
      <p class="auth-sub">Here is your license key. Copy it now and paste it into the plugin. It is also saved in your dashboard.</p>
      <div class="msg ok" style="text-align:center;font-size:18px;letter-spacing:1px;font-weight:800"><?=e($result['license_key'])?></div>
    <?php else: ?>
      <p class="auth-sub">Your license has been <?=!empty($result['renewed'])?'renewed':'updated'?>. See it in your dashboard.</p>
    <?php endif; ?>
    <a class="btn btn-primary btn-block" href="dashboard.php" style="margin-top:10px">Go to dashboard</a>

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
