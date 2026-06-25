<?php
/**
 * Shared marketing layout: SEO <head>, sticky nav, footer.
 * Keeps every page consistent and the NEER Media rebrand + links in one place.
 */
declare(strict_types=1);

/** Brand/company name as a new-tab link to neermedia.com. */
function rai_labs(): string {
    return '<a href="https://neermedia.com" target="_blank" rel="noopener">NEER Media</a>';
}
function site_base(): string {
    return rtrim((string)(getenv('PUBLIC_URL') ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? 'saathi.neermedia.com'))), '/');
}

/**
 * Render <head> + opening <body>.
 * $o: title, desc, slug, schema (raw JSON-LD string, optional), extra_css (optional).
 */
function page_head(array $o): void {
    $IMG  = $GLOBALS['IMG'] ?? (require __DIR__ . '/../assets/images.php');
    $base = site_base();
    $title = $o['title'] ?? 'Saathi — AI Chatbot for WordPress';
    $desc  = $o['desc']  ?? 'Saathi is an agentic AI chatbot for WordPress & WooCommerce — supports and sells, trained on your real content.';
    $slug  = ltrim((string)($o['slug'] ?? ''), '/');
    $canon = $base . '/' . $slug;
    $ogimg = $base . '/assets/og.png';
    ?><!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=e($title)?></title>
<meta name="description" content="<?=e($desc)?>">
<link rel="canonical" href="<?=e($canon)?>">
<meta name="robots" content="index, follow">
<meta property="og:type" content="website"><meta property="og:site_name" content="Saathi">
<meta property="og:title" content="<?=e($title)?>"><meta property="og:description" content="<?=e($desc)?>">
<meta property="og:url" content="<?=e($canon)?>"><meta property="og:image" content="<?=e($ogimg)?>">
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="<?=e($title)?>"><meta name="twitter:description" content="<?=e($desc)?>"><meta name="twitter:image" content="<?=e($ogimg)?>">
<link rel="icon" href="<?=$IMG['logo']?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css">
<script type="application/ld+json"><?php echo json_encode([
    '@context'=>'https://schema.org','@type'=>'Organization','name'=>'NEER Media',
    'url'=>'https://neermedia.com','logo'=>$ogimg,'sameAs'=>['https://neermedia.com'],
    'brand'=>['@type'=>'Brand','name'=>'Saathi']
], JSON_UNESCAPED_SLASHES); ?></script>
<?php if (!empty($o['schema'])): ?><script type="application/ld+json"><?=$o['schema']?></script><?php endif; ?>
<style>
  .phero{position:relative;background:linear-gradient(160deg,var(--bg1,#f6f3ff),var(--bg2,#fff));overflow:hidden;padding:78px 0 54px;text-align:center}
  .phero .wrap{position:relative;z-index:2}
  .phero h1{font-size:clamp(32px,4.6vw,50px);font-weight:800;letter-spacing:-.5px;margin:10px 0 0}
  .phero p.lead{font-size:18px;color:var(--ink2);max-width:680px;margin:16px auto 0}
  .phero .cta-row{display:flex;gap:13px;justify-content:center;flex-wrap:wrap;margin-top:24px}
  .phero:before{content:"";position:absolute;width:420px;height:420px;border-radius:50%;filter:blur(72px);background:rgba(109,93,251,.16);top:-140px;left:-90px;z-index:0}
  .phero:after{content:"";position:absolute;width:360px;height:360px;border-radius:50%;filter:blur(72px);background:rgba(255,107,94,.13);top:-40px;right:-120px;z-index:0}
  .prose{max-width:820px;margin:0 auto}
  .prose h2{font-size:24px;margin:28px 0 8px}.prose h3{font-size:18px;margin:20px 0 6px}
  .prose p,.prose li{color:var(--ink2);font-size:15.5px;line-height:1.75}
  .prose ul,.prose ol{margin:10px 0 10px 22px}.prose li{margin:6px 0}
  .prose a{color:var(--v);font-weight:600}
  .prose .upd{color:var(--muted);font-size:13px}
  .docwrap{display:grid;grid-template-columns:240px 1fr;gap:30px;align-items:start}
  @media(max-width:820px){.docwrap{grid-template-columns:1fr}}
  .docnav{position:sticky;top:88px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:12px;box-shadow:var(--shadow-sm)}
  @media(max-width:820px){.docnav{position:static}}
  .docnav a{display:block;padding:8px 11px;border-radius:9px;color:var(--ink2);font-weight:600;font-size:14px;text-decoration:none}
  .docnav a:hover{background:#f3f0ff;color:var(--v)}
  .docblock{background:#fff;border:1px solid var(--line);border-radius:18px;padding:24px 26px;box-shadow:var(--shadow-sm);margin-bottom:16px}
  .docblock h3{margin:0 0 8px;font-size:19px}.docblock p,.docblock li{color:var(--ink2);font-size:15px;line-height:1.7}
  .docblock code{background:#f3f0ff;border:1px solid var(--line);border-radius:6px;padding:1px 6px;font-family:ui-monospace,monospace;font-size:13px;color:var(--v)}
  <?php if (!empty($o['extra_css'])) echo $o['extra_css']; ?>
</style>
</head><body>
<?php }

/** Sticky top navigation. $active = home|features|pricing|docs|about|contact */
function site_nav(string $active = ''): void {
    $IMG = $GLOBALS['IMG'] ?? [];
    $logo = $IMG['logo'] ?? '';
    $on = fn(string $k) => $active === $k ? ' style="color:var(--v)"' : '';
    ?>
<header class="nav"><div class="wrap">
  <a class="brand" href="index.php"><img src="<?=$logo?>" alt="Saathi logo">Saathi</a>
  <nav class="nav-links">
    <a href="features.php"<?=$on('features')?>>Features</a>
    <a href="pricing.php"<?=$on('pricing')?>>Pricing</a>
    <a href="docs.php"<?=$on('docs')?>>Docs</a>
    <a href="about.php"<?=$on('about')?>>About</a>
    <a href="contact.php"<?=$on('contact')?>>Contact</a>
  </nav>
  <div class="nav-cta">
    <a class="btn btn-ghost" href="index.php#top">Live demo</a>
    <a class="btn btn-primary" href="login.php">Get started</a>
  </div>
</div></header>
<?php }

/** Site footer with rebrand + real links. */
function site_footer(): void {
    $IMG = $GLOBALS['IMG'] ?? [];
    $logo = $IMG['logo'] ?? '';
    $year = date('Y');
    ?>
<footer><div class="wrap">
  <div>
    <a class="brand" href="index.php"><img src="<?=$logo?>" alt="" style="width:30px;height:30px"> Saathi</a>
    <p style="color:#a99fe0;font-size:14px;margin-top:12px;max-width:300px">The agentic AI chatbot that supports and sells for your WordPress &amp; WooCommerce website. A product by <?=rai_labs()?>.</p>
  </div>
  <div><h5>Product</h5><a href="features.php">Features</a><a href="pricing.php">Pricing</a><a href="docs.php">Docs &amp; Help</a><a href="index.php#top">Live demo</a></div>
  <div><h5>Company</h5><a href="about.php">About</a><a href="contact.php">Contact</a><a href="https://neermedia.com" target="_blank" rel="noopener">NEER Media ↗</a></div>
  <div><h5>Legal</h5><a href="privacy.php">Privacy</a><a href="terms.php">Terms</a><a href="refund.php">Refund</a></div>
</div><div class="foot-bottom">© <?=$year?> Saathi · a product by <?=rai_labs()?>. All rights reserved.</div></footer>
<?php }

function page_foot(): void { echo "\n</body></html>"; }
