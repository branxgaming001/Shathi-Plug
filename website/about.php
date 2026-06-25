<?php
require __DIR__ . '/includes/bootstrap.php';
$IMG = require __DIR__ . '/assets/images.php';
require __DIR__ . '/includes/layout.php';
page_head([
  'title' => 'About Saathi & NEER Media',
  'desc'  => 'Saathi is built by NEER Media — an AI chatbot for WordPress & WooCommerce that supports and sells, trained on your real website content.',
  'slug'  => 'about.php',
]);
site_nav('about');
?>
<section class="phero"><div class="wrap">
  <span class="eyebrow">About us</span>
  <h1>We're building the friendliest AI for your website</h1>
  <p class="lead">Saathi is an agentic AI chatbot that answers your visitors from your <strong>real</strong> content and sells your products right inside the chat — made by <?=rai_labs()?>.</p>
  <div class="cta-row">
    <a class="btn btn-primary btn-lg" href="login.php">Get started free</a>
    <a class="btn btn-ghost btn-lg" href="index.php#top">Try the live bot</a>
  </div>
</div></section>

<section class="section"><div class="wrap">
  <div class="feat">
    <div class="feat-text">
      <span class="eyebrow">Our mission</span>
      <h2 style="margin-top:8px">Make every website feel personally helpful</h2>
      <p>Most website visitors leave with their question unanswered. Support teams can't be awake 24/7, and generic chatbots make up answers that hurt trust. We started Saathi to fix both — an assistant that actually <strong>knows your site</strong>, speaks your visitor's language, and turns questions into happy customers.</p>
      <p>Saathi reads only your <strong>published</strong> pages, posts and products, so every reply is grounded in your real content — never hallucinated. And because it understands your catalog, it can recommend and sell, not just chat.</p>
    </div>
    <div class="feat-img"><img src="<?=$IMG['feature-knowledge']?>" alt="Saathi grounded knowledge"></div>
  </div>
</div></section>

<section class="section" style="padding-top:0"><div class="wrap">
  <span class="eyebrow" style="display:block;text-align:center;margin:0 auto">What makes us different</span>
  <h2>Support <em>and</em> sales — on any AI you like</h2>
  <p class="sub">Other bots are either support-only or sales-only. Saathi does both, privately, on your terms.</p>
  <div class="cards">
    <?php
    $items = [
      ['🧠','Grounded in your content','Answers from your real published pages, posts and products — no hallucinations, no off-brand replies.'],
      ['🛍️','Sells inside the chat','Shows product cards with Add to Cart & Buy Now, so conversations become orders.'],
      ['🔌','Bring your own AI','Works with 15 providers including free models — you stay in control of cost and data.'],
      ['🔒','Privacy-first','Ignores drafts and private data, stays in scope, and respects your visitors.'],
      ['🌍','Speaks every language','Auto-replies in 40+ languages, matching whatever your visitor types.'],
      ['🎨','Truly yours','Your brand colour and a friendly mascot — the whole widget re-themes instantly.'],
    ];
    foreach ($items as $c) echo '<div class="card"><div class="ico" style="font-size:24px">'.$c[0].'</div><h4>'.e($c[1]).'</h4><p>'.e($c[2]).'</p></div>';
    ?>
  </div>
</div></section>

<section class="section" style="padding-top:0"><div class="wrap">
  <div class="demo">
    <span class="eyebrow">The company</span>
    <h2 style="margin-top:10px">Made by NEER Media</h2>
    <p>Saathi is built and maintained by <?=rai_labs()?>, a software studio focused on practical, privacy-respecting AI products. We believe powerful AI should be affordable, transparent, and genuinely useful for real businesses — not locked behind enterprise pricing. Learn more about us at <a href="https://neermedia.com" target="_blank" rel="noopener" style="color:var(--v);font-weight:600">neermedia.com ↗</a>.</p>
    <a class="btn btn-primary btn-lg" href="contact.php" style="margin-top:6px">Get in touch</a>
  </div>
</div></section>

<section class="wrap"><div class="cta-band">
  <h2>Give your website a Saathi</h2>
  <p>Turn visitors into customers with an AI that supports and sells — set up in about five minutes.</p>
  <a class="btn btn-ghost btn-lg" href="login.php">Start free</a>
</div></section>
<?php site_footer(); page_foot();
