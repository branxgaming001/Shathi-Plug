<?php
$YEAR = date('Y');
$IMG = require __DIR__ . '/assets/images.php';
$FR = @include __DIR__ . '/assets/mascot_frames.php';
if (!is_array($FR)) $FR = [$IMG['mascot-1']];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Saathi — the AI chatbot that supports & sells for your website</title>
<meta name="description" content="Saathi is an agentic AI support + sales assistant for WordPress & WooCommerce. Knows your site, sells your products in chat, speaks every language, fully customizable, and improves over time.">
<link rel="icon" href="<?=$IMG['logo']?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css">
<style>
/* ===== Hero upgrade + provider slider (v2) ===== */
.hero.blobs:before,.hero.blobs:after{content:"";position:absolute;border-radius:50%;filter:blur(72px);z-index:0;pointer-events:none}
.hero.blobs:before{width:460px;height:460px;background:rgba(109,93,251,.20);top:-130px;left:-110px}
.hero.blobs:after{width:400px;height:400px;background:rgba(255,107,94,.16);top:10px;right:-120px}
.hero .wrap{position:relative;z-index:2}
.hero h1 .grad.shine{background-image:linear-gradient(120deg,var(--v),var(--c2),var(--v));background-size:200% auto;-webkit-background-clip:text;background-clip:text;color:transparent;animation:shine 5s linear infinite}
@keyframes shine{to{background-position:200% center}}
.hero-art{min-height:380px;display:flex;align-items:center;justify-content:center}
#heroMascot{position:relative;width:300px;height:300px;object-fit:contain;border-radius:0;box-shadow:none;filter:drop-shadow(0 30px 44px rgba(109,93,251,.40));z-index:3;cursor:pointer;transition:opacity .3s ease;animation:none}
@media(max-width:860px){#heroMascot{width:228px;height:228px}}
.ring{position:absolute;border:2px dashed rgba(109,93,251,.22);border-radius:50%;z-index:1}
.ring.r1{width:410px;height:410px;animation:spin 26s linear infinite}
.ring.r2{width:288px;height:288px;border-color:rgba(255,107,94,.26);animation:spin 18s linear infinite reverse}
@media(max-width:860px){.ring.r1{width:300px;height:300px}.ring.r2{width:216px;height:216px}}
@keyframes spin{to{transform:rotate(360deg)}}
.speech{position:absolute;top:4%;right:0;background:#fff;border:1px solid var(--line);border-radius:16px 16px 16px 4px;padding:10px 14px;font-size:13px;font-weight:700;color:var(--ink);box-shadow:var(--shadow-sm);z-index:4;max-width:200px}
.float-card{position:absolute;background:#fff;border:1px solid var(--line);border-radius:14px;padding:9px 13px;box-shadow:var(--shadow-sm);font-size:13px;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:8px;z-index:4}
.float-card .dot{width:9px;height:9px;border-radius:50%}
.fc1{bottom:30px;left:-4px;animation:bob 4s ease-in-out infinite}
.fc2{bottom:118px;right:-6px;animation:bob 5s ease-in-out infinite}
@keyframes bob{50%{transform:translateY(-12px)}}
.spark{position:absolute;width:8px;height:8px;background:#FFC93C;border-radius:50%;opacity:.8;z-index:1;animation:tw 3s ease-in-out infinite;pointer-events:none}
@keyframes tw{0%,100%{transform:scale(.4);opacity:.3}50%{transform:scale(1.2);opacity:.9}}
/* provider slider */
.marq{overflow:hidden;border-top:1px solid var(--line);border-bottom:1px solid var(--line);background:#fff;padding:16px 0}
.marq .track{display:flex;gap:38px;white-space:nowrap;animation:scrollx 26s linear infinite;width:max-content}
.marq:hover .track{animation-play-state:paused}
.marq b{font-family:var(--display);font-weight:700;font-size:18px;color:var(--muted);opacity:.72;display:flex;align-items:center;gap:8px}
@keyframes scrollx{to{transform:translateX(-50%)}}
/* stats */
.statsec{padding:36px 0 6px}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
@media(max-width:760px){.stats{grid-template-columns:repeat(2,1fr)}}
.stat{background:#fff;border:1px solid var(--line);border-radius:20px;padding:22px 16px;text-align:center;box-shadow:var(--shadow-sm)}
.stat .n{font-family:var(--display);font-weight:800;font-size:33px;background:linear-gradient(120deg,var(--v),var(--c2));-webkit-background-clip:text;background-clip:text;color:transparent}
.stat .l{color:var(--muted);font-size:13px;margin-top:3px}
@media(prefers-reduced-motion:reduce){.ring,.spark,.float-card,.marq .track,.hero h1 .grad.shine{animation:none}}
</style>
</head>
<body>

<!-- NAV -->
<header class="nav"><div class="wrap">
  <a class="brand" href="#top"><img src="<?=$IMG['logo']?>" alt="Saathi logo">Saathi</a>
  <nav class="nav-links">
    <a href="#features">Features</a><a href="#pricing">Pricing</a><a href="#faq">FAQ</a>
  </nav>
  <div class="nav-cta">
    <button class="btn btn-ghost" onclick="document.getElementById('sbFab')&&document.getElementById('sbFab').click()">Live demo</button>
    <a class="btn btn-primary" href="login.php">Get started</a>
  </div>
</div></header>

<!-- HERO -->
<section class="hero blobs" id="top"><div class="wrap">
  <div>
    <span class="eyebrow">The best WordPress AI chatbot</span>
    <h1>Meet <span class="grad shine">Saathi</span> — the bot that <span class="grad shine">supports &amp; sells</span> for you.</h1>
    <p class="lead">An agentic AI assistant that learns your real website content, recommends and sells your products inside chat, speaks every visitor's language, and gets smarter over time.</p>
    <div class="cta-row">
      <a class="btn btn-primary btn-lg" href="login.php">Start free →</a>
      <button class="btn btn-ghost btn-lg" onclick="document.getElementById('sbFab')&&document.getElementById('sbFab').click()">▶ Try the live bot</button>
    </div>
  </div>
  <div class="hero-art" id="heroArt">
    <div class="ring r1"></div><div class="ring r2"></div>
    <div class="speech" id="heroSpeech">Hi! I'm Saathi 👋</div>
    <img id="heroMascot" src="<?=$FR[0]?>" alt="Saathi mascot" title="Tap a colour &amp; mascot below to switch me!">
    <div class="float-card fc1"><span class="dot" style="background:#19C37D"></span> Online now</div>
    <div class="float-card fc2"><span class="dot" style="background:#FF6B5E"></span> Added to cart ✓</div>
  </div>
</div></section>

<!-- PROVIDER SLIDER -->
<div class="marq" aria-hidden="true"><div class="track" id="provMarq"></div></div>

<!-- STATS -->
<section class="statsec"><div class="wrap stats">
  <div class="stat"><div class="n" data-c="40" data-suf="+">0</div><div class="l">Languages</div></div>
  <div class="stat"><div class="n" data-c="15" data-suf="+">0</div><div class="l">AI providers</div></div>
  <div class="stat"><div class="n" data-c="8">0</div><div class="l">Mascots</div></div>
  <div class="stat"><div class="n" data-c="24" data-suf="/7">0</div><div class="l">Support &amp; sales</div></div>
</div></section>

<!-- TRUST -->
<div class="trust"><div class="wrap">
  <?php
  $pills = ['WooCommerce ready','Multilingual','Works with any AI key','Privacy-first','Fully customizable','Self-improving AI'];
  $check = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>';
  foreach ($pills as $p) echo '<span class="pill">'.$check.' '.$p.'</span>';
  ?>
</div></div>

<!-- FEATURES (alternating) -->
<section class="section" id="features"><div class="wrap">
  <span class="eyebrow" style="display:block;text-align:center;margin:0 auto">Features</span>
  <h2>Everything your website needs in one bot</h2>
  <p class="sub">Saathi blends agentic support, in-chat commerce, and deep site knowledge — wrapped in a premium, fully-customizable widget.</p>

  <div class="feat">
    <div class="feat-img"><img src="<?=$IMG['feature-commerce']?>" alt="Product showcase in chat"></div>
    <div class="feat-text">
      <h3>Sell inside the chat 🛍️</h3>
      <p>Saathi shows real product cards with image, rating and price — and lets customers <strong>Add to Cart</strong> or <strong>Buy Now</strong> without leaving the conversation.</p>
      <ul class="feat-list">
        <li><?=$check?> Live WooCommerce product showcase</li>
        <li><?=$check?> One-tap Add to Cart & instant checkout</li>
        <li><?=$check?> Recommends the right product from your catalog</li>
      </ul>
    </div>
  </div>

  <div class="feat rev">
    <div class="feat-text">
      <h3>Knows your site — not generic answers 🧠</h3>
      <p>A deep scan indexes only your <strong>published</strong> pages, posts and products, so every answer is grounded in your real content. No hallucinated info.</p>
      <ul class="feat-list">
        <li><?=$check?> Reads your live, current content</li>
        <li><?=$check?> Follow-up suggestions from your real pages</li>
        <li><?=$check?> Ignores drafts, trash & junk automatically</li>
      </ul>
    </div>
    <div class="feat-img"><img src="<?=$IMG['feature-knowledge']?>" alt="Knowledge scan"></div>
  </div>

  <div class="feat">
    <div class="feat-img"><img src="<?=$IMG['feature-multilingual']?>" alt="Multilingual chat"></div>
    <div class="feat-text">
      <h3>Speaks your visitor's language 🌍</h3>
      <p>English, Hindi, Hinglish, Gujarati and 40+ more — Saathi auto-replies in whatever language each visitor types.</p>
      <ul class="feat-list">
        <li><?=$check?> Automatic language matching</li>
        <li><?=$check?> Keeps brand & product names intact</li>
        <li><?=$check?> Works on any AI model — even free ones</li>
      </ul>
    </div>
  </div>

  <div class="feat rev">
    <div class="feat-text">
      <h3>Make it truly yours 🎨</h3>
      <p>Pick your brand colour and choose a friendly mascot — the whole widget re-themes instantly. Try it live in the demo bot (bottom-right) right now.</p>
      <ul class="feat-list">
        <li><?=$check?> Brand colour + 8 mascots</li>
        <li><?=$check?> Drag, resize & position the window</li>
        <li><?=$check?> Beautiful on mobile & desktop</li>
      </ul>
    </div>
    <div class="cust-card" id="custCard">
      <div class="cust-prev" id="custPrev" style="--pv:#6D5DFB">
        <img id="custPrevImg" src="<?=$IMG['mascot-1']?>" alt="">
        <div><div class="h">Saathi</div><div class="s">Online · your brand, your bot</div></div>
      </div>
      <div class="cust-row"><span class="lab">Colour</span><span id="custColors" style="display:flex;gap:8px;flex-wrap:wrap"></span></div>
      <div class="cust-row"><span class="lab">Mascot</span><span id="custMascots" style="display:flex;gap:8px;flex-wrap:wrap"></span></div>
      <button class="btn btn-primary" style="margin-top:6px" id="custOpen">See it in the live bot →</button>
    </div>
  </div>
</div></section>

<!-- SMALL CARDS -->
<section class="section" style="padding-top:0"><div class="wrap">
  <div class="cards">
    <?php
    $bolt='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2 3 14h9l-1 8 10-12h-9z"/></svg>';
    $chat='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.5 8.5 0 0 1-12.5 7.5L3 21l1.9-5.5A8.5 8.5 0 1 1 21 11.5z"/></svg>';
    $move='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M2 12h20M8 6l4-4 4 4M8 18l4 4 4-4"/></svg>';
    $phone='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg>';
    $spark='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2 2M16 16l2 2M18 6l-2 2M8 16l-2 2"/></svg>';
    $shield='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 4 5v6c0 5 3.5 8 8 11 4.5-3 8-6 8-11V5z"/></svg>';
    $cards=[
      [$chat,'Agentic support','Answers questions, guides visitors, and takes action — not just canned replies.'],
      [$spark,'Self-improving','Learns from chats and your content over time, so answers keep getting sharper.'],
      [$bolt,'Smart follow-ups','Suggests the next best question — always grounded in your real site.'],
      [$move,'Drag & resize','Visitors can move and resize the window; you control default position.'],
      [$phone,'Mobile-perfect','Sits cleanly above mobile nav bars, fully responsive, fast to load.'],
      [$shield,'Secure & private','Refuses sensitive data, respects privacy, and stays in your scope.'],
    ];
    foreach($cards as $c) echo '<div class="card"><div class="ico">'.$c[0].'</div><h4>'.$c[1].'</h4><p>'.$c[2].'</p></div>';
    ?>
  </div>
</div></section>

<!-- LIVE DEMO -->
<section class="section" style="padding-top:0"><div class="wrap">
  <div class="demo">
    <span class="eyebrow">See it live</span>
    <h2 style="margin-top:10px">Talk to Saathi right now</h2>
    <p>Open the bot in the bottom-right corner. Ask about features or pricing, type <em>“show me a product demo”</em>, and switch its colour & mascot — live.</p>
    <button class="btn btn-primary btn-lg" onclick="document.getElementById('sbFab')&&document.getElementById('sbFab').click()">Open the demo bot</button>
  </div>
</div></section>

<!-- PRICING -->
<section class="section" id="pricing"><div class="wrap">
  <span class="eyebrow alt" style="display:block;text-align:center;margin:0 auto">Pricing</span>
  <h2>Simple, honest pricing</h2>
  <p class="sub">Start free. Upgrade when you're ready. Lifetime means pay once — forever.</p>
  <div class="prices">
    <div class="price">
      <h4>Free</h4><div class="amt">₹0<span>/forever</span></div>
      <ul>
        <li><?=$check?> Core AI chat</li><li><?=$check?> 1 website</li>
        <li><?=$check?> Any AI provider key</li><li><?=$check?> Community support</li>
      </ul>
      <a class="btn btn-ghost" href="login.php">Start free</a>
    </div>
    <div class="price pop">
      <h4>Pro</h4><div class="amt">₹999<span>/month</span></div>
      <ul>
        <li><?=$check?> Everything in Free</li><li><?=$check?> WooCommerce product showcase</li>
        <li><?=$check?> Multilingual + deep knowledge scan</li><li><?=$check?> Colour & mascot customization</li>
        <li><?=$check?> Priority support</li>
      </ul>
      <a class="btn btn-primary" href="login.php">Get Pro</a>
    </div>
    <div class="price">
      <h4>Lifetime</h4><div class="amt">₹9,999<span>/once</span></div>
      <ul>
        <li><?=$check?> Everything in Pro</li><li><?=$check?> Unlimited duration</li>
        <li><?=$check?> All future updates</li><li><?=$check?> Best value</li>
      </ul>
      <a class="btn btn-ghost" href="login.php">Buy Lifetime</a>
    </div>
  </div>
</div></section>

<!-- FAQ -->
<section class="section" id="faq" style="padding-top:0"><div class="wrap">
  <h2>Frequently asked</h2>
  <div class="faq">
    <?php
    $faqs=[
      ['Do I need a paid AI account?','No — Saathi works with any AI provider, including free models. Add your key and you are ready.'],
      ['Will it answer from MY content?','Yes. Saathi deep-scans only your published pages, posts and products, so answers stay accurate and on-brand.'],
      ['Can it sell my products?','Absolutely. On WooCommerce it shows product cards with Add to Cart and Buy Now right inside the chat.'],
      ['Can I match my brand?','Pick any colour and mascot — the whole widget re-themes instantly. Try it in the demo bot now.'],
      ['Is it hard to set up?','Install, add your AI key, run the deep scan — live in about five minutes.'],
    ];
    foreach($faqs as $q) echo '<details class="qa"><summary>'.$q[0].' <span>+</span></summary><p>'.$q[1].'</p></details>';
    ?>
  </div>
</div></section>

<!-- CTA -->
<section class="wrap"><div class="cta-band">
  <h2>Ready to give your site a Saathi?</h2>
  <p>Join the websites turning visitors into happy customers — with the best AI chatbot in WordPress.</p>
  <a class="btn btn-ghost btn-lg" href="login.php">Get started free</a>
</div></section>

<!-- FOOTER -->
<footer><div class="wrap">
  <div>
    <a class="brand" href="#top"><img src="<?=$IMG['logo']?>" alt="" style="width:30px;height:30px"> Saathi</a>
    <p style="color:#a99fe0;font-size:14px;margin-top:12px;max-width:280px">The agentic AI chatbot that supports and sells for your website. A product by RAI.</p>
  </div>
  <div><h5>Product</h5><a href="#features">Features</a><a href="#pricing">Pricing</a><a href="#faq">FAQ</a><a href="login.php">Get started</a></div>
  <div><h5>Company</h5><a href="#">About</a><a href="#">Contact</a><a href="#">Blog</a></div>
  <div><h5>Legal</h5><a href="#">Privacy</a><a href="#">Terms</a><a href="#">Refund</a></div>
</div><div class="foot-bottom">© <?=$YEAR?> Saathi · a product by RAI. All rights reserved.</div></footer>

<!-- SAATHI DEMO BOT -->
<div id="sbot"></div>
<script>
window.SAATHI_IMG = <?php
  $m = [];
  for ($i = 1; $i <= 8; $i++) { $m['mascot-' . $i] = $IMG['mascot-' . $i]; }
  echo json_encode($m);
?>;
window.SAATHI_FRAMES = <?php echo json_encode(array_values($FR)); ?>;
</script>
<script src="assets/widget/saathi-embed.js" defer></script>
<script>
(function () {
  // Hero signature mascot — cycle emotion frames with a soft fade.
  var fr = window.SAATHI_FRAMES || [], i = 0, hm = document.getElementById('heroMascot');
  if (hm && fr.length > 1) { setInterval(function () { i = (i + 1) % fr.length; hm.style.opacity = .35; setTimeout(function(){ hm.src = fr[i]; hm.style.opacity = 1; }, 160); }, 2600); }

  // On-page customizer (colour + mascot) that drives the live bot too.
  var COLORS = ['#6D5DFB', '#2DB4FF', '#19C37D', '#FF6B5E', '#F0567A', '#7C3AED'];
  var MASC = [1, 3, 5, 6, 7, 8];
  var sel = { color: '#6D5DFB', mascot: 1 };
  var cc = document.getElementById('custColors'), cm = document.getElementById('custMascots'),
      prev = document.getElementById('custPrev'), prevImg = document.getElementById('custPrevImg');
  function apply() {
    if (prev) prev.style.setProperty('--pv', sel.color);
    if (prevImg && window.SAATHI_IMG) prevImg.src = window.SAATHI_IMG['mascot-' + sel.mascot];
    if (window.SaathiBot) { window.SaathiBot.setColor(sel.color); window.SaathiBot.setMascot(sel.mascot); }
  }
  function refresh() {
    if (cc) [].forEach.call(cc.children, function (d, k) { d.className = 'cust-dot' + (COLORS[k] === sel.color ? ' on' : ''); });
    if (cm) [].forEach.call(cm.children, function (d, k) { d.className = 'cust-mini' + (MASC[k] === sel.mascot ? ' on' : ''); });
  }
  if (cc) COLORS.forEach(function (c) { var d = document.createElement('span'); d.className = 'cust-dot' + (c === sel.color ? ' on' : ''); d.style.background = c; d.onclick = function () { sel.color = c; refresh(); apply(); }; cc.appendChild(d); });
  if (cm && window.SAATHI_IMG) MASC.forEach(function (n) { var im = document.createElement('img'); im.className = 'cust-mini' + (n === sel.mascot ? ' on' : ''); im.src = window.SAATHI_IMG['mascot-' + n]; im.onclick = function () { sel.mascot = n; refresh(); apply(); }; cm.appendChild(im); });
  var ob = document.getElementById('custOpen'); if (ob) ob.onclick = function () { apply(); if (window.SaathiBot) window.SaathiBot.open(); };
})();
</script>
<script>
(function(){
  // Provider slider — duplicate the list so the loop is seamless.
  var provs=['OpenAI','Anthropic Claude','Google Gemini','xAI Grok','DeepSeek','Mistral','OpenRouter','Groq','Together AI','Fireworks','Ollama','Cohere','Perplexity','LM Studio'];
  var mq=document.getElementById('provMarq');
  if(mq) mq.innerHTML=provs.concat(provs).map(function(p){return '<b>✦ '+p+'</b>';}).join('');
  // Hero speech rotator
  var tips=["Hi! I'm Saathi 👋","Ask me about products 🛍️","I answer from your site 📚","Tap a colour & mascot below!","Add to cart inside chat ✓"];
  var sp=document.getElementById('heroSpeech'),ti=0;
  if(sp) setInterval(function(){ti=(ti+1)%tips.length;sp.style.opacity=0;setTimeout(function(){sp.textContent=tips[ti];sp.style.transition='opacity .3s';sp.style.opacity=1;},150);},3200);
  // Sparkles around the mascot
  var ha=document.getElementById('heroArt');
  if(ha){for(var k=0;k<7;k++){var s=document.createElement('div');s.className='spark';s.style.left=(10+Math.random()*80)+'%';s.style.top=(10+Math.random()*80)+'%';s.style.animationDelay=(Math.random()*3)+'s';ha.appendChild(s);}}
  // Cursor parallax on the mascot
  if(ha){ha.addEventListener('mousemove',function(e){var r=ha.getBoundingClientRect();var x=(e.clientX-r.left-r.width/2)/24;var y=(e.clientY-r.top-r.height/2)/24;var hm=document.getElementById('heroMascot');if(hm)hm.style.transform='translate('+x+'px,'+y+'px)';});
  ha.addEventListener('mouseleave',function(){var hm=document.getElementById('heroMascot');if(hm)hm.style.transform='';});}
  // Stats count-up when scrolled into view
  function countup(st){var el=st.querySelector('.n');if(!el||el.dataset.done)return;el.dataset.done=1;var end=+el.dataset.c,suf=el.dataset.suf||'',t=0;var iv=setInterval(function(){t+=Math.ceil(end/30);if(t>=end){t=end;clearInterval(iv);}el.textContent=t+suf;},28);}
  var stats=[].slice.call(document.querySelectorAll('.stat'));
  if('IntersectionObserver' in window){var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){countup(e.target);io.unobserve(e.target);}});},{threshold:.4});stats.forEach(function(s){io.observe(s);});}
  else stats.forEach(countup);
})();
</script>
</body>
</html>
