<?php
/**
 * HOSTINGER / shared-hosting config.
 * 1) In hPanel, create a MySQL database + user, then fill the 4 DB values below.
 * 2) Rename this file to:  config.local.php
 * 3) Keep it private (never share these values).
 *
 * The app reads everything from here on shared hosting (no env vars needed).
 */

// ---- MySQL (from hPanel → Databases) ----
putenv('DB_HOST=localhost');                 // Hostinger usually 'localhost'
putenv('DB_PORT=3306');
putenv('DB_NAME=REPLACE_DB_NAME');           // e.g. u956995489_saathi
putenv('DB_USER=REPLACE_DB_USER');           // e.g. u956995489_saathi
putenv('DB_PASS=REPLACE_DB_PASSWORD');

// ---- Security secret (used to hash OTP + license keys) ----
// Generate once and NEVER change later, or issued license keys stop validating.
putenv('APP_SECRET=REPLACE_WITH_LONG_RANDOM_STRING');

// ---- Initial admin (seeded on first run; change the password after login) ----
putenv('ADMIN_USER=admin');
putenv('ADMIN_PASS=REPLACE_STRONG_ADMIN_PASSWORD');

// ---- Cron token (for /cron.php?token=...) ----
putenv('CRON_TOKEN=REPLACE_RANDOM_TOKEN');

// ---- Public URL (used in renewal emails) ----
putenv('PUBLIC_URL=https://railabs.in');

// Optional (leave blank to keep TEST/dev modes; can also set later in Admin → Settings):
// putenv('OPENROUTER_API_KEY=...');  putenv('OPENROUTER_MODEL=...');   // real bot AI
// putenv('RAZORPAY_KEY_ID=...');     putenv('RAZORPAY_KEY_SECRET=...'); // real payments
// putenv('BREVO_API_KEY=...');       putenv('MAIL_FROM=no-reply@railabs.in'); // real OTP email

// ---- Razorpay (license checkout) ----
// Use rzp_test_* keys to validate end-to-end, then swap to rzp_live_* for production.
// payment_mode() auto-switches to 'razorpay' once KEY_ID + KEY_SECRET are present.
// putenv('RAZORPAY_KEY_ID=rzp_test_xxxxxxxx');
// putenv('RAZORPAY_KEY_SECRET=xxxxxxxxxxxxxxxx');
// RAZORPAY_WEBHOOK_SECRET must match the secret you set on the Dashboard webhook
// (Settings -> Webhooks -> https://saathi.neermedia.com/razorpay_webhook.php, events: payment.captured, order.paid)
// putenv('RAZORPAY_WEBHOOK_SECRET=xxxxxxxxxxxxxxxx');
