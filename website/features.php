<?php
require __DIR__ . '/includes/bootstrap.php';
$IMG = require __DIR__ . '/assets/images.php';
require __DIR__ . '/includes/layout.php';

$schema = json_encode([
  '@context' => 'https://schema.org', '@type' => 'SoftwareApplication',
  'name' => 'Saathi', 'applicationCategory' => 'BusinessApplication',
  'operatingSystem' => 'WordPress', 'description' => 'Agentic AI chatbot for WordPress & WooCommerce that supports customers and sells products inside the chat.',
  'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'INR'],
  'publisher' => ['@type' => 'Organization', 'name' => 'RAI Labs Pvt. Ltd.', 'url' => 'https://railabs.in'],
], JSON_UNESCAPED_SLASHES);

page_head([
  'title' => 'Saathi Features — Support, Sales & Multilingual AI',
  'desc'  => 'Grounded answers from your real content, in-chat WooCommerce selling, 15 AI providers, 40+ languages, 8 mascots and a fully customizable widget for WordPress.',
  'slug'  => 'features.php',
  'schema'=> $schema,
]);
site_nav('features');
$check = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="width:18px;height:18px"><path d="M20 6 9 17l-5-5"/></svg>';
?>
<section class="phero"><div class="wrap">
  <span class="eyebrow">Features</span>
  <h1>Everything your website needs in one bot</h1>
  <p class="lead">Saathi blends agentic support, in-chat commerce and deep site knowledge — wrapped in a premium, fully-customizable widget for WordPress &amp; WooCommerce.</p>
  <div class="cta-row"><a class="btn btn-primary btn-lg" href="login.php">Start free</a><a class="btn btn-ghost btn-lg" href="pricing.php">See pricing</a></div>
</div></section>

<section class="section"><div class="wrap">
  <div class="feat">
    <div class="feat-img"><img src="<?=$IMG['feature-commerce']?>" alt="Saathi product showcase in chat"></div>
    <div class="feat-text">
      <h3>Sell inside the chat 🛍️</h3>
      <p>Saathi understands your WooCommerce catalog and shows real product cards — image, rating and price — then lets customers <strong>Add to Cart</strong> or <strong>Buy Now</strong> without leaving the conversation.</p>
      <ul class="feat-list">
        <li><?=$check?> Live WooCommerce product showcase</li>
        <li><?=$check?> One-tap Add to Cart &amp; instant checkout</li>
        <li><?=$check?> Recommends the right product from your catalog</li>
      </ul>
    </div>
  </div>
  <div class="feat rev">
    <div class="feat-text">
      <h3>Knows your site — not generic answers 🧠</h3>
      <p>A deep scan indexes only your <strong>published</strong> pages, posts and products, so every answer is grounded in your real content. Drafts, trash and private data are ignored automatically.</p>
      <ul class="feat-list">
        <li><?=$check?> Reads your live, current content</li>
        <li><?=$check?> Follow-up suggestions from your real pages</li>
        <li><?=$check?> No hallucinated or off-brand replies</li>
      </ul>
    </div>
    <div class="feat-img"><img src="<?=$IMG['feature-knowledge']?>" alt="Saathi website knowledge scan"></div>
  </div>
  <div class="feat">
    <div class="feat-img"><img src="<?=$IMG['feature-multilingual']?>" alt="Saathi multilingual chat"></div>
    <div class="feat-text">
      <h3>Speaks your visitor's language 🌍</h3>
      <p>English, Hindi, Hinglish, Gujarati and 40+ more — Saathi auto-detects and replies in whatever language each visitor types, keeping your brand and product names intact.</p>
      <ul class="feat-list">
        <li><?=$check?> Automatic language matching</li>
        <li><?=$check?> Works on any AI model — even free ones</li>
        <li><?=$check?> One bot for a global audience</li>
      </ul>
    </div>
  </div>
  <div class="feat rev">
    <div class="feat-text">
      <h3>Make it truly yours 🎨</h3>
      <p>Pick your brand colour and choose from 8 friendly mascots — the whole widget re-themes instantly. Visitors can drag, resize and position the window; you control the defaults.</p>
      <ul class="feat-list">
        <li><?=$check?> Brand colour + 8 mascots</li>
        <li><?=$check?> Drag, resize &amp; placement rules</li>
        <li><?=$check?> Beautiful on mobile &amp; desktop</li>
      </ul>
    </div>
    <div class="feat-img"><img src="<?=$IMG['feature-customize']?>" alt="Saathi customization"></div>
  </div>
</div></section>

<section class="section" style="padding-top:0"><div class="wrap">
  <span class="eyebrow" style="display:block;text-align:center;margin:0 auto">More superpowers</span>
  <h2>Built for real businesses</h2>
  <div class="cards">
    <?php
    $cards = [
      ['🔌','15 AI providers','OpenAI, Claude, Gemini, Grok, DeepSeek, Mistral, OpenRouter, Groq, Ollama & more — bring your own key, even free models.'],
      ['⚡','5-minute setup','Install, paste an AI key, run the scan — and Saathi is live on your site.'],
      ['🧩','Agentic, not canned','Understands intent, guides visitors and takes action instead of repeating scripts.'],
      ['✨','Self-improving','Learns from your content and chats over time, so answers keep getting sharper.'],
      ['📱','Mobile-perfect','Sits cleanly above mobile nav bars, loads fast, fully responsive.'],
      ['🛡️','Secure & private','Refuses sensitive data, respects privacy and stays within your scope.'],
    ];
    foreach ($cards as $c) echo '<div class="card"><div class="ico" style="font-size:24px">'.$c[0].'</div><h4>'.e($c[1]).'</h4><p>'.e($c[2]).'</p></div>';
    ?>
  </div>
</div></section>

<section class="wrap"><div class="cta-band">
  <h2>Ready to see it on your site?</h2>
  <p>Start free, or talk to the live bot right now — the best AI chatbot for WordPress.</p>
  <a class="btn btn-ghost btn-lg" href="login.php">Get started free</a>
</div></section>
<?php site_footer(); page_foot();
