<?php
/** Demo data + canned answers for the Saathi landing bot. */

function demo_products(): array {
    static $img = null;
    if ($img === null) { $img = @include __DIR__ . '/../assets/images.php'; if (!is_array($img)) $img = []; }
    return [
        ['name' => 'Aurora Smartwatch',        'image' => $img['product-watch'] ?? '',      'price' => '₹4,999', 'was' => '₹6,499', 'rating' => 4.5, 'reviews' => '1.2k'],
        ['name' => 'Pulse Wireless Headphones', 'image' => $img['product-headphones'] ?? '', 'price' => '₹2,799', 'was' => '₹3,999', 'rating' => 4.6, 'reviews' => '860'],
        ['name' => 'Stride Running Sneakers',   'image' => $img['product-sneaker'] ?? '',    'price' => '₹3,499', 'was' => '₹4,499', 'rating' => 4.4, 'reviews' => '2.1k'],
    ];
}

function saathi_system_prompt(): string {
    return "You are Saathi, the friendly AI assistant on the marketing website for the \"Saathi Agentic AI\" WordPress chatbot plugin. "
        . "Your goal: help visitors understand Saathi and show why it is the best WordPress chatbot — by design, low cost, features, simplicity, premium feel, and a self-improving AI. "
        . "Keep replies short, warm and skimmable: a one-line intro, then bold-label bullet points when useful. "
        . "Reply in the SAME language and script the visitor used (English, Hindi, Hinglish, Gujarati, etc.). "
        . "Features you can mention: agentic AI support; WooCommerce product showcase with Add to Cart & Buy Now inside chat; multilingual replies; deep site-knowledge scan of only published content; follow-up question suggestions grounded in the site; persona generator; full colour & mascot customization; drag/resize widget; mobile-friendly; premium reply formatting; license-key system; and it keeps improving over time. "
        . "Pricing (demo): Free starter; Pro Rs 999/month; Lifetime Rs 9,999 one-time. "
        . "This website has no real products; if asked about products or shopping, say you'll show a DEMO product showcase so they can see how product cards look in chat. "
        . "Never invent facts beyond Saathi. Never output <think> or reasoning tags — give only the final answer.";
}

function wants_products(string $m): bool {
    return (bool) preg_match('/product|showcase|demo|shop|buy|cart|item|store|catalog|bechte|dikha|saman/i', $m);
}

/** Offline fallback — varied, distinct answers per question type. */
function canned_reply(string $m): array {
    $t = mb_strtolower(trim($m));
    $fu = function ($q, $o) { return ['question' => $q, 'options' => $o]; };

    // Greeting
    if (preg_match('/^(hi|hello|hey|hii|namaste|namaskar|hola|yo|salaam)\b/u', $t)) {
        return ['reply' => "Hey there! 👋 I'm **Saathi**. I can explain what I do, show a live product demo, or talk pricing. What would you like?",
            'followups' => $fu('Pick one:', ['What can Saathi do?', 'Show a product demo', 'See pricing'])];
    }
    // Pricing
    if (preg_match('/price|cost|kitne|kitna|plan|pricing|paisa|charge|free|paid/u', $t)) {
        return ['reply' => "Simple, honest pricing:\n\n- **Free** — core AI chat, 1 site\n- **Pro — Rs 999/mo** — WooCommerce showcase, multilingual, full customization, deep knowledge scan\n- **Lifetime — Rs 9,999** — pay once, everything forever\n\nMost people start Free and upgrade when they see the results.",
            'followups' => $fu('Next?', ['Show a product demo', 'Is setup easy?', 'Why is it the best?'])];
    }
    // Products / showcase
    if (wants_products($m)) {
        return ['reply' => "We don't sell products on this site, but here's a **live demo** — this is exactly how your products appear inside Saathi chat (image, rating, price, Buy & Add):",
            'products' => demo_products(),
            'followups' => $fu('Want to see more?', ['How do customers buy?', 'Can I change the colours?', 'See pricing'])];
    }
    // Languages
    if (preg_match('/language|languages|hindi|gujarati|multilingual|bhasha|translate/u', $t)) {
        return ['reply' => "Saathi is **fully multilingual** 🌍\n\n- Replies in the **same language** each visitor types — English, Hindi, Hinglish, Gujarati, Marathi, Tamil and 40+ more\n- Switches language mid-chat automatically\n- Keeps your brand & product names intact\n\nNo extra setup — it just matches your visitor.",
            'followups' => $fu('Next?', ['Show a product demo', 'How does it learn my site?', 'See pricing'])];
    }
    // Security / privacy
    if (preg_match('/secur|privacy|safe|data|gdpr|hack|otp|password/u', $t)) {
        return ['reply' => "Security is built-in 🔒\n\n- **Refuses** to handle passwords, OTPs or card details\n- Stays **in scope** — only answers about your site\n- Privacy-first: you control what's stored\n\nYour customers and your data stay protected.",
            'followups' => $fu('Next?', ['What can Saathi do?', 'See pricing', 'Is setup easy?'])];
    }
    // WooCommerce / selling
    if (preg_match('/woo|woocommerce|sell|cart|checkout|order|ecommerce|store/u', $t)) {
        return ['reply' => "Saathi turns chat into a **sales channel** 🛒\n\n- Shows real **product cards** with image, rating & price\n- **Add to Cart** and **Buy Now** right inside the conversation\n- Recommends the right product from your catalog\n\nWant to see it? Just say *“show me a product demo”*.",
            'products' => demo_products(),
            'followups' => $fu('Next?', ['How do customers buy?', 'See pricing', 'Can I change the colours?'])];
    }
    // How it learns / knowledge
    if (preg_match('/learn|knowledge|scan|train|content|accurate|hallucinat/u', $t)) {
        return ['reply' => "Saathi **learns your real website** 🧠\n\n- Deep-scans only your **published** pages, posts & products\n- Answers are grounded in your content — no made-up info\n- Ignores drafts, trash and junk automatically\n- Re-scan anytime to stay current",
            'followups' => $fu('Next?', ['What languages?', 'Show a product demo', 'See pricing'])];
    }
    // Customization
    if (preg_match('/colou?r|mascot|custom|theme|brand|design|logo/u', $t)) {
        return ['reply' => "Make Saathi **truly yours** 🎨\n\n- Pick any **brand colour** — the whole widget re-themes instantly\n- Choose from **8 friendly mascots**\n- **Drag, resize** and position the window\n\nTry the colour & mascot controls right below this chat — or on the page!",
            'followups' => $fu('Next?', ['Show a product demo', 'See pricing', 'Is setup easy?'])];
    }
    // Setup
    if (preg_match('/set ?up|install|easy|how.*(start|use)|kaise|begin|get started/u', $t)) {
        return ['reply' => "Live in about **5 minutes** 👇\n\n- **Install** the plugin on WordPress\n- **Add your AI key** — any provider, even free models\n- **Deep-scan** your site so Saathi learns your content\n\nThat's it — Saathi starts answering right away.",
            'followups' => $fu('Next?', ['See pricing', 'Show a product demo', 'What languages?'])];
    }
    // Why best / compare
    if (preg_match('/best|why|compare|better|competitor|recommend|special/u', $t)) {
        return ['reply' => "Why Saathi stands out 🏆\n\n- **Agentic** — takes action, not just canned replies\n- **Sells** inside chat (WooCommerce)\n- **Multilingual** + grounded in your real content\n- **Premium & customizable**, yet **affordable**\n- **Improves over time**\n\nIt's support + sales + brand — in one bot.",
            'followups' => $fu('Next?', ['See pricing', 'Show a product demo', 'Is setup easy?'])];
    }
    // Contact / support / human
    if (preg_match('/contact|support|human|talk|help|email|reach/u', $t)) {
        return ['reply' => "Happy to help! You can:\n\n- **Start free** from the button above\n- Reach the team via the **Contact** link in the footer\n- Ask me anything about features or pricing right here",
            'followups' => $fu('Meanwhile:', ['What can Saathi do?', 'See pricing', 'Show a product demo'])];
    }
    // Thanks / bye
    if (preg_match('/thank|thanks|shukriya|bye|ok|great|nice|cool/u', $t)) {
        return ['reply' => "You're welcome! 😊 Whenever you're ready, hit **Start free** at the top — and feel free to ask me anything else."];
    }
    // Default — what is / capabilities
    return ['reply' => "**Saathi** is an agentic AI support + sales assistant for WordPress. In short:\n\n- **Knows your site** — answers from your real content\n- **Sells for you** — product cards, Add to Cart & Buy Now in chat\n- **Speaks your visitor's language**\n- **Fully yours** — colours, mascot, drag & resize\n- **Gets smarter over time**\n\nTry switching my colour & mascot below — or ask for a product demo!",
        'followups' => $fu('What next?', ['Show a product demo', 'See pricing', 'What languages?'])];
}
