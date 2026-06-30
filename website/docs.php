<?php
require __DIR__ . '/includes/bootstrap.php';
$IMG = require __DIR__ . '/assets/images.php';
require __DIR__ . '/includes/layout.php';

$faqs = [
  ['Which AI providers are supported?', 'OpenAI, Anthropic Claude, Google Gemini, xAI Grok, DeepSeek, Mistral, OpenRouter, Groq, Ollama and more — 15 in total, including free models.'],
  ['Does Saathi read my whole site?', 'It indexes only your published pages, posts and products. Drafts, trashed and private content are skipped.'],
  ['Where do I get a license key?', 'Choose a plan in your dashboard. Your key appears there and is shown when you check out.'],
  ['Will it slow my site?', 'No. The widget is lightweight and loads asynchronously, so your pages stay fast.'],
];
$faqSchema = json_encode([
  '@context'=>'https://schema.org','@type'=>'FAQPage',
  'mainEntity'=>array_map(fn($f)=>['@type'=>'Question','name'=>strip_tags($f[0]),'acceptedAnswer'=>['@type'=>'Answer','text'=>strip_tags($f[1])]], $faqs),
], JSON_UNESCAPED_SLASHES);

page_head([
  'title' => 'Saathi Docs — Install, Setup & License Help',
  'desc'  => 'Step-by-step help for Saathi: install the plugin, connect an AI provider, scan your website, customize the widget, and activate your license.',
  'slug' => 'docs',
  'schema'=> $faqSchema,
]);
site_nav('docs');
?>
<section class="phero"><div class="wrap">
  <span class="eyebrow">Docs &amp; Help</span>
  <h1>Get Saathi live in 5 minutes</h1>
  <p class="lead">Everything you need to install, connect, customize and activate Saathi on your WordPress site.</p>
</div></section>

<section class="section"><div class="wrap"><div class="docwrap">
  <aside class="docnav">
    <a href="#install">1 · Install the plugin</a>
    <a href="#ai">2 · Connect an AI provider</a>
    <a href="#scan">3 · Scan your website</a>
    <a href="#look">4 · Appearance &amp; mascot</a>
    <a href="#place">5 · Placement rules</a>
    <a href="#license">6 · Activate your license</a>
    <a href="#trouble">Troubleshooting</a>
    <a href="#faq">FAQ</a>
  </aside>
  <div>
    <div class="docblock" id="install"><h3>1 · Install the plugin</h3>
      <p>In WordPress, go to <code>Plugins → Add New → Upload Plugin</code>, choose the Saathi <code>.zip</code>, click <strong>Install Now</strong>, then <strong>Activate</strong>. A new <strong>Saathi AI</strong> menu appears in your dashboard.</p></div>
    <div class="docblock" id="ai"><h3>2 · Connect an AI provider</h3>
      <p>Open <code>Saathi AI → AI Providers</code>, paste an API key from any of 15 providers (OpenAI, Claude, Gemini, OpenRouter, Groq, Ollama and more), click <strong>Test connection</strong>, then <strong>Save</strong>. You can pick a cheaper or free model for everyday replies.</p></div>
    <div class="docblock" id="scan"><h3>3 · Scan your website</h3>
      <p>Go to <code>Saathi AI → Knowledge</code> and click <strong>Scan website</strong>. Saathi indexes your <strong>published</strong> pages, posts and products so answers come from your real content. Re-scan anytime you publish new content.</p></div>
    <div class="docblock" id="look"><h3>4 · Appearance &amp; mascot</h3>
      <p>Under <code>Saathi AI → Appearance</code>, pick your brand colour and one of 8 mascots. The whole widget re-themes instantly. Set the welcome message and suggested questions here too.</p></div>
    <div class="docblock" id="place"><h3>5 · Placement rules</h3>
      <p>Choose where the launcher appears (corner, offset above mobile nav bars) and on which pages it shows or hides. Visitors can drag and resize the window; you set the defaults.</p></div>
    <div class="docblock" id="license"><h3>6 · Activate your license</h3>
      <p>Copy your license key from your <a href="/dashboard">Saathi dashboard</a>, then paste it in <code>Saathi AI → License</code> and click <strong>Activate</strong>. One key activates the number of domains your plan allows.</p></div>
    <div class="docblock" id="trouble"><h3>Troubleshooting</h3>
      <p><strong>Bot not replying?</strong> Re-check your AI key in AI Providers and click Test connection. <strong>Answers seem generic?</strong> Run the website scan again. <strong>Widget not visible?</strong> Check Placement rules and that it isn't hidden on that page. Still stuck? <a href="/contact">Contact us</a>.</p></div>
    <div class="docblock" id="faq"><h3>FAQ</h3>
      <?php foreach ($faqs as $q): ?><p><strong><?=$q[0]?></strong><br><?=$q[1]?></p><?php endforeach; ?>
    </div>
  </div>
</div></div></section>

<section class="wrap"><div class="cta-band">
  <h2>Need a hand?</h2>
  <p>Our team is happy to help you get set up. Reach out anytime.</p>
  <a class="btn btn-ghost btn-lg" href="/contact">Contact support</a>
</div></section>
<?php site_footer(); page_foot();
