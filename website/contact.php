<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/mailer.php';
$IMG = require __DIR__ . '/assets/images.php';
require __DIR__ . '/includes/layout.php';

$sent = false; $err = ''; $in = ['name'=>'','email'=>'','subject'=>'','message'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($in as $k => $_) $in[$k] = trim((string)($_POST[$k] ?? ''));
    if ($in['name'] === '' || mb_strlen($in['name']) > 120) $err = 'Please enter your name.';
    elseif (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) $err = 'Please enter a valid email address.';
    elseif ($in['message'] === '' || mb_strlen($in['message']) > 4000) $err = 'Please write your message.';
    else {
        // Store the enquiry (table created on demand).
        try {
            pdo()->exec("CREATE TABLE IF NOT EXISTS contacts(
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120), email VARCHAR(190), subject VARCHAR(160), message TEXT,
                ip VARCHAR(45), created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            pdo()->prepare("INSERT INTO contacts(name,email,subject,message,ip) VALUES(?,?,?,?,?)")
                 ->execute([$in['name'],$in['email'],$in['subject'],$in['message'],client_ip()]);
        } catch (Throwable $e) { /* non-fatal */ }
        // Notify the team.
        $to = admin_emails()[0] ?? (cfg('MAIL_FROM') ?: 'branxgaming001@gmail.com');
        $html = '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:auto">'
              . '<h2 style="color:#6D5DFB">New enquiry — Saathi</h2>'
              . '<p><b>Name:</b> '.e($in['name']).'<br><b>Email:</b> '.e($in['email']).'<br><b>Subject:</b> '.e($in['subject'] ?: '—').'</p>'
              . '<p style="white-space:pre-wrap;border-left:3px solid #6D5DFB;padding-left:12px">'.e($in['message']).'</p></div>';
        @send_email($to, 'Saathi enquiry: ' . ($in['subject'] ?: $in['name']), $html);
        audit('contact_enquiry', ['email'=>$in['email']], 'system');
        $sent = true; $in = ['name'=>'','email'=>'','subject'=>'','message'=>''];
    }
}

$css = '.cwrap{display:grid;grid-template-columns:1fr 1fr;gap:30px;align-items:start}@media(max-width:760px){.cwrap{grid-template-columns:1fr}}'
     . '.fld{margin-bottom:14px}.fld label{display:block;font-weight:600;font-size:13.5px;margin-bottom:6px}'
     . '.fld input,.fld textarea{width:100%;box-sizing:border-box;border:1.5px solid var(--line);border-radius:11px;padding:11px 13px;font:inherit;font-size:14.5px;outline:none}'
     . '.fld input:focus,.fld textarea:focus{border-color:var(--v)}.fld textarea{min-height:120px;resize:vertical}'
     . '.cinfo .row{display:flex;gap:12px;align-items:flex-start;margin:14px 0}.cinfo .ic{font-size:20px}';
page_head([
  'title' => 'Contact Saathi — Support & Sales',
  'desc'  => 'Get in touch with the Saathi team at NEER Media Questions about features, pricing or setup? Send us a message and we\'ll reply within 1 business day.',
  'slug'  => 'contact.php',
  'extra_css' => $css,
]);
site_nav('contact');
?>
<section class="phero"><div class="wrap">
  <span class="eyebrow">Contact</span>
  <h1>Talk to us</h1>
  <p class="lead">Questions about features, pricing or setup? Send a message and the <?=rai_labs()?> team will get back to you — usually within one business day.</p>
</div></section>

<section class="section"><div class="wrap"><div class="cwrap">
  <div>
    <?php if ($sent): ?>
      <div class="msg ok" style="background:#e8f9f1;border:1px solid #b9e7cf;color:#0f7a4f;padding:14px;border-radius:12px">✅ Thanks! Your message has been sent — we'll reply to your email soon.</div>
    <?php elseif ($err): ?>
      <div class="msg err" style="background:#fdeaee;border:1px solid #f5c2cd;color:#c0264a;padding:12px;border-radius:12px;margin-bottom:14px"><?=e($err)?></div>
    <?php endif; ?>
    <form method="post" autocomplete="on">
      <?=csrf_field()?>
      <div class="fld"><label>Your name</label><input name="name" value="<?=e($in['name'])?>" maxlength="120" required></div>
      <div class="fld"><label>Email address</label><input type="email" name="email" value="<?=e($in['email'])?>" placeholder="you@example.com" required></div>
      <div class="fld"><label>Subject</label><input name="subject" value="<?=e($in['subject'])?>" maxlength="160" placeholder="What's this about?"></div>
      <div class="fld"><label>Message</label><textarea name="message" maxlength="4000" required><?=e($in['message'])?></textarea></div>
      <button class="btn btn-primary btn-lg btn-block">Send message</button>
    </form>
  </div>
  <div class="cinfo">
    <h3 style="margin-top:0">Other ways to reach us</h3>
    <div class="row"><span class="ic">🏢</span><div><strong>NEER Media</strong><br><span class="small" style="color:var(--ink2)">Maker of Saathi · <a href="https://neermedia.com" target="_blank" rel="noopener" style="color:var(--v);font-weight:600">neermedia.com ↗</a></span></div></div>
    <div class="row"><span class="ic">📚</span><div><strong>Self-serve help</strong><br><span class="small" style="color:var(--ink2)">Most setup questions are answered in our <a href="docs.php" style="color:var(--v);font-weight:600">Docs &amp; Help center</a>.</span></div></div>
    <div class="row"><span class="ic">💬</span><div><strong>Try the live bot</strong><br><span class="small" style="color:var(--ink2)">Ask Saathi itself on our <a href="index.php#top" style="color:var(--v);font-weight:600">home page</a>.</span></div></div>
    <div class="row"><span class="ic">⏱️</span><div><strong>Response time</strong><br><span class="small" style="color:var(--ink2)">Within 1 business day, Mon–Sat.</span></div></div>
  </div>
</div></div></section>
<?php site_footer(); page_foot();
