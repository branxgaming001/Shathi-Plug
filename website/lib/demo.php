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

/** Offline fallback (used when no OpenRouter key is configured). */
function canned_reply(string $m): array {
    $t = mb_strtolower($m);
    if (preg_match('/price|cost|kitne|kitna|plan|pricing|paisa|charge/u', $t)) {
        return ['reply' => "Here's our simple pricing:\n\n- **Free** — get started with core chat\n- **Pro — Rs 999/mo** — WooCommerce showcase, multilingual, full customization, deep knowledge scan\n- **Lifetime — Rs 9,999** — pay once, everything forever\n\nWant to see how the in-chat product showcase looks?",
            'followups' => ['question' => 'Next?', 'options' => ['Show a product demo', 'Is setup easy?', 'What can Saathi do?']]];
    }
    if (wants_products($m)) {
        return ['reply' => "Sure! We don't sell products on this site, but here's a **live demo** — this is exactly how your products appear inside Saathi chat, with image, rating, price, and Buy & Add buttons:",
            'products' => demo_products(),
            'followups' => ['question' => 'Want to see more?', 'options' => ['How do customers buy?', 'Show pricing', 'Can I change the colours?']]];
    }
    if (preg_match('/set ?up|install|easy|how.*(start|use)|kaise/u', $t)) {
        return ['reply' => "Super simple to launch 👇\n\n- **Install** the plugin on WordPress\n- **Add your AI key** — any provider works, even free models\n- **Deep-scan** your site so Saathi learns your real content\n\nThat's it — Saathi is live and answering in minutes.",
            'followups' => ['question' => 'Next?', 'options' => ['See pricing', 'Show a product demo', 'What languages?']]];
    }
    return ['reply' => "Saathi is an agentic AI support + sales assistant for WordPress. Highlights:\n\n- **Knows your site** — scans your real published content\n- **Sells for you** — product cards, Add to Cart & Buy Now right in chat\n- **Speaks your visitor's language** — fully multilingual\n- **Fully yours** — change colours & mascot, drag & resize\n- **Gets smarter over time**\n\nTry the colour & mascot controls below the chat 👇",
        'followups' => ['question' => 'What next?', 'options' => ['Show a product demo', 'See pricing', 'Is setup easy?']]];
}
