<?php
require __DIR__ . '/includes/bootstrap.php';
$IMG = require __DIR__ . '/assets/images.php';
require __DIR__ . '/includes/layout.php';

$faqs = [
  ['Which AI providers are supported?', 'Saathi supports 15 providers: OpenAI, Anthropic Claude, Google Gemini, xAI Grok, DeepSeek, Mistral AI, Perplexity, Cohere, OpenRouter, Groq, Together AI, Fireworks AI, Ollama (local), LM Studio (local), and any custom OpenAI-compatible endpoint. You bring your own API key — Saathi never charges you per message.'],
  ['Does Saathi read my entire site?', 'Only published content is indexed — published pages, posts and WooCommerce products. Drafts, trashed content, private posts, password-protected pages and admin-only content are automatically excluded. You control exactly what the bot knows.'],
  ['Where do I get my license key?', 'Log in to your <a href="/dashboard">Saathi dashboard</a>, go to the License tab. Your key is displayed there and also emailed to you at checkout. Paste it in <code>Saathi AI → License</code> in WordPress to activate.'],
  ['Will Saathi slow my website?', 'No. The widget loads asynchronously after the page is fully interactive. Core Web Vitals (LCP, FID, CLS) are not affected. The script is ~18 KB gzipped and deferred.'],
  ['Can I use Saathi on multiple websites?', 'Each license key activates on one domain. For additional sites, purchase additional licenses from your dashboard. License keys are domain-locked and cannot be transferred without support.'],
  ['Does it work with WooCommerce?', 'Yes — WooCommerce product showcase, Add to Cart and Buy Now inside chat are available on the Pro and Max plans. Saathi reads your live product catalog, shows product cards with images and prices, and can send visitors directly to checkout.'],
  ['What happens if I run out of AI credits?', 'Saathi itself has no usage limits — you pay only your AI provider. If your API key runs out of credits, the bot will show a connection error. Simply top up your account with your provider and the bot resumes automatically.'],
  ['Is GDPR / privacy compliance handled?', 'Yes. Saathi supports a GDPR consent gate — the chat window can be configured to show a consent notice before starting a conversation. Guest conversations are identified by a privacy-safe cookie, never by IP address alone. No conversation data is shared with third parties.'],
  ['Can I have multiple personas?', 'Pro and Max plans support multiple AI personas — each with its own name, tone, system prompt and mascot. You can switch personas per page or let the AI build custom ones based on your content.'],
  ['Does the bot remember past conversations?', 'Yes. The memory feature (Pro/Max) stores key facts from past sessions — customer preferences, previous questions, product interests — and surfaces them in future conversations. Guests and logged-in users both get memory support.'],
];

$faqSchema = json_encode([
  '@context'=>'https://schema.org','@type'=>'FAQPage',
  'mainEntity'=>array_map(fn($f)=>['@type'=>'Question','name'=>strip_tags($f[0]),'acceptedAnswer'=>['@type'=>'Answer','text'=>strip_tags($f[1])]], $faqs),
], JSON_UNESCAPED_SLASHES);

page_head([
  'title' => 'Saathi Docs — Full Setup, Configuration & Troubleshooting Guide',
  'desc'  => 'Complete documentation for Saathi: install the plugin, connect an AI provider, scan your site, configure personas, enable WooCommerce selling, customize the widget, and fix common issues.',
  'slug'  => 'docs',
  'schema'=> $faqSchema,
  'extra_css' => '
    .doc-badge{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.04em;padding:2px 8px;border-radius:20px;vertical-align:middle;margin-left:6px}
    .doc-badge.pro{background:#ede9fe;color:#6d28d9}
    .doc-badge.max{background:#fef3c7;color:#b45309}
    .doc-badge.free{background:#d1fae5;color:#065f46}
    .step-list{counter-reset:step;margin:12px 0;padding:0;list-style:none}
    .step-list li{counter-increment:step;display:flex;gap:14px;padding:10px 0;border-bottom:1px solid var(--line)}
    .step-list li:last-child{border-bottom:none}
    .step-list li::before{content:counter(step);flex-shrink:0;width:28px;height:28px;border-radius:50%;background:var(--v);color:#fff;font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center;margin-top:1px}
    .step-list li div{flex:1}
    .step-list li strong{display:block;font-size:15px;margin-bottom:3px}
    .alert-box{border-radius:12px;padding:13px 16px;margin:14px 0;font-size:14.5px;line-height:1.6;display:flex;gap:10px;align-items:flex-start}
    .alert-box.tip{background:#f0fdf4;border:1px solid #86efac;color:#166534}
    .alert-box.warn{background:#fffbeb;border:1px solid #fcd34d;color:#92400e}
    .alert-box.info{background:#eff6ff;border:1px solid #93c5fd;color:#1e40af}
    .alert-box .icon{font-size:18px;line-height:1;flex-shrink:0;margin-top:1px}
    .docnav a.active{background:#f3f0ff;color:var(--v)}
    .req-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:12px 0}
    @media(max-width:600px){.req-grid{grid-template-columns:1fr}}
    .req-card{background:#f9f7ff;border:1px solid var(--line);border-radius:10px;padding:11px 14px;font-size:14px}
    .req-card strong{display:block;margin-bottom:3px;font-size:13px;color:var(--muted)}
    .provider-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin:14px 0}
    .prov-card{background:#f9f7ff;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font-size:13.5px;font-weight:600;display:flex;align-items:center;gap:8px}
    .prov-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
    .trouble-item{margin-bottom:18px;padding-bottom:18px;border-bottom:1px solid var(--line)}
    .trouble-item:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0}
    .trouble-item h4{font-size:15.5px;margin:0 0 6px;display:flex;align-items:center;gap:7px}
  ',
]);
site_nav('docs');
?>
<section class="phero"><div class="wrap">
  <span class="eyebrow">Docs &amp; Help</span>
  <h1>Complete Saathi documentation</h1>
  <p class="lead">Everything from first install to advanced WooCommerce selling, AI personas, memory and multilingual setup — with troubleshooting for every common issue.</p>
  <div class="cta-row">
    <a class="btn btn-primary btn-lg" href="/login">Get started free</a>
    <a class="btn btn-ghost btn-lg" href="/contact">Talk to support</a>
  </div>
</div></section>

<section class="section"><div class="wrap"><div class="docwrap">

  <!-- SIDEBAR NAV -->
  <aside class="docnav" id="docSidebar">
    <a href="#requirements">System requirements</a>
    <a href="#install">1 · Install &amp; activate</a>
    <a href="#ai">2 · Connect an AI provider</a>
    <a href="#models">3 · Choose a model</a>
    <a href="#scan">4 · Scan your website</a>
    <a href="#persona">5 · Personas &amp; tone</a>
    <a href="#look">6 · Appearance &amp; mascot</a>
    <a href="#place">7 · Placement &amp; visibility</a>
    <a href="#woocommerce">8 · WooCommerce selling</a>
    <a href="#memory">9 · Memory &amp; context</a>
    <a href="#multilingual">10 · Multilingual setup</a>
    <a href="#license">11 · License activation</a>
    <a href="#update">12 · Updating the plugin</a>
    <a href="#privacy">13 · Privacy &amp; GDPR</a>
    <a href="#trouble">Troubleshooting</a>
    <a href="#faq">FAQ</a>
  </aside>

  <!-- CONTENT -->
  <div>

    <!-- SYSTEM REQUIREMENTS -->
    <div class="docblock" id="requirements">
      <h3>System requirements</h3>
      <p>Before installing, make sure your environment meets the following minimums:</p>
      <div class="req-grid">
        <div class="req-card"><strong>WordPress</strong>6.0 or higher</div>
        <div class="req-card"><strong>PHP</strong>8.0 or higher (8.2+ recommended)</div>
        <div class="req-card"><strong>MySQL / MariaDB</strong>5.7 / 10.3 or higher</div>
        <div class="req-card"><strong>HTTPS</strong>Required — SSL certificate must be active</div>
        <div class="req-card"><strong>WooCommerce (optional)</strong>6.0+ for in-chat selling features</div>
        <div class="req-card"><strong>Server outbound HTTP</strong>cURL must be enabled (most hosts: yes)</div>
      </div>
      <div class="alert-box tip"><span class="icon">💡</span><span>Saathi works on any PHP hosting — shared, VPS, managed WordPress (Kinsta, WP Engine, Cloudways, etc.). No Node.js, Redis or background worker required.</span></div>
    </div>

    <!-- INSTALL -->
    <div class="docblock" id="install">
      <h3>1 · Install &amp; activate</h3>
      <p>There are two ways to install Saathi — upload and auto-install:</p>
      <p><strong>Method A — Upload (recommended)</strong></p>
      <ol class="step-list">
        <li><div><strong>Download the plugin ZIP</strong>Log in to your <a href="/dashboard">Saathi dashboard</a>, go to <strong>Downloads</strong> and click <strong>Download plugin ZIP</strong>. You need an active license (Free plan works).</div></li>
        <li><div><strong>Upload to WordPress</strong>In your WordPress admin, go to <code>Plugins → Add New → Upload Plugin</code>, click <strong>Choose file</strong>, select the <code>sathi-agentic-ai.zip</code> you downloaded, and click <strong>Install Now</strong>.</div></li>
        <li><div><strong>Activate</strong>Click <strong>Activate Plugin</strong>. A new <strong>Saathi AI</strong> menu appears in the left sidebar of your WordPress dashboard.</div></li>
        <li><div><strong>Run the setup wizard</strong>Saathi will prompt you to start the setup wizard. This walks you through AI provider, scan, and appearance in under 5 minutes.</div></li>
      </ol>
      <p><strong>Method B — FTP / cPanel</strong></p>
      <p>Unzip <code>sathi-agentic-ai.zip</code> and upload the <code>sathi-agentic-ai/</code> folder to <code>/wp-content/plugins/</code> via FTP or cPanel File Manager. Then activate from <code>Plugins → Installed Plugins</code>.</p>
      <div class="alert-box warn"><span class="icon">⚠️</span><span>Do not install Saathi on a WordPress Multisite (network) as the main network-activated plugin — activate it individually on each site instead.</span></div>
    </div>

    <!-- AI PROVIDER -->
    <div class="docblock" id="ai">
      <h3>2 · Connect an AI provider</h3>
      <p>Saathi works with <strong>15 AI providers</strong>. You bring your own API key — Saathi never charges per message and doesn't proxy your requests through its own servers.</p>
      <div class="provider-grid">
        <div class="prov-card"><span class="prov-dot" style="background:#10a37f"></span>OpenAI</div>
        <div class="prov-card"><span class="prov-dot" style="background:#d97757"></span>Anthropic</div>
        <div class="prov-card"><span class="prov-dot" style="background:#4285f4"></span>Google Gemini</div>
        <div class="prov-card"><span class="prov-dot" style="background:#141414"></span>xAI Grok</div>
        <div class="prov-card"><span class="prov-dot" style="background:#4d6bfe"></span>DeepSeek</div>
        <div class="prov-card"><span class="prov-dot" style="background:#fa520f"></span>Mistral AI</div>
        <div class="prov-card"><span class="prov-dot" style="background:#20808d"></span>Perplexity</div>
        <div class="prov-card"><span class="prov-dot" style="background:#39594d"></span>Cohere</div>
        <div class="prov-card"><span class="prov-dot" style="background:#6566f1"></span>OpenRouter</div>
        <div class="prov-card"><span class="prov-dot" style="background:#f55036"></span>Groq</div>
        <div class="prov-card"><span class="prov-dot" style="background:#0f6fff"></span>Together AI</div>
        <div class="prov-card"><span class="prov-dot" style="background:#5019c5"></span>Fireworks AI</div>
        <div class="prov-card"><span class="prov-dot" style="background:#64748b"></span>Ollama (local)</div>
        <div class="prov-card"><span class="prov-dot" style="background:#7b8da9"></span>LM Studio</div>
        <div class="prov-card"><span class="prov-dot" style="background:#c9a84c"></span>Custom endpoint</div>
      </div>
      <p><strong>How to connect:</strong></p>
      <ol class="step-list">
        <li><div><strong>Get an API key</strong>Sign up with your chosen provider (e.g. <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com</a> for OpenAI, <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai</a> for OpenRouter). Free tiers are available on most providers.</div></li>
        <li><div><strong>Open AI Providers in WordPress</strong>Go to <code>Saathi AI → AI Providers</code> in your WordPress dashboard. Select your provider from the dropdown.</div></li>
        <li><div><strong>Paste the API key</strong>Paste the key in the field provided. For Ollama (local) or LM Studio, enter the base URL instead (e.g. <code>http://localhost:11434/v1</code>).</div></li>
        <li><div><strong>Test the connection</strong>Click <strong>Test connection</strong>. You'll see a green ✓ if the key works and the model responds.</div></li>
        <li><div><strong>Save</strong>Click <strong>Save provider</strong>. Saathi will now use this key for all chat conversations.</div></li>
      </ol>
      <div class="alert-box info"><span class="icon">ℹ️</span><span>You can configure a different provider for <strong>chat</strong> (everyday replies), <strong>embeddings</strong> (knowledge search) and <strong>heavy tasks</strong> (personas, deep scan). This lets you use a cheap/fast model for chat and a more capable one for reasoning.</span></div>
    </div>

    <!-- MODEL SELECTION -->
    <div class="docblock" id="models">
      <h3>3 · Choose the right model</h3>
      <p>Different models have different strengths. Here's a quick guide for Saathi use cases:</p>
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        <thead><tr style="background:#f3f0ff"><th style="padding:9px 12px;text-align:left;border-radius:8px 0 0 0">Model</th><th style="padding:9px 12px;text-align:left">Best for</th><th style="padding:9px 12px;text-align:left;border-radius:0 8px 0 0">Cost</th></tr></thead>
        <tbody>
          <tr style="border-top:1px solid var(--line)"><td style="padding:9px 12px"><code>gpt-4o-mini</code></td><td style="padding:9px 12px">Fast everyday chat — great default</td><td style="padding:9px 12px">Very low</td></tr>
          <tr style="border-top:1px solid var(--line)"><td style="padding:9px 12px"><code>deepseek-chat</code></td><td style="padding:9px 12px">Budget-friendly, high quality</td><td style="padding:9px 12px">Very low</td></tr>
          <tr style="border-top:1px solid var(--line)"><td style="padding:9px 12px"><code>llama-3.3-70b</code> (Groq)</td><td style="padding:9px 12px">Free, ultra-fast inference</td><td style="padding:9px 12px">Free tier</td></tr>
          <tr style="border-top:1px solid var(--line)"><td style="padding:9px 12px"><code>claude-3-5-sonnet</code></td><td style="padding:9px 12px">Complex personas, long context</td><td style="padding:9px 12px">Medium</td></tr>
          <tr style="border-top:1px solid var(--line)"><td style="padding:9px 12px"><code>gemini-1.5-flash</code></td><td style="padding:9px 12px">Good multilingual + fast</td><td style="padding:9px 12px">Low</td></tr>
          <tr style="border-top:1px solid var(--line)"><td style="padding:9px 12px"><code>ollama/llama3.2</code></td><td style="padding:9px 12px">100% local, private, no API cost</td><td style="padding:9px 12px">Free (self-hosted)</td></tr>
        </tbody>
      </table>
      <div class="alert-box tip"><span class="icon">💡</span><span><strong>Recommended starting point:</strong> Use <code>gpt-4o-mini</code> (OpenAI) or <code>deepseek-chat</code> (DeepSeek) for chat. Both give excellent quality at very low cost — often under ₹1 per 100 conversations.</span></div>
    </div>

    <!-- SCAN -->
    <div class="docblock" id="scan">
      <h3>4 · Scan your website</h3>
      <p>The website scan is what makes Saathi answer from <em>your</em> content instead of generic AI replies. It indexes all published pages, posts and WooCommerce products.</p>
      <ol class="step-list">
        <li><div><strong>Go to Knowledge</strong>Navigate to <code>Saathi AI → Knowledge</code> in your WordPress dashboard.</div></li>
        <li><div><strong>Click "Scan website"</strong>Saathi crawls all published posts, pages and products. Progress is shown in real time. A typical site (50–200 pages) takes 30–90 seconds.</div></li>
        <li><div><strong>Review indexed content</strong>After the scan, you'll see a list of indexed documents with word counts. You can remove individual pages from the knowledge base if needed.</div></li>
        <li><div><strong>Re-scan after content changes</strong>Whenever you publish new pages, update product descriptions or change important content, run a new scan so the bot stays accurate.</div></li>
      </ol>
      <div class="alert-box info"><span class="icon">ℹ️</span><span><strong>What gets scanned:</strong> Published pages, published posts, published WooCommerce products (name, description, price, categories). <strong>What is excluded:</strong> Drafts, trash, private posts, password-protected content, admin pages, orders, customer data.</span></div>
      <div class="alert-box tip"><span class="icon">💡</span><span><strong>Pro tip:</strong> Add a detailed "About Us" page, an FAQ page, and rich product descriptions before scanning. The more specific your content, the more accurate Saathi's answers will be.</span></div>
    </div>

    <!-- PERSONAS -->
    <div class="docblock" id="persona">
      <h3>5 · Personas &amp; bot tone <span class="doc-badge pro">Pro</span> <span class="doc-badge max">Max</span></h3>
      <p>Personas let you define <em>who your bot is</em> — name, personality, tone, and scope of knowledge. You can create multiple personas for different pages or contexts.</p>
      <ol class="step-list">
        <li><div><strong>Go to Personas</strong>Navigate to <code>Saathi AI → Personas</code>.</div></li>
        <li><div><strong>Create a persona</strong>Give it a name (e.g. "Riya — Support Bot"), a short description, and write the system prompt. Example: <em>"You are Riya, a friendly support agent for Acme Store. Answer only from the provided knowledge base. If unsure, ask the customer to clarify."</em></div></li>
        <li><div><strong>Set the tone</strong>Choose from presets (Friendly, Professional, Formal, Energetic) or write a custom tone instruction in the system prompt.</div></li>
        <li><div><strong>Assign a mascot</strong>Pick one of the 8 mascots to go with this persona — each persona can have its own look.</div></li>
        <li><div><strong>AI-built persona</strong> <span class="doc-badge max">Max</span> Click <strong>Generate with AI</strong> — describe your brand in a sentence and Saathi writes the full system prompt for you based on your scanned content.</div></li>
      </ol>
      <div class="alert-box info"><span class="icon">ℹ️</span><span>The Free plan comes with one default persona. Pro and Max support unlimited custom personas, and Max adds AI-assisted persona generation.</span></div>
    </div>

    <!-- APPEARANCE -->
    <div class="docblock" id="look">
      <h3>6 · Appearance &amp; mascot</h3>
      <p>Saathi's widget is fully themeable. Customize it to match your brand — visitors can also change the look themselves.</p>
      <ol class="step-list">
        <li><div><strong>Open Appearance</strong>Go to <code>Saathi AI → Appearance</code>.</div></li>
        <li><div><strong>Choose your brand color</strong>Pick from the palette or enter a hex code. The button, message bubbles and accents all update instantly.</div></li>
        <li><div><strong>Select a mascot</strong>8 friendly mascots are included. Each has unique emotion states that animate during conversation.</div></li>
        <li><div><strong>Set the welcome message</strong>Write the greeting the bot shows when first opened. Example: <em>"Hi there! 👋 I'm Riya. Ask me anything about our products or your order!"</em></div></li>
        <li><div><strong>Add suggested questions</strong>Pre-fill 3–5 question chips that appear in the chat — great for guiding visitors. Example: "What are your shipping options?", "Show me bestsellers".</div></li>
        <li><div><strong>Preview &amp; save</strong>The live preview on the right updates in real time. Click <strong>Save appearance</strong> when done.</div></li>
      </ol>
    </div>

    <!-- PLACEMENT -->
    <div class="docblock" id="place">
      <h3>7 · Placement &amp; visibility</h3>
      <p>Control exactly where the Saathi launcher appears and on which pages it's shown or hidden.</p>
      <ol class="step-list">
        <li><div><strong>Corner position</strong>Choose bottom-right (default), bottom-left, top-right or top-left. On mobile, an automatic offset keeps it clear of the browser navigation bar.</div></li>
        <li><div><strong>Show / hide on specific pages</strong>Under <strong>Visibility rules</strong>, select "Show on all pages" or add URL patterns to show/hide the bot only on specific pages. Example: hide on <code>/checkout</code>, show on <code>/shop</code>.</div></li>
        <li><div><strong>Auto-open delay</strong>Optionally set the bot to open automatically after X seconds on the page — useful for high-intent pages like pricing.</div></li>
        <li><div><strong>Mobile settings</strong>Toggle mobile on/off separately from desktop. You can also set a smaller launcher size for mobile.</div></li>
      </ol>
      <div class="alert-box tip"><span class="icon">💡</span><span>Visitors can drag the chat window anywhere on screen and resize it. Your placement settings define the default, not the forced position.</span></div>
    </div>

    <!-- WOOCOMMERCE -->
    <div class="docblock" id="woocommerce">
      <h3>8 · WooCommerce selling <span class="doc-badge max">Max</span></h3>
      <p>Saathi can showcase your WooCommerce products inside the chat — with images, prices, ratings — and let customers Add to Cart or go straight to checkout without leaving the conversation.</p>
      <ol class="step-list">
        <li><div><strong>Ensure WooCommerce is active</strong>WooCommerce 6.0+ must be installed and active on the same WordPress site.</div></li>
        <li><div><strong>Enable Commerce mode</strong>Go to <code>Saathi AI → Commerce</code> and toggle <strong>Enable WooCommerce selling</strong> on.</div></li>
        <li><div><strong>Run a product scan</strong>Under <code>Saathi AI → Knowledge → WooCommerce</code>, click <strong>Sync products</strong>. This imports your live catalog — product names, descriptions, prices, images and categories.</div></li>
        <li><div><strong>Set product recommendations</strong>Choose whether Saathi recommends by category match, price range, or bestsellers first. You can also write a recommendation rule in natural language.</div></li>
        <li><div><strong>Test in chat</strong>Open the chat widget and type <em>"show me your bestsellers"</em> or <em>"I need a gift under ₹1000"</em> — product cards appear inside the conversation.</div></li>
      </ol>
      <div class="alert-box info"><span class="icon">ℹ️</span><span>The <strong>Add to Cart</strong> button in chat uses the standard WooCommerce cart session — the item appears in the regular cart and checkout flow. No custom checkout code required.</span></div>
    </div>

    <!-- MEMORY -->
    <div class="docblock" id="memory">
      <h3>9 · Memory &amp; conversation context <span class="doc-badge pro">Pro</span> <span class="doc-badge max">Max</span></h3>
      <p>Saathi remembers key facts from past sessions — so returning visitors get a personalized experience, not a blank slate.</p>
      <ul style="color:var(--ink2);font-size:15px;line-height:1.75;margin:10px 0 10px 18px">
        <li>On <strong>Pro/Max</strong>, memory is stored per user (logged-in) or per guest cookie (visitors). It persists across sessions.</li>
        <li>The bot remembers: customer name (if given), product interests, language preference, previous questions and resolved issues.</li>
        <li>Memory is surfaced in the system prompt automatically — you don't need to configure anything beyond enabling the feature.</li>
        <li>To clear a user's memory: go to <code>Saathi AI → Memory</code> and delete entries by user or guest ID.</li>
        <li>Memory does not share between different visitors — each user/guest has an isolated memory store.</li>
      </ul>
      <div class="alert-box tip"><span class="icon">💡</span><span><strong>GDPR note:</strong> If a user requests data deletion under GDPR, use the Saathi dashboard <em>or</em> WordPress admin <code>Saathi AI → Memory → Erase user data</code> to permanently remove their memory records.</span></div>
    </div>

    <!-- MULTILINGUAL -->
    <div class="docblock" id="multilingual">
      <h3>10 · Multilingual setup</h3>
      <p>Saathi auto-detects the visitor's language from their message and replies in the same language — no plugin configuration needed. It supports 40+ languages including Hindi, Hinglish, Gujarati, Tamil, Bengali, Marathi, and all major European and Asian languages.</p>
      <p><strong>How it works:</strong></p>
      <ul style="color:var(--ink2);font-size:15px;line-height:1.75;margin:10px 0 10px 18px">
        <li>The AI model detects language from the first user message.</li>
        <li>Replies are in the same language, keeping your brand name, product names and prices unchanged.</li>
        <li>If a visitor switches language mid-conversation, the bot follows automatically.</li>
        <li>For code-mixed languages (Hinglish, Spanglish etc.), Saathi matches the user's own mix.</li>
      </ul>
      <div class="alert-box info"><span class="icon">ℹ️</span><span><strong>Language quality depends on your AI model.</strong> OpenAI GPT-4o, Claude and Gemini have the best multilingual quality. For Hindi and South Asian languages, <code>gpt-4o-mini</code> or <code>gemini-1.5-flash</code> work very well at low cost.</span></div>
    </div>

    <!-- LICENSE -->
    <div class="docblock" id="license">
      <h3>11 · License activation</h3>
      <ol class="step-list">
        <li><div><strong>Choose a plan</strong>Go to your <a href="/pricing">pricing page</a> and choose Free, Pro or Max. Free gives you a working chatbot with no time limit.</div></li>
        <li><div><strong>Copy your license key</strong>After checkout, log in to your <a href="/dashboard">Saathi dashboard</a>. Your license key is shown on the License tab and in your confirmation email. It looks like: <code>SATHI-XXXX-XXXX-XXXX-XXXX</code>.</div></li>
        <li><div><strong>Activate in WordPress</strong>In your WordPress admin, go to <code>Saathi AI → License</code>. Paste your key and click <strong>Activate</strong>. The plugin connects to the license server and confirms activation within a few seconds.</div></li>
        <li><div><strong>Check activation status</strong>A green ✓ and your plan name confirm successful activation. You'll see plan features unlock immediately.</div></li>
      </ol>
      <div class="alert-box warn"><span class="icon">⚠️</span><span>Each license key works on <strong>one domain</strong>. Activating on a new domain deactivates the previous one (unless you have a multi-site plan). To transfer a license, deactivate from the old domain first: <code>Saathi AI → License → Deactivate</code>.</span></div>
    </div>

    <!-- UPDATING -->
    <div class="docblock" id="update">
      <h3>12 · Updating the plugin</h3>
      <p>When a new version of Saathi is released, you'll see an update notice in your WordPress admin under <code>Plugins → Installed Plugins</code>. Updates are safe to apply — your settings, personas, knowledge base and conversation history are never affected by an update.</p>
      <ol class="step-list">
        <li><div><strong>Back up first (optional but recommended)</strong>Use a backup plugin (Updraft Plus, All-in-One WP Migration) before major version updates.</div></li>
        <li><div><strong>Apply the update</strong>Click <strong>Update now</strong> next to Saathi AI in the Plugins list. The plugin downloads and replaces the old files automatically.</div></li>
        <li><div><strong>Check settings</strong>After updating, visit <code>Saathi AI → AI Providers</code> and confirm your key is still connected. Run a quick test conversation on the frontend.</div></li>
      </ol>
      <div class="alert-box tip"><span class="icon">💡</span><span>Always update on a staging site first for major version jumps (e.g. 1.x → 2.x). For minor updates (e.g. 1.2 → 1.3), updating directly on production is safe.</span></div>
    </div>

    <!-- PRIVACY -->
    <div class="docblock" id="privacy">
      <h3>13 · Privacy &amp; GDPR</h3>
      <p>Saathi is built with privacy by default:</p>
      <ul style="color:var(--ink2);font-size:15px;line-height:1.75;margin:10px 0 10px 18px">
        <li><strong>No data sold or shared:</strong> Conversation data stays between your server, your visitor's browser, and your AI provider (which has its own privacy policy).</li>
        <li><strong>Guest tracking:</strong> Visitors are identified by a secure, httpOnly cookie — never by IP address alone.</li>
        <li><strong>GDPR consent gate:</strong> Enable the consent notice under <code>Saathi AI → Privacy</code>. The chat won't start until the visitor accepts.</li>
        <li><strong>Data deletion:</strong> Use <code>Saathi AI → Memory → Erase user data</code> to delete all stored memory for a user. Works with WordPress's built-in Personal Data Eraser tool too.</li>
        <li><strong>Conversation logs:</strong> Stored in your own database only. You can configure auto-deletion of logs older than N days under <code>Saathi AI → Privacy → Log retention</code>.</li>
        <li><strong>No third-party tracking:</strong> The Saathi widget does not load any third-party analytics, ad scripts or fingerprinting code.</li>
      </ul>
    </div>

    <!-- TROUBLESHOOTING -->
    <div class="docblock" id="trouble">
      <h3>Troubleshooting</h3>

      <div class="trouble-item">
        <h4>🤐 Bot not replying at all</h4>
        <p>Go to <code>Saathi AI → AI Providers</code> and click <strong>Test connection</strong>. Common causes:</p>
        <ul style="color:var(--ink2);font-size:14.5px;line-height:1.7;margin:6px 0 6px 18px">
          <li><strong>Invalid API key:</strong> Re-paste the key — no spaces before/after. Make sure it hasn't been revoked in your provider dashboard.</li>
          <li><strong>Out of credits:</strong> Check your provider billing page. Most providers send an email when credits run low.</li>
          <li><strong>Server blocked outbound:</strong> Some shared hosts block external HTTP. Check with your host that cURL is enabled and that <code>api.openai.com</code> (or your provider's domain) is reachable.</li>
          <li><strong>Wrong base URL:</strong> For custom/Ollama endpoints, double-check the URL format (e.g. <code>http://localhost:11434/v1</code>).</li>
        </ul>
      </div>

      <div class="trouble-item">
        <h4>🌐 Widget not visible on the frontend</h4>
        <ul style="color:var(--ink2);font-size:14.5px;line-height:1.7;margin:6px 0 6px 18px">
          <li>Check <code>Saathi AI → Appearance → Visibility rules</code> — ensure the page you're testing isn't in the hidden list.</li>
          <li>Check if a caching plugin (WP Rocket, W3 Total Cache) is serving a stale page. Clear all caches and hard-refresh.</li>
          <li>Check for JavaScript conflicts: open browser DevTools (F12) → Console tab → look for red errors. Temporarily deactivate other plugins to isolate the conflict.</li>
          <li>Check that the plugin is activated (not just installed) in <code>Plugins → Installed Plugins</code>.</li>
        </ul>
      </div>

      <div class="trouble-item">
        <h4>🤔 Answers are generic / bot doesn't know my products</h4>
        <ul style="color:var(--ink2);font-size:14.5px;line-height:1.7;margin:6px 0 6px 18px">
          <li>Run a fresh website scan: <code>Saathi AI → Knowledge → Scan website</code>. If you've published new content since the last scan, it won't be in the bot's knowledge base.</li>
          <li>Improve your content: add detailed product descriptions, an FAQ page, and a clear "About" page. Thin content gives thin answers.</li>
          <li>Check the Knowledge list to confirm your key pages were indexed (word count should be &gt; 50 words per page).</li>
          <li>For WooCommerce products, ensure they have meaningful descriptions — not just titles.</li>
        </ul>
      </div>

      <div class="trouble-item">
        <h4>🌍 Bot replies in English even when I write in Hindi</h4>
        <ul style="color:var(--ink2);font-size:14.5px;line-height:1.7;margin:6px 0 6px 18px">
          <li>This is usually a model limitation. Switch to <code>gpt-4o-mini</code>, <code>gpt-4o</code> or <code>gemini-1.5-flash</code> which have strong multilingual support.</li>
          <li>Check your persona's system prompt — if it says "Always reply in English", that overrides language detection. Remove the language constraint.</li>
        </ul>
      </div>

      <div class="trouble-item">
        <h4>💳 License shows "Invalid" after activation</h4>
        <ul style="color:var(--ink2);font-size:14.5px;line-height:1.7;margin:6px 0 6px 18px">
          <li>Confirm the key is copy-pasted exactly — no leading/trailing spaces, no missing characters.</li>
          <li>Check if the key is already activated on another domain. Go to your <a href="/dashboard">dashboard</a> → License → Activations to see active domains and deactivate if needed.</li>
          <li>Confirm your server can reach <code>saathi.neermedia.com</code> — some locked-down hosts block outbound HTTPS. Contact your host to whitelist the domain.</li>
        </ul>
      </div>

      <div class="trouble-item">
        <h4>🐌 Chat responses are slow</h4>
        <ul style="color:var(--ink2);font-size:14.5px;line-height:1.7;margin:6px 0 6px 18px">
          <li>Switch to a faster model: <code>llama-3.3-70b</code> on Groq is the fastest (free tier available), followed by <code>gpt-4o-mini</code>.</li>
          <li>If using Ollama/LM Studio locally, response speed depends on your machine's hardware.</li>
          <li>Check your server's PHP execution time — if it's below 60 seconds, streaming may time out on slow models.</li>
        </ul>
      </div>

      <div class="trouble-item">
        <h4>🛒 WooCommerce product cards not showing</h4>
        <ul style="color:var(--ink2);font-size:14.5px;line-height:1.7;margin:6px 0 6px 18px">
          <li>Confirm you are on the <strong>Max</strong> plan — WooCommerce selling requires Max.</li>
          <li>Re-run the product sync: <code>Saathi AI → Knowledge → WooCommerce → Sync products</code>.</li>
          <li>Ensure products are published and not out of stock (out-of-stock products are excluded by default).</li>
          <li>Try a more explicit query in the chat: <em>"Show me products in the [category name] category"</em>.</li>
        </ul>
      </div>

      <div class="alert-box info"><span class="icon">ℹ️</span><span>Still stuck? Open the <a href="/contact">contact page</a> and include your WordPress version, Saathi version (shown in <code>Plugins → Installed Plugins</code>), your AI provider, and a description of the issue. Screenshots help a lot.</span></div>
    </div>

    <!-- FAQ -->
    <div class="docblock" id="faq">
      <h3>FAQ</h3>
      <div class="faq">
        <?php foreach ($faqs as $q) echo '<details class="qa"><summary>'.$q[0].' <span>+</span></summary><p>'.$q[1].'</p></details>'; ?>
      </div>
    </div>

  </div><!-- /content -->
</div></div></section>

<section class="wrap"><div class="cta-band">
  <h2>Need a hand getting set up?</h2>
  <p>Our team typically replies within a few hours. Or try the live bot right now — it's powered by Saathi itself.</p>
  <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:8px">
    <a class="btn btn-ghost btn-lg" href="/contact">Contact support</a>
    <a class="btn btn-primary btn-lg" href="/?demo=1">Try the live demo</a>
  </div>
</div></section>

<script>
// Highlight active doc nav item on scroll
(function(){
  var links = document.querySelectorAll('.docnav a');
  var sections = [];
  links.forEach(function(a){ var id = a.getAttribute('href').replace('#',''); var el = document.getElementById(id); if(el) sections.push({el:el,a:a}); });
  function update(){
    var y = window.scrollY + 120;
    var active = null;
    sections.forEach(function(s){ if(s.el.offsetTop <= y) active = s; });
    links.forEach(function(a){ a.classList.remove('active'); });
    if(active) active.a.classList.add('active');
  }
  window.addEventListener('scroll', update, {passive:true});
  update();
})();
</script>
<?php site_footer(); page_foot();
