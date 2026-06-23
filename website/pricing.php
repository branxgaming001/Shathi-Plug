<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/payments.php';
$IMG = require __DIR__ . '/assets/images.php';
require __DIR__ . '/includes/layout.php';

$plans = plans_all();              // active, sorted
$byCode = [];
foreach ($plans as $p) $byCode[$p['code']] = $p;
$cardCodes = ['free', 'pro', 'max'];

$faqs = [
  ['Do I need a paid AI account?', 'No. Saathi works with any of ~15 AI providers, including free models. You bring your own key, so you stay in control of usage and cost — and the Free plan can run a real chatbot at no cost.'],
  ['What\'s the difference between Pro and Max?', 'Pro unlocks the full assistant — all 8 mascots, multiple & AI-built personas, memory, navigation and priority support. Max adds the commerce & advanced features: WooCommerce product showcase, deep site scan, self-improving AI, smart follow-up questions and direct add-to-cart in chat.'],
  ['Can I switch or upgrade later?', 'Yes — upgrade or downgrade anytime from your dashboard. Your license updates automatically.'],
  ['How many websites does each plan cover?', 'One website per licence — on every plan (Free, Pro and Max). Running more than one site? Just add a licence for each site.'],
  ['Do you offer refunds?', 'Yes — a 7-day money-back guarantee. See our Refund &amp; Cancellation Policy.'],
  ['Which currency am I charged in?', 'Prices are shown in Indian Rupees (₹) with an approximate USD figure. Buyers worldwide are welcome.'],
];
$faqSchema = json_encode([
  '@context'=>'https://schema.org','@type'=>'FAQPage',
  'mainEntity'=>array_map(fn($f)=>['@type'=>'Question','name'=>strip_tags($f[0]),'acceptedAnswer'=>['@type'=>'Answer','text'=>strip_tags($f[1])]], $faqs),
], JSON_UNESCAPED_SLASHES);

$css = '.price .usd{display:block;font-size:13px;color:var(--muted);font-weight:600;margin-top:2px}';

page_head([
  'title' => 'Saathi Pricing — Free, Pro & Max',
  'desc'  => 'Simple, honest pricing for the Saathi WordPress AI chatbot. Start free worldwide, go Pro at ₹499/mo, or unlock everything with Max at ₹699/mo. Bring your own AI key.',
  'slug'  => 'pricing.php',
  'schema'=> $faqSchema,
  'extra_css' => $css,
]);
site_nav('pricing');
$check = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:16px;height:16px"><path d="M20 6 9 17l-5-5"/></svg>';

function plan_period_label(string $code): string {
  return ['free'=>'forever','pro'=>'/month','max'=>'/month'][$code] ?? '';
}
?>
<section class="phero"><div class="wrap">
  <span class="eyebrow">Pricing</span>
  <h1>Simple, honest pricing</h1>
  <p class="lead">Start <strong>free</strong> anywhere in the world. Upgrade when you're ready. You bring your own AI key, so there are no surprise usage bills from us. Prices in ₹ (USD shown too).</p>
</div></section>

<section class="section" style="padding-top:38px"><div class="wrap">
  <div class="prices">
    <?php foreach ($cardCodes as $code):
      $p = $byCode[$code] ?? null; if (!$p) continue;
      $pop = $code === 'max';
      $feats = array_filter(array_map('trim', explode('|', (string)$p['features'])));
      $isFree = (int)$p['price_inr'] === 0;
    ?>
    <div class="price <?=$pop?'pop':''?>">
      <h4><?=e($p['name'])?></h4>
      <div class="amt">₹<?=number_format((int)$p['price_inr'])?><span> <?=plan_period_label($code)?></span>
        <span class="usd"><?=$isFree?'Free forever':'≈ $'.(int)$p['price_usd'].'/mo'?></span>
      </div>
      <ul>
        <?php foreach ($feats as $f): ?><li><?=$check?> <?=e($f)?></li><?php endforeach; ?>
      </ul>
      <a class="btn <?=$pop?'btn-primary':'btn-ghost'?>" href="checkout.php?plan=<?=urlencode($code)?>"><?=$isFree?'Start free':'Choose '.e($p['name'])?></a>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="sub" style="margin-top:26px">All plans include core agentic AI chat, multilingual replies, your own provider, and the customizable widget. The 8 mascots, AI personas and memory come with Pro; WooCommerce selling, deep scan, follow-ups and add-to-cart come with Max. Free is genuinely usable — not a trial.</p>
</div></section>

<section class="section" style="padding-top:0"><div class="wrap">
  <h2>Pricing FAQ</h2>
  <div class="faq">
    <?php foreach ($faqs as $q) echo '<details class="qa"><summary>'.$q[0].' <span>+</span></summary><p>'.$q[1].'</p></details>'; ?>
  </div>
</div></section>

<section class="wrap"><div class="cta-band">
  <h2>Try Saathi on your site today</h2>
  <p>Free to start, anywhere in the world — built by <?=rai_labs()?>.</p>
  <a class="btn btn-ghost btn-lg" href="login.php">Get started free</a>
</div></section>
<?php site_footer(); page_foot();
