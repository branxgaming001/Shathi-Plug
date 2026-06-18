<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/payments.php';
$IMG = require __DIR__ . '/assets/images.php';
require __DIR__ . '/includes/layout.php';

$plans = plans_all();              // active, sorted
$byCode = [];
foreach ($plans as $p) $byCode[$p['code']] = $p;
$cardCodes = ['free', 'pro', 'lifetime', 'agency'];
$proM = $byCode['pro'] ?? null;
$proY = $byCode['pro_annual'] ?? null;

$faqs = [
  ['Do I need a paid AI account?', 'No. Saathi works with any of ~15 AI providers, including free models. You bring your own key, so you stay in control of usage and cost — and the Free plan can run a real chatbot at no cost.'],
  ['What does Lifetime mean?', 'Pay once and use Saathi forever on the included websites, with all future updates. No recurring charge.'],
  ['Can I switch or upgrade later?', 'Yes — upgrade, downgrade or move from monthly to annual anytime from your dashboard. Your license updates automatically.'],
  ['How many websites does each plan cover?', 'Free covers 1 site, Pro 3 sites, Lifetime 5 sites, and Agency 25 sites.'],
  ['Do you offer refunds?', 'Yes — a 7-day money-back guarantee. See our Refund &amp; Cancellation Policy.'],
  ['Which currency am I charged in?', 'Prices are shown in Indian Rupees (₹) with an approximate USD figure. Buyers worldwide are welcome.'],
];
$faqSchema = json_encode([
  '@context'=>'https://schema.org','@type'=>'FAQPage',
  'mainEntity'=>array_map(fn($f)=>['@type'=>'Question','name'=>strip_tags($f[0]),'acceptedAnswer'=>['@type'=>'Answer','text'=>strip_tags($f[1])]], $faqs),
], JSON_UNESCAPED_SLASHES);

$css = '.bill{display:flex;justify-content:center;margin:0 0 28px}'
  . '.bill .seg{display:inline-flex;background:#fff;border:1px solid var(--line);border-radius:999px;padding:4px}'
  . '.bill button{border:0;background:transparent;padding:9px 20px;border-radius:999px;font:inherit;font-weight:700;font-size:14px;color:var(--muted);cursor:pointer}'
  . '.bill button.on{background:var(--v);color:#fff}'
  . '.bill .save{font-size:11px;font-weight:800;color:#19A463;margin-left:4px}'
  . '.price .usd{display:block;font-size:13px;color:var(--muted);font-weight:600;margin-top:2px}';

page_head([
  'title' => 'Saathi Pricing — Free, Pro, Lifetime & Agency',
  'desc'  => 'Simple, honest pricing for the Saathi WordPress AI chatbot. Start free worldwide, go Pro monthly or annual, pay once for Lifetime, or scale with Agency. Bring your own AI key.',
  'slug'  => 'pricing.php',
  'schema'=> $faqSchema,
  'extra_css' => $css,
]);
site_nav('pricing');
$check = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:16px;height:16px"><path d="M20 6 9 17l-5-5"/></svg>';

function plan_period_label(string $code): string {
  return ['free'=>'/forever','pro'=>'/month','pro_annual'=>'/year','lifetime'=>'one-time','agency'=>'one-time'][$code] ?? '';
}
?>
<section class="phero"><div class="wrap">
  <span class="eyebrow">Pricing</span>
  <h1>Simple, honest pricing</h1>
  <p class="lead">Start <strong>free</strong> anywhere in the world. Upgrade when you're ready. You bring your own AI key, so there are no surprise usage bills from us. Prices in ₹ (USD shown too).</p>
</div></section>

<section class="section" style="padding-top:38px"><div class="wrap">
  <div class="bill">
    <div class="seg">
      <button id="billM" class="on" onclick="setBill('m')">Monthly</button>
      <button id="billY" onclick="setBill('y')">Annual <span class="save">save 33%</span></button>
    </div>
  </div>

  <div class="prices">
    <?php foreach ($cardCodes as $code):
      $p = $byCode[$code] ?? null; if (!$p) continue;
      $pop = $code === 'pro';
      $feats = array_filter(array_map('trim', explode('|', (string)$p['features'])));
    ?>
    <div class="price <?=$pop?'pop':''?>">
      <h4><?=e($p['name'])?></h4>
      <?php if ($code === 'pro' && $proY): ?>
        <div class="amt" id="proAmt">
          <span id="proPrice">₹<?=number_format((int)$proM['price_inr'])?></span><span id="proPer">/month</span>
          <span class="usd" id="proUsd">≈ $<?=(int)$proM['price_usd']?>/mo</span>
        </div>
      <?php else: ?>
        <div class="amt">₹<?=number_format((int)$p['price_inr'])?><span> <?=plan_period_label($code)?></span>
          <span class="usd"><?=((int)$p['price_usd']===0)?'Free forever':'≈ $'.(int)$p['price_usd'].' '.trim(plan_period_label($code),'/')?></span>
        </div>
      <?php endif; ?>
      <ul>
        <?php foreach ($feats as $f): ?><li><?=$check?> <?=e($f)?></li><?php endforeach; ?>
      </ul>
      <?php if ($code === 'pro'): ?>
        <a class="btn btn-primary" id="proCta" href="checkout.php?plan=pro">Choose Pro</a>
      <?php else: ?>
        <a class="btn <?=$pop?'btn-primary':'btn-ghost'?>" href="checkout.php?plan=<?=urlencode($code)?>"><?=((int)$p['price_inr']===0)?'Start free':'Choose '.e($p['name'])?></a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="sub" style="margin-top:26px">All plans include core agentic AI chat, multilingual replies, and the customizable widget. WooCommerce in-chat selling, the full deep-scan and priority support come with Pro and above. Free is genuinely usable — not a trial.</p>
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

<script>
(function(){
  var M={price:'₹<?=number_format((int)($proM['price_inr']??999))?>',per:'/month',usd:'≈ $<?=(int)($proM['price_usd']??12)?>/mo',cta:'checkout.php?plan=pro'};
  var Y={price:'₹<?=number_format((int)($proY['price_inr']??7999))?>',per:'/year',usd:'≈ $<?=(int)($proY['price_usd']??89)?>/yr',cta:'checkout.php?plan=pro_annual'};
  window.setBill=function(which){
    var y=which==='y';
    document.getElementById('billM').classList.toggle('on',!y);
    document.getElementById('billY').classList.toggle('on',y);
    var d=y?Y:M, pp=document.getElementById('proPrice'),per=document.getElementById('proPer'),us=document.getElementById('proUsd'),cta=document.getElementById('proCta');
    if(pp)pp.textContent=d.price; if(per)per.textContent=d.per; if(us)us.textContent=d.usd; if(cta)cta.setAttribute('href',d.cta);
  };
})();
</script>
<?php site_footer(); page_foot();
