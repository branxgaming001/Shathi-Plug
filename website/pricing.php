<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/payments.php';
$IMG = require __DIR__ . '/assets/images.php';
require __DIR__ . '/includes/layout.php';

$plans = plans_all();

$faqs = [
  ['Do I need a paid AI account?', 'No. Saathi works with any of 15 AI providers, including free models. You add your own key and stay in control of usage and cost.'],
  ['What does Lifetime mean?', 'Pay once and use Saathi forever on the included websites, with all future updates. No recurring charge.'],
  ['Can I switch plans later?', 'Yes — upgrade or downgrade anytime from your dashboard. Your license updates automatically.'],
  ['Is there a refund policy?', 'Yes. See our Refund &amp; Cancellation Policy for the window and eligibility.'],
  ['Are payments secure?', 'Payments are processed securely. The platform is currently in test mode; live payment processing is being enabled shortly.'],
];
$faqSchema = json_encode([
  '@context'=>'https://schema.org','@type'=>'FAQPage',
  'mainEntity'=>array_map(fn($f)=>['@type'=>'Question','name'=>strip_tags($f[0]),'acceptedAnswer'=>['@type'=>'Answer','text'=>strip_tags($f[1])]], $faqs),
], JSON_UNESCAPED_SLASHES);

page_head([
  'title' => 'Saathi Pricing — Free, Pro & Lifetime Plans',
  'desc'  => 'Simple, honest pricing for the Saathi WordPress AI chatbot. Start free, go Pro monthly, or pay once for Lifetime. Bring your own AI key.',
  'slug'  => 'pricing.php',
  'schema'=> $faqSchema,
]);
site_nav('pricing');
$check = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:16px;height:16px"><path d="M20 6 9 17l-5-5"/></svg>';
?>
<section class="phero"><div class="wrap">
  <span class="eyebrow">Pricing</span>
  <h1>Simple, honest pricing</h1>
  <p class="lead">Start free. Upgrade when you're ready. <strong>Lifetime</strong> means pay once — forever. You bring your own AI key, so there are no surprise usage bills from us.</p>
</div></section>

<section class="section" style="padding-top:40px"><div class="wrap">
  <div class="prices">
    <?php foreach ($plans as $p):
      $pop = $p['code'] === 'pro';
      $period = $p['period'] === 'lifetime' ? '/once' : ($p['period'] === 'year' ? '/year' : '/month');
      if ((int)$p['price_inr'] === 0) $period = '/forever';
      $feats = array_filter(array_map('trim', explode('|', (string)$p['features'])));
    ?>
    <div class="price <?=$pop?'pop':''?>">
      <h4><?=e($p['name'])?></h4>
      <div class="amt">₹<?=number_format((int)$p['price_inr'])?><span><?=$period?></span></div>
      <ul>
        <?php foreach ($feats as $f): ?><li><?=$check?> <?=e($f)?></li><?php endforeach; ?>
      </ul>
      <a class="btn <?=$pop?'btn-primary':'btn-ghost'?>" href="checkout.php?plan=<?=urlencode($p['code'])?>">
        <?=((int)$p['price_inr']===0)?'Start free':'Choose '.e($p['name'])?>
      </a>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="sub" style="margin-top:26px">All plans include the core agentic AI chat, multilingual replies, and the customizable widget. WooCommerce in-chat selling, deep knowledge scan and priority support come with Pro &amp; Lifetime.</p>
</div></section>

<section class="section" style="padding-top:0"><div class="wrap">
  <h2>Pricing FAQ</h2>
  <div class="faq">
    <?php foreach ($faqs as $q) echo '<details class="qa"><summary>'.$q[0].' <span>+</span></summary><p>'.$q[1].'</p></details>'; ?>
  </div>
</div></section>

<section class="wrap"><div class="cta-band">
  <h2>Try Saathi on your site today</h2>
  <p>Free to start, with the friendliest AI chatbot in WordPress — built by <?=rai_labs()?>.</p>
  <a class="btn btn-ghost btn-lg" href="login.php">Get started free</a>
</div></section>
<?php site_footer(); page_foot();
