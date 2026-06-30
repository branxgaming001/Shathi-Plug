<?php
require __DIR__ . '/includes/bootstrap.php';
$IMG = require __DIR__ . '/assets/images.php';
require __DIR__ . '/includes/layout.php';
page_head([
  'title' => 'Refund & Cancellation Policy — Saathi',
  'desc'  => 'Saathi\'s refund and cancellation policy by NEER Media — eligibility, the refund window, and how to request a refund.',
  'slug' => 'refund',
]);
site_nav('');
?>
<section class="phero"><div class="wrap"><span class="eyebrow">Legal</span><h1>Refund &amp; Cancellation Policy</h1><p class="lead">Clear and fair — here's how refunds work.</p></div></section>
<section class="section"><div class="wrap"><div class="prose">
  <p class="upd">Last updated: <?=date('d M Y')?></p>
  <p>This policy applies to purchases of Saathi plans from <?=rai_labs()?>.</p>

  <h2>1. 7-day money-back guarantee</h2>
  <p>If Saathi isn't right for you, request a refund within <strong>7 days</strong> of your purchase and we'll refund your payment, provided the license has not been abused (for example, mass activations or resale).</p>

  <h2>2. Subscriptions (Pro)</h2>
  <p>You can cancel your Pro subscription anytime from your dashboard. Cancellation stops future renewals; your plan stays active until the end of the current billing period. Renewal charges are not refundable after the 7-day window, except where required by law.</p>

  <h2>3. Lifetime purchases</h2>
  <p>Lifetime plans are eligible for the 7-day money-back guarantee. After 7 days, Lifetime purchases are non-refundable as you retain perpetual access.</p>

  <h2>4. Free plan</h2>
  <p>The Free plan involves no payment, so no refund applies.</p>

  <h2>5. Third-party AI costs</h2>
  <p>Saathi uses your own AI provider key. Any charges from your AI provider are billed by them and are outside our control and refunds.</p>

  <h2>6. How to request a refund</h2>
  <p>Email us through the <a href="/contact">contact page</a> with your account email and order details. Approved refunds are issued to your original payment method within 5–10 business days.</p>

  <h2>7. Contact</h2>
  <p>Questions about refunds? Reach <?=rai_labs()?> via our <a href="/contact">contact page</a>.</p>
</div></div></section>
<?php site_footer(); page_foot();
