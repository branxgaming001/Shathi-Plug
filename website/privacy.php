<?php
require __DIR__ . '/includes/bootstrap.php';
$IMG = require __DIR__ . '/assets/images.php';
require __DIR__ . '/includes/layout.php';
page_head([
  'title' => 'Privacy Policy — Saathi',
  'desc'  => 'How Saathi by RAI Labs Pvt. Ltd. collects, uses and protects your data, including account, profile and website-scan information.',
  'slug'  => 'privacy.php',
]);
site_nav('');
?>
<section class="phero"><div class="wrap"><span class="eyebrow">Legal</span><h1>Privacy Policy</h1><p class="lead">How we handle your information at Saathi.</p></div></section>
<section class="section"><div class="wrap"><div class="prose">
  <p class="upd">Last updated: <?=date('d M Y')?></p>
  <p>This Privacy Policy explains how <?=rai_labs()?> ("RAI Labs", "we", "us") collects, uses and protects information when you use the Saathi website, dashboard and WordPress plugin (the "Service").</p>

  <h2>1. Information we collect</h2>
  <ul>
    <li><strong>Account &amp; profile:</strong> your email address, and the profile details you provide (name, company, website domain, mobile number, address, use case, and how you heard about us).</li>
    <li><strong>Authentication:</strong> if you sign in with Google, we receive your verified email and basic profile (name) from Google. We never see your Google password.</li>
    <li><strong>Billing:</strong> plan and payment status. Card details are handled by the payment processor, not stored by us.</li>
    <li><strong>Usage &amp; logs:</strong> license activations, sign-in events, and security/audit logs (including IP address) to keep your account safe.</li>
    <li><strong>Plugin content:</strong> when you run a scan, the plugin indexes only your <strong>published</strong> pages, posts and products to answer your visitors. Drafts, trashed and private content are excluded.</li>
  </ul>

  <h2>2. How we use it</h2>
  <ul>
    <li>To provide and operate the Service, issue and validate licenses, and process plans.</li>
    <li>To send transactional emails (sign-in codes, renewal reminders, account notices).</li>
    <li>To provide support and improve the product.</li>
    <li>To protect against fraud and abuse.</li>
  </ul>

  <h2>3. AI providers &amp; your key</h2>
  <p>Saathi works on a "bring your own key" model. When the bot answers, your chosen AI provider (e.g. OpenAI, Google, OpenRouter) processes the message under their terms. You control which provider and model you use. We do not sell your data or use your content to train third-party models.</p>

  <h2>4. Third-party services</h2>
  <p>We use a small number of trusted processors: an email delivery provider (for sign-in codes and notices), and a payment processor (when paid plans are enabled). Each handles data only to perform its function.</p>

  <h2>5. Cookies &amp; sessions</h2>
  <p>We use a single, secure, http-only session cookie to keep you signed in. We do not use advertising trackers.</p>

  <h2>6. Data retention &amp; security</h2>
  <p>We keep account data while your account is active and as required for legal and accounting reasons. We protect data with encryption in transit, hashed secrets, prepared database queries and access controls.</p>

  <h2>7. Your rights</h2>
  <p>You may access, correct or delete your personal data, or close your account, by contacting us. We will respond within a reasonable period.</p>

  <h2>8. Children</h2>
  <p>The Service is not directed to children under 16 and we do not knowingly collect their data.</p>

  <h2>9. Changes</h2>
  <p>We may update this policy; material changes will be posted here with a new "last updated" date.</p>

  <h2>10. Contact</h2>
  <p>Questions about privacy? Reach us via our <a href="contact.php">contact page</a>. Data controller: <?=rai_labs()?>.</p>
</div></div></section>
<?php site_footer(); page_foot();
