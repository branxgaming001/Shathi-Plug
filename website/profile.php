<?php
require __DIR__ . '/includes/bootstrap.php';
$IMG = require __DIR__ . '/assets/images.php';
$u = require_login();

// Admins skip onboarding; already-complete users go straight in.
if (is_admin_email($u['email'] ?? '')) redirect('admin.php');
if (profile_complete($u)) redirect($_SESSION['next'] ?? 'dashboard.php');

$USE_CASES = [
  'support' => 'Customer support & FAQs',
  'sales'   => 'Sales & product recommendations',
  'both'    => 'Both support & sales',
  'kb'      => 'Knowledge base / docs assistant',
  'leadgen' => 'Lead capture / pre-sales',
  'other'   => 'Something else',
];
$HEARD = [
  'google'  => 'Google search',
  'wporg'   => 'WordPress.org plugin directory',
  'social'  => 'Social media (Instagram / Facebook / X)',
  'youtube' => 'YouTube',
  'friend'  => 'A friend or colleague',
  'blog'    => 'A blog / article',
  'ai'      => 'ChatGPT / an AI assistant',
  'other'   => 'Other',
];
$INDUSTRIES = ['E-commerce / Retail','SaaS / Software','Education','Healthcare','Real estate','Agency / Services','Hospitality / Travel','Media / Publishing','Non-profit','Other'];

$norm_domain = function (string $d): string {
    $d = trim(mb_strtolower($d));
    $d = preg_replace('#^https?://#', '', $d);
    $d = preg_replace('#/.*$#', '', $d);
    $d = preg_replace('#^www\.#', '', $d);
    return (string) $d;
};

$err = ''; $f = [];
$fields = ['first_name','last_name','mobile','company','website','use_case','industry','address','country','heard_from','goal'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($fields as $k) $f[$k] = trim((string)($_POST[$k] ?? ''));
    $f['website'] = $norm_domain($f['website']);

    if ($f['first_name'] === '' || mb_strlen($f['first_name']) > 80)      $err = 'Please enter your first name.';
    elseif ($f['last_name'] === '' || mb_strlen($f['last_name']) > 80)    $err = 'Please enter your last name.';
    elseif (!preg_match('/^[0-9+\-\s()]{7,20}$/', $f['mobile']))          $err = 'Please enter a valid mobile number (with country code).';
    elseif ($f['company'] === '' || mb_strlen($f['company']) > 150)       $err = 'Please enter your company or brand name.';
    elseif ($f['website'] === '' || !preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $f['website'])) $err = 'Enter a valid website domain, e.g. example.com';
    elseif (!isset($USE_CASES[$f['use_case']]))                          $err = 'Please choose how you plan to use Saathi.';
    elseif ($f['address'] === '' || mb_strlen($f['address']) > 400)       $err = 'Please enter your address.';
    elseif ($f['country'] === '' || mb_strlen($f['country']) > 80)        $err = 'Please enter your country.';
    elseif (!isset($HEARD[$f['heard_from']]))                            $err = 'Please tell us where you heard about us.';
    else {
        $name = trim($f['first_name'] . ' ' . $f['last_name']);
        pdo()->prepare("UPDATE users SET first_name=?, last_name=?, name=?, mobile=?, company=?, website=?, use_case=?, industry=?, address=?, country=?, heard_from=?, goal=?, profile_completed=1 WHERE id=?")
            ->execute([$f['first_name'],$f['last_name'],$name,$f['mobile'],$f['company'],$f['website'],$f['use_case'],$f['industry'],$f['address'],$f['country'],$f['heard_from'],$f['goal'],(int)$u['id']]);
        audit('profile_completed', ['company'=>$f['company'],'use_case'=>$f['use_case']], 'user', (int)$u['id']);
        $next = $_SESSION['next'] ?? 'dashboard.php'; unset($_SESSION['next']);
        redirect($next);
    }
}
// Prefill from a failed attempt, else from existing user record.
foreach ($fields as $k) if (!isset($f[$k])) $f[$k] = (string)($u[$k] ?? '');
$v = fn(string $k): string => e($f[$k] ?? '');
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Complete your profile — Saathi</title><link rel="icon" href="<?=$IMG['logo']?>">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/app.css">
<style>
  .ob-wrap{max-width:720px;margin:40px auto;padding:0 18px}
  .ob-card{background:#fff;border:1px solid var(--line);border-radius:22px;box-shadow:var(--shadow);padding:30px}
  .ob-head{display:flex;align-items:center;gap:12px;margin-bottom:6px}
  .ob-head img{width:36px;height:36px}
  .ob-card h1{font-size:26px;margin:0}
  .ob-sub{color:var(--ink2);margin:6px 0 20px;font-size:15px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  @media(max-width:560px){.grid2{grid-template-columns:1fr}}
  .ob-card .field{margin-bottom:14px}
  .ob-card label{display:block;font-weight:600;font-size:13.5px;margin-bottom:6px;color:var(--ink)}
  .ob-card label .req{color:#e0567a}
  .ob-card input,.ob-card select,.ob-card textarea{width:100%;box-sizing:border-box;border:1.5px solid var(--line);border-radius:11px;padding:11px 13px;font:inherit;font-size:14.5px;background:#fff;outline:none}
  .ob-card input:focus,.ob-card select:focus,.ob-card textarea:focus{border-color:var(--v)}
  .ob-card textarea{resize:vertical;min-height:70px}
  .hint{font-size:12px;color:var(--muted);margin-top:5px}
  .ob-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:8px;flex-wrap:wrap}
</style>
</head><body>
<div class="ob-wrap"><div class="ob-card">
  <div class="ob-head"><img src="<?=$IMG['logo']?>" alt=""><h1>Welcome to Saathi 🎉</h1></div>
  <p class="ob-sub">You're verified as <strong><?=e($u['email'] ?: $u['mobile'])?></strong>. Just a few quick details so we can tailor Saathi to your website and keep your licenses in order. All fields marked <span style="color:#e0567a">*</span> are required.</p>
  <?php if ($err): ?><div class="msg err"><?=e($err)?></div><?php endif; ?>

  <form method="post" autocomplete="on" novalidate>
    <?=csrf_field()?>

    <div class="grid2">
      <div class="field"><label>First name <span class="req">*</span></label><input name="first_name" value="<?=$v('first_name')?>" maxlength="80" required></div>
      <div class="field"><label>Last name <span class="req">*</span></label><input name="last_name" value="<?=$v('last_name')?>" maxlength="80" required></div>
    </div>

    <div class="grid2">
      <div class="field"><label>Mobile number <span class="req">*</span></label><input name="mobile" type="tel" value="<?=$v('mobile')?>" placeholder="+91 98765 43210" required><div class="hint">Include country code. Used only for account & billing contact.</div></div>
      <div class="field"><label>Country <span class="req">*</span></label><input name="country" value="<?=$v('country')?>" placeholder="India" maxlength="80" required></div>
    </div>

    <div class="grid2">
      <div class="field"><label>Company / brand name <span class="req">*</span></label><input name="company" value="<?=$v('company')?>" placeholder="RAI Labs" maxlength="150" required></div>
      <div class="field"><label>Website domain <span class="req">*</span></label><input name="website" value="<?=$v('website')?>" placeholder="example.com" maxlength="190" required><div class="hint">The site where you'll run Saathi (used for your license).</div></div>
    </div>

    <div class="grid2">
      <div class="field"><label>How will you use Saathi? <span class="req">*</span></label>
        <select name="use_case" required>
          <option value="">Select a use case…</option>
          <?php foreach ($USE_CASES as $k=>$lbl): ?><option value="<?=$k?>" <?=$f['use_case']===$k?'selected':''?>><?=e($lbl)?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Industry <span class="hint" style="display:inline">(optional)</span></label>
        <select name="industry">
          <option value="">Select…</option>
          <?php foreach ($INDUSTRIES as $opt): ?><option value="<?=e($opt)?>" <?=$f['industry']===$opt?'selected':''?>><?=e($opt)?></option><?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field"><label>Address <span class="req">*</span></label><textarea name="address" maxlength="400" placeholder="Street, city, state, postal code" required><?=$v('address')?></textarea></div>

    <div class="field"><label>Where did you hear about us? <span class="req">*</span></label>
      <select name="heard_from" required>
        <option value="">Select…</option>
        <?php foreach ($HEARD as $k=>$lbl): ?><option value="<?=$k?>" <?=$f['heard_from']===$k?'selected':''?>><?=e($lbl)?></option><?php endforeach; ?>
      </select>
    </div>

    <div class="field"><label>What do you hope Saathi will do for you? <span class="hint" style="display:inline">(optional)</span></label><textarea name="goal" maxlength="500" placeholder="e.g. Answer product questions 24/7 and boost sales on my store"><?=$v('goal')?></textarea></div>

    <div class="ob-foot">
      <span class="hint">🔒 Your details are private and used only to run your account.</span>
      <button class="btn btn-primary btn-lg">Save & continue →</button>
    </div>
  </form>
</div>
<p class="small" style="text-align:center;margin:14px 0"><a href="logout.php" style="color:var(--muted)">Log out</a></p>
</div></body></html>
