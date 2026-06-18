<?php
require __DIR__ . '/includes/bootstrap.php';
$IMG = require __DIR__ . '/assets/images.php';
require __DIR__ . '/includes/layout.php';
page_head([
  'title' => 'Terms of Service — Saathi',
  'desc'  => 'The terms for using Saathi by RAI Labs Pvt. Ltd. — plans, licenses, acceptable use, and liability.',
  'slug'  => 'terms.php',
]);
site_nav('');
?>
<section class="phero"><div class="wrap"><span class="eyebrow">Legal</span><h1>Terms of Service</h1><p class="lead">The agreement for using Saathi.</p></div></section>
<section class="section"><div class="wrap"><div class="prose">
  <p class="upd">Last updated: <?=date('d M Y')?></p>
  <p>These Terms govern your use of the Saathi website, dashboard and WordPress plugin (the "Service"), provided by <?=rai_labs()?>. By creating an account or using the Service, you agree to these Terms.</p>

  <h2>1. Accounts</h2>
  <p>You must provide accurate information and keep your account secure. You are responsible for activity under your account. One person or business per account.</p>

  <h2>2. Plans &amp; billing</h2>
  <p>We offer Free, Pro (subscription) and Lifetime (one-time) plans. Paid features are available while your plan is active. Prices are shown in INR and may change with notice; existing Lifetime purchases are honoured. Taxes may apply.</p>

  <h2>3. Licenses</h2>
  <p>Each plan includes a license key valid for a set number of website domains ("activations"). You may not share, resell or exceed your activation limit. We may revoke licenses used in violation of these Terms.</p>

  <h2>4. Bring-your-own AI key</h2>
  <p>Saathi requires you to connect your own AI provider key. You are responsible for your provider account, its costs and its terms. We are not liable for third-party AI outputs, outages or charges.</p>

  <h2>5. Acceptable use</h2>
  <ul>
    <li>Do not use the Service unlawfully, to harass, or to generate harmful, deceptive or infringing content.</li>
    <li>Do not attempt to break, overload, reverse-engineer or bypass security or licensing.</li>
    <li>Do not use Saathi to collect sensitive personal data from visitors without proper consent.</li>
  </ul>

  <h2>6. Intellectual property</h2>
  <p>Saathi, its branding and code are owned by RAI Labs Pvt. Ltd. Your website content remains yours. You grant us the limited rights needed to operate the Service for you.</p>

  <h2>7. Disclaimers</h2>
  <p>The Service is provided "as is". AI responses may be imperfect; you are responsible for reviewing how the bot is configured and what it is allowed to do on your site.</p>

  <h2>8. Limitation of liability</h2>
  <p>To the maximum extent permitted by law, RAI Labs Pvt. Ltd. is not liable for indirect or consequential damages, and total liability is limited to the amount you paid for the Service in the preceding 12 months.</p>

  <h2>9. Termination</h2>
  <p>You may stop using the Service anytime. We may suspend or terminate accounts that violate these Terms.</p>

  <h2>10. Governing law</h2>
  <p>These Terms are governed by the laws of India, with exclusive jurisdiction of the competent courts at our registered location.</p>

  <h2>11. Contact</h2>
  <p>Questions about these Terms? Use our <a href="contact.php">contact page</a>.</p>
</div></div></section>
<?php site_footer(); page_foot();
