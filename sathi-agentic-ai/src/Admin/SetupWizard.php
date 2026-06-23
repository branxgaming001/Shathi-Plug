<?php
/**
 * SetupWizard — first-run onboarding + license gate, rendered as a standalone,
 * chrome-stripped admin page (no wp-admin sidebar) with vanilla-JS transitions.
 *
 * It deliberately avoids the compiled React bundle: every step talks to the
 * plugin's EXISTING REST API (sathi/v1) with the wp_rest nonce — the same
 * endpoints the React admin uses — so it ships as plain PHP + JS.
 *
 * Flow: Welcome → License (gate) → AI Provider (+ live playground) → Persona
 * builder → Look & feel (mascot + colour) → [Deep scan, Max only] → Done.
 *
 * Gate behaviour: when enforcement is ON and no valid license is active, every
 * Saathi admin page redirects here and the wizard cannot advance past the
 * License step until a key is verified. Free keys pass the gate too.
 *
 * @package NeerMedia\Sathi\Admin
 */

namespace NeerMedia\Sathi\Admin;

use NeerMedia\Sathi\Core\Settings;
use NeerMedia\Sathi\License\LicenseManager;

class SetupWizard {

    public const PAGE          = 'sathi-setup';
    public const DONE_OPTION   = 'sathi_setup_complete';
    public const REDIRECT_TRANSIENT = 'sathi_setup_redirect';

    private Settings $settings;
    private LicenseManager $license;

    public function __construct( Settings $settings, ?LicenseManager $license = null ) {
        $this->settings = $settings;
        $this->license  = $license ?: new LicenseManager( $settings );
    }

    /** Wire hooks. Called from Plugin::boot during plugins_loaded. */
    public function register(): void {
        add_action( 'admin_init', [ $this, 'maybe_intercept' ], 1 );
    }

    /** True when the gate should hold the user on the wizard. */
    public function gate_active(): bool {
        return $this->license->enforcement_enabled() && ! $this->license->is_active();
    }

    /** True once the owner has finished (or skipped) onboarding. */
    public function is_complete(): bool {
        return (bool) get_option( self::DONE_OPTION, false );
    }

    /** Current tier derived from the verified license plan. */
    private function tier(): string {
        $plan = strtolower( (string) ( $this->license->status()['plan'] ?? '' ) );
        if ( in_array( $plan, [ 'max', 'lifetime', 'agency' ], true ) ) {
            return 'max';
        }
        if ( in_array( $plan, [ 'pro', 'pro_annual' ], true ) ) {
            return 'pro';
        }
        return 'free';
    }

    private function has_provider(): bool {
        foreach ( (array) $this->settings->get_provider_configs() as $cfg ) {
            if ( ! empty( $cfg['api_key'] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Single admin_init entry point: handle the "finish" action, the
     * post-activation redirect, the license gate, and the standalone render.
     */
    public function maybe_intercept(): void {
        if ( ! is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

        // ── Finish / skip action (nonce-protected) ──────────────────────
        if ( $page === self::PAGE && isset( $_GET['sathi_setup_action'] ) && $_GET['sathi_setup_action'] === 'complete' ) {
            check_admin_referer( 'sathi_setup_complete' );
            update_option( self::DONE_OPTION, 1, false );
            delete_transient( self::REDIRECT_TRANSIENT );
            wp_safe_redirect( admin_url( 'admin.php?page=sathi-dashboard' ) );
            exit;
        }

        // ── Render the standalone wizard page ───────────────────────────
        if ( $page === self::PAGE ) {
            $this->render_page();
            exit;
        }

        // ── Post-activation one-time redirect into the wizard ───────────
        if ( get_transient( self::REDIRECT_TRANSIENT ) ) {
            delete_transient( self::REDIRECT_TRANSIENT );
            if ( ! isset( $_GET['activate-multi'] ) && ! is_network_admin() && ! $this->is_complete() ) {
                wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
                exit;
            }
        }

        // ── License gate: hold Saathi pages on the wizard until licensed ─
        if ( $this->gate_active() && strpos( $page, 'sathi-' ) === 0 && $page !== self::PAGE ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
            exit;
        }
    }

    /** The 5 starting personas offered in the persona builder. */
    private function persona_presets(): array {
        return [
            [
                'id' => 'support', 'icon' => '💬', 'color' => '#6D5DFB',
                'title' => 'Support Agent',
                'role'  => 'Handles questions, orders & issues with warmth.',
                'tone'  => 'Friendly · Helpful',
                'hello' => 'Hi! I can help with orders, returns and any questions — what do you need?',
                'prompt'=> 'You are {assistant}, the friendly support assistant for {company} ({site}). Help visitors with questions about products, orders, returns and general support in the {field} space. Be warm, concise and proactive. Greet new visitors, ask a clarifying question when needed, and hand off to a human for anything you are unsure about. Stay strictly on-topic for {company}.',
            ],
            [
                'id' => 'sales', 'icon' => '🛍️', 'color' => '#FF6B5E',
                'title' => 'Sales Advisor',
                'role'  => 'Recommends the right products and guides to purchase.',
                'tone'  => 'Persuasive · Upbeat',
                'hello' => 'Looking for something? Tell me what you need and I will find the best match.',
                'prompt'=> 'You are {assistant}, the sales advisor for {company} ({site}). Understand the visitor\'s needs in the {field} space, recommend the best-fit products, explain benefits clearly, and confidently guide them toward a purchase. Be upbeat and helpful, never pushy. Answer pricing and availability questions and suggest add-ons when relevant.',
            ],
            [
                'id' => 'expert', 'icon' => '🛠️', 'color' => '#0EA5E9',
                'title' => 'Technical Expert',
                'role'  => 'Answers detailed how-to and troubleshooting questions.',
                'tone'  => 'Precise · Clear',
                'hello' => 'Need help setting something up or fixing an issue? Walk me through it.',
                'prompt'=> 'You are {assistant}, the technical expert for {company} ({site}). Give precise, step-by-step help with how-to and troubleshooting questions in the {field} space. Use clear structure and short steps. Confirm the visitor\'s setup before advising, cite the relevant docs, and escalate to a human for account-specific or risky changes.',
            ],
            [
                'id' => 'concierge', 'icon' => '🎩', 'color' => '#10B981',
                'title' => 'Concierge',
                'role'  => 'A polished, premium guide for your visitors.',
                'tone'  => 'Polite · Refined',
                'hello' => 'Welcome. It would be my pleasure to help — how may I assist you today?',
                'prompt'=> 'You are {assistant}, the concierge for {company} ({site}). Offer a polished, premium, white-glove experience to every visitor in the {field} space. Be courteous and refined, anticipate needs, and make tasteful recommendations. Keep replies elegant and unhurried, and gracefully connect visitors with a human for bespoke requests.',
            ],
            [
                'id' => 'mascot', 'icon' => '🎉', 'color' => '#F59E0B',
                'title' => 'Brand Mascot',
                'role'  => 'Playful brand personality that delights visitors.',
                'tone'  => 'Fun · Energetic',
                'hello' => 'Hey hey! 👋 So glad you dropped by — what can I do for you today?',
                'prompt'=> 'You are {assistant}, the playful brand mascot for {company} ({site}). Bring energy and personality to every chat in the {field} space while still being genuinely helpful. Use a light, fun tone (a tasteful emoji is fine), celebrate the visitor\'s wins, and keep things on-brand. Know when to switch to a clear, helpful mode for real questions, and hand off to a human when needed.',
            ],
        ];
    }

    /** Render the full standalone wizard document, then the caller exits. */
    private function render_page(): void {
        nocache_headers();

        $persona  = $this->settings->get_persona();
        $boot = [
            'rest'        => esc_url_raw( rest_url( 'sathi/v1' ) ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'dashboard'   => esc_url_raw( admin_url( 'admin.php?page=sathi-dashboard' ) ),
            'settings'    => esc_url_raw( admin_url( 'admin.php?page=sathi-settings' ) ),
            'completeUrl' => esc_url_raw( wp_nonce_url( admin_url( 'admin.php?page=' . self::PAGE . '&sathi_setup_action=complete' ), 'sathi_setup_complete' ) ),
            'pricing'     => 'https://saathi.neermedia.com/pricing.php',
            'getKey'      => 'https://saathi.neermedia.com/login.php',
            'site'        => get_bloginfo( 'name' ),
            'enforce'     => $this->license->enforcement_enabled(),
            'licensed'    => $this->license->is_active() && $this->license->get_key() !== '',
            'plan'        => (string) ( $this->license->status()['plan'] ?? '' ),
            'tier'        => $this->tier(),
            'hasProvider' => $this->has_provider(),
            'persona'     => [ 'name' => $persona['name'], 'text' => $persona['text'] ],
            'accent'      => (string) $this->settings->get( Settings::KEY_ACCENT_COLOR, '#6D5DFB' ),
            'avatar'      => (string) $this->settings->get( Settings::KEY_WIDGET_AVATAR, 'mascot-1' ),
            'presets'     => $this->persona_presets(),
        ];

        $css   = $this->styles();
        $js    = $this->script();
        $bootJson = wp_json_encode( $boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        $logo  = $this->logo_svg();

        // Standalone HTML — no get_header(); full chrome strip.
        echo '<!doctype html><html ' . get_language_attributes() . '><head>';
        echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . esc_html__( 'Set up Saathi AI', 'sathi-agentic-ai' ) . '</title>';
        echo '<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">';
        echo '<style>' . $css . '</style>';
        echo '</head><body class="sathi-wiz">';

        // Top bar
        echo '<header class="wz-top"><div class="wz-brand">' . $logo . '<span>Saathi</span></div>';
        echo '<a class="wz-exit" href="' . esc_url( $boot['completeUrl'] ) . '">' . esc_html__( 'Exit setup', 'sathi-agentic-ai' ) . ' &rsaquo;</a></header>';

        echo '<main class="wz-shell"><div id="wz-stepper" class="wz-stepper"></div><div id="wz-stage" class="wz-stage"></div></main>';

        echo '<div id="wz-toast" class="wz-toast" role="status" aria-live="polite"></div>';

        echo '<script id="sathi-setup-boot" type="application/json">' . $bootJson . '</script>';
        echo '<script>' . $js . '</script>';
        echo '</body></html>';
    }

    /** Compact brand mark (twin chat bubbles, brand violet/coral). */
    private function logo_svg(): string {
        return '<svg viewBox="0 0 40 40" width="30" height="30" aria-hidden="true">'
            . '<rect x="3" y="6" width="26" height="20" rx="9" fill="#6D5DFB"/>'
            . '<rect x="13" y="15" width="24" height="18" rx="8" fill="#FF6B5E"/>'
            . '<circle cx="21" cy="24" r="1.7" fill="#fff"/><circle cx="27" cy="24" r="1.7" fill="#fff"/><circle cx="33" cy="24" r="1.7" fill="#fff"/>'
            . '</svg>';
    }

    /** All wizard CSS (inlined into the standalone page). */
    private function styles(): string {
        return <<<'CSS'
*{box-sizing:border-box}
:root{--v:#6D5DFB;--v2:#7c3aed;--coral:#FF6B5E;--ink:#1c1733;--muted:#6b6780;--line:#e9e7f3;--ok:#15803d;--bad:#b3261e;--bg:#f6f5fb}
html,body{margin:0;padding:0}
body.sathi-wiz{font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:var(--ink);background:linear-gradient(180deg,#f8f7fd,#efeaff 60%,#f6f5fb);min-height:100vh}
h1,h2,h3,h4{font-family:'Baloo 2',cursive;margin:0;line-height:1.15}
a{color:var(--v);text-decoration:none}
.wz-top{display:flex;align-items:center;justify-content:space-between;padding:16px 28px;background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:5}
.wz-brand{display:flex;align-items:center;gap:9px;font-family:'Baloo 2';font-weight:800;font-size:20px}
.wz-exit{color:var(--muted);font-weight:600;font-size:14px}
.wz-shell{max-width:760px;margin:0 auto;padding:30px 20px 80px}
.wz-stepper{display:flex;align-items:center;gap:6px;justify-content:center;margin:6px 0 26px;flex-wrap:wrap}
.wz-step{display:flex;align-items:center;gap:8px;opacity:.5;transition:opacity .25s}
.wz-step.on,.wz-step.done{opacity:1}
.wz-step .dot{width:26px;height:26px;border-radius:50%;display:grid;place-items:center;background:#e7e3fb;color:var(--v);font-weight:800;font-size:13px;border:2px solid transparent}
.wz-step.on .dot{background:var(--v);color:#fff;box-shadow:0 4px 12px rgba(109,93,251,.4)}
.wz-step.done .dot{background:var(--ok);color:#fff}
.wz-step .lbl{font-size:13px;font-weight:600;color:var(--muted)}
.wz-step.on .lbl{color:var(--ink)}
.wz-step .bar{width:22px;height:2px;background:var(--line);border-radius:2px}
.wz-stage{position:relative}
.wz-card{background:#fff;border:1px solid var(--line);border-radius:20px;padding:34px;box-shadow:0 20px 50px -28px rgba(40,30,90,.35);animation:wzIn .26s ease}
.wz-card.out{animation:wzOut .18s ease forwards}
@keyframes wzIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
@keyframes wzOut{to{opacity:0;transform:translateY(-8px)}}
.wz-eyebrow{display:inline-block;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--v);background:#efeafe;padding:5px 11px;border-radius:999px;margin-bottom:12px}
.wz-card h1{font-size:30px;margin-bottom:8px}
.wz-card h2{font-size:23px;margin-bottom:6px}
.wz-lead{color:var(--muted);font-size:15.5px;line-height:1.55;margin:0 0 18px}
.wz-field{margin:14px 0}
.wz-field label{display:block;font-weight:600;font-size:14px;margin-bottom:6px}
.wz-input,.wz-select,.wz-textarea{width:100%;border:1.6px solid var(--line);border-radius:12px;padding:12px 14px;font:inherit;background:#fff;transition:border-color .15s,box-shadow .15s}
.wz-input:focus,.wz-select:focus,.wz-textarea:focus{outline:none;border-color:var(--v);box-shadow:0 0 0 4px rgba(109,93,251,.12)}
.wz-input.ok,.wz-textarea.ok{border-color:var(--ok)}
.wz-input.bad{border-color:var(--bad)}
.wz-textarea{min-height:130px;resize:vertical;line-height:1.5}
.wz-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.wz-pwwrap{position:relative}
.wz-pwwrap .wz-eye{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted);font-size:13px;font-weight:600}
.wz-btn{appearance:none;border:none;cursor:pointer;font:inherit;font-weight:700;border-radius:12px;padding:12px 22px;transition:transform .12s,box-shadow .12s,background .15s}
.wz-btn:active{transform:translateY(1px)}
.wz-primary{background:linear-gradient(135deg,var(--v),var(--v2));color:#fff;box-shadow:0 10px 22px -10px rgba(109,93,251,.7)}
.wz-primary:disabled{opacity:.45;cursor:not-allowed;box-shadow:none}
.wz-ghost{background:#f1eefc;color:var(--v)}
.wz-ghost:disabled{opacity:.5;cursor:not-allowed}
.wz-nav{display:flex;align-items:center;justify-content:space-between;margin-top:26px;gap:12px}
.wz-skip{background:none;border:none;color:var(--muted);font:inherit;font-weight:600;cursor:pointer;text-decoration:underline;font-size:13.5px}
.wz-note{font-size:13px;color:var(--muted);background:#f6f5fb;border:1px solid var(--line);border-radius:12px;padding:11px 14px;line-height:1.5}
.wz-note.warn{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
.wz-status{display:none;margin-top:12px;font-size:14px;font-weight:600;padding:10px 14px;border-radius:11px}
.wz-status.show{display:block;animation:wzIn .2s ease}
.wz-status.ok{background:#ecfdf3;color:var(--ok)}
.wz-status.bad{background:#fef2f2;color:var(--bad)}
.wz-status .hint{display:block;font-weight:500;color:var(--muted);margin-top:3px;font-size:13px}
.wz-spin{display:inline-block;width:15px;height:15px;border:2px solid rgba(255,255,255,.5);border-top-color:#fff;border-radius:50%;animation:wzspin .7s linear infinite;vertical-align:-2px;margin-right:7px}
.wz-ghost .wz-spin{border-color:rgba(109,93,251,.35);border-top-color:var(--v)}
@keyframes wzspin{to{transform:rotate(360deg)}}
.wz-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;margin:8px 0 4px}
.wz-pcard{position:relative;text-align:left;border:2px solid var(--line);background:#fff;border-radius:16px;padding:15px;cursor:pointer;transition:transform .15s,box-shadow .15s,border-color .15s}
.wz-pcard:hover{transform:translateY(-2px);box-shadow:0 14px 30px -18px rgba(40,30,90,.4)}
.wz-pcard.sel{border-color:var(--pc,var(--v));box-shadow:0 0 0 3px color-mix(in srgb,var(--pc,var(--v)) 22%,transparent)}
.wz-pcard .ic{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;font-size:20px;background:color-mix(in srgb,var(--pc,var(--v)) 16%,#fff);margin-bottom:9px}
.wz-pcard .pt{font-family:'Baloo 2';font-weight:700;font-size:16.5px}
.wz-pcard .pr{font-size:12.5px;color:var(--muted);margin:3px 0 7px;line-height:1.4}
.wz-pcard .tn{font-size:11px;font-weight:700;color:var(--pc,var(--v));text-transform:uppercase;letter-spacing:.04em}
.wz-bubble{margin-top:9px;font-size:12px;color:#444;background:#f6f5fb;border-radius:10px 10px 10px 3px;padding:8px 10px;line-height:1.4}
.wz-q{animation:wzIn .25s ease}
.wz-ack{color:var(--ok);font-weight:600;font-size:13.5px;margin:8px 0 0}
.wz-chiprow{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
.wz-chip{border:1.5px solid var(--line);background:#fff;border-radius:999px;padding:7px 14px;font:inherit;font-size:13px;font-weight:600;cursor:pointer;color:var(--muted);transition:.15s}
.wz-chip.sel{border-color:var(--v);background:#efeafe;color:var(--v)}
.wz-skel{height:14px;border-radius:7px;background:linear-gradient(90deg,#eee 25%,#f6f6f6 50%,#eee 75%);background-size:200% 100%;animation:wzsh 1.2s infinite linear;margin:8px 0}
@keyframes wzsh{to{background-position:-200% 0}}
.wz-mascots{display:grid;grid-template-columns:repeat(auto-fill,minmax(82px,1fr));gap:10px;margin-top:6px}
.wz-mas{position:relative;border:2px solid var(--line);border-radius:14px;padding:8px;cursor:pointer;background:#fff;text-align:center;transition:.15s}
.wz-mas img{width:48px;height:48px;object-fit:contain;display:block;margin:0 auto}
.wz-mas .mn{font-size:10.5px;color:var(--muted);margin-top:4px}
.wz-mas.sel{border-color:var(--v);box-shadow:0 0 0 3px rgba(109,93,251,.18)}
.wz-mas.locked{cursor:pointer}
.wz-mas.locked img{filter:grayscale(.7);opacity:.55}
.wz-lock{position:absolute;top:5px;right:5px;width:18px;height:18px;border-radius:50%;background:#1c1733;color:#fff;display:grid;place-items:center;font-size:10px}
.wz-swatches{display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin-top:6px}
.wz-sw{width:30px;height:30px;border-radius:50%;cursor:pointer;border:2px solid #fff;box-shadow:0 0 0 1.5px var(--line)}
.wz-sw.sel{box-shadow:0 0 0 2.5px var(--ink)}
.wz-chatlog{border:1px solid var(--line);border-radius:14px;padding:14px;background:#faf9fe;max-height:230px;overflow:auto;margin-top:8px}
.wz-msg{margin:6px 0;display:flex}
.wz-msg.u{justify-content:flex-end}
.wz-msg .b{max-width:80%;padding:9px 13px;border-radius:14px;font-size:13.5px;line-height:1.45}
.wz-msg.u .b{background:var(--v);color:#fff;border-bottom-right-radius:4px}
.wz-msg.a .b{background:#fff;border:1px solid var(--line);border-bottom-left-radius:4px}
.wz-success-ic{width:74px;height:74px;border-radius:50%;background:#ecfdf3;display:grid;place-items:center;margin:0 auto 14px}
.wz-success-ic svg{width:38px;height:38px;stroke:var(--ok);stroke-width:3;fill:none;stroke-dasharray:48;stroke-dashoffset:48;animation:wzdraw .6s .15s ease forwards}
@keyframes wzdraw{to{stroke-dashoffset:0}}
.wz-checklist{list-style:none;padding:0;margin:16px 0 0;text-align:left}
.wz-checklist li{padding:10px 0;border-top:1px solid var(--line);display:flex;gap:10px;align-items:flex-start;font-size:14px}
.wz-checklist li b{font-weight:700}
.wz-pop{position:fixed;inset:0;background:rgba(20,16,40,.45);display:none;place-items:center;z-index:30;padding:20px}
.wz-pop.show{display:grid;animation:wzIn .2s ease}
.wz-popcard{background:#fff;border-radius:20px;max-width:440px;width:100%;padding:26px;text-align:center}
.wz-popcard .crown{font-size:30px}
.wz-cols{display:flex;gap:12px;text-align:left;margin:16px 0}
.wz-col{flex:1;border:1px solid var(--line);border-radius:14px;padding:13px}
.wz-col h4{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
.wz-col ul{margin:0;padding-left:16px;font-size:13px;line-height:1.7}
.wz-col.max{border-color:var(--coral);background:#fff6f4}
@media (max-width:560px){.wz-card{padding:22px}.wz-cols{flex-direction:column}.wz-step .lbl{display:none}}
@media (prefers-reduced-motion:reduce){*{animation-duration:.01ms!important;transition-duration:.01ms!important}}
CSS;
    }

    /** The full vanilla-JS wizard controller (inlined). */
    private function script(): string {
        return <<<'JS'
(function(){
"use strict";
var BOOT = JSON.parse(document.getElementById('sathi-setup-boot').textContent);
var stage = document.getElementById('wz-stage');
var stepperEl = document.getElementById('wz-stepper');
var toastEl = document.getElementById('wz-toast');

// ── State ────────────────────────────────────────────────────────────
var state = {
  licensed: !!BOOT.licensed, plan: BOOT.plan || '', tier: BOOT.tier || 'free',
  hasProvider: !!BOOT.hasProvider,
  answers: { name: BOOT.persona && BOOT.persona.name && BOOT.persona.name!=='Saathi' ? BOOT.persona.name : '', company: BOOT.site || '', field: '' },
  persona: { name: (BOOT.persona && BOOT.persona.name) || 'Saathi', text: (BOOT.persona && BOOT.persona.text) || '', tone: '' },
  avatar: BOOT.avatar || 'mascot-1', accent: BOOT.accent || '#6D5DFB',
  mascots: null
};

function steps(){
  var s = ['welcome','license','provider','persona','look'];
  if (state.tier === 'max') s.push('scan');
  s.push('done');
  return s;
}
var idx = 0; // index into steps()

// ── REST helper ──────────────────────────────────────────────────────
function api(path, method, body){
  return fetch(BOOT.rest + path, {
    method: method || 'GET',
    headers: { 'Content-Type':'application/json', 'X-WP-Nonce': BOOT.nonce },
    body: body ? JSON.stringify(body) : undefined
  }).then(function(r){ return r.json().then(function(j){ return { ok:r.ok, status:r.status, data:j }; }); })
   .catch(function(e){ return { ok:false, status:0, data:{ message:String(e) } }; });
}
function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
function toast(msg){ toastEl.textContent = msg; toastEl.classList.add('show'); clearTimeout(toast._t); toast._t = setTimeout(function(){ toastEl.classList.remove('show'); }, 2200); }

// ── Stepper render ───────────────────────────────────────────────────
var LABELS = { license:'License', provider:'AI Provider', persona:'Persona', look:'Look & feel', scan:'Deep scan' };
function renderStepper(){
  var seq = steps().filter(function(s){ return s!=='welcome' && s!=='done'; });
  var cur = steps()[idx];
  var html = '';
  seq.forEach(function(s, i){
    var curPos = seq.indexOf(cur);
    var cls = (s===cur) ? 'on' : (curPos>i ? 'done' : '');
    html += '<div class="wz-step '+cls+'"><span class="dot">'+(curPos>i?'✓':(i+1))+'</span><span class="lbl">'+LABELS[s]+'</span></div>';
    if (i < seq.length-1) html += '<span class="bar"></span>';
  });
  stepperEl.innerHTML = html;
  stepperEl.style.display = (cur==='welcome'||cur==='done') ? 'none' : 'flex';
}

// ── Navigation ───────────────────────────────────────────────────────
function go(delta){
  var seq = steps();
  var next = Math.min(seq.length-1, Math.max(0, idx+delta));
  if (next === idx) return;
  // gate: cannot pass license step unless licensed (when enforcement on)
  if (delta>0 && seq[idx]==='license' && BOOT.enforce && !state.licensed){ return; }
  idx = next; transition();
}
function gotoStep(name){ var i = steps().indexOf(name); if(i>=0){ idx=i; transition(); } }
function transition(){
  var card = stage.firstElementChild;
  if (card){ card.classList.add('out'); setTimeout(paint, 150); } else { paint(); }
}
function paint(){
  renderStepper();
  var name = steps()[idx];
  stage.innerHTML = '';
  var card = document.createElement('div'); card.className='wz-card'; card.setAttribute('data-step',name);
  card.innerHTML = VIEWS[name] ? VIEWS[name]() : '';
  stage.appendChild(card);
  if (WIRE[name]) WIRE[name](card);
  var f = card.querySelector('input,textarea,select,button.wz-primary');
  if (f) setTimeout(function(){ try{ f.focus(); }catch(e){} }, 220);
  window.scrollTo({top:0,behavior:'smooth'});
}

// ── Shared nav markup ────────────────────────────────────────────────
function nav(opts){
  opts = opts || {};
  var back = opts.back===false ? '' : '<button class="wz-btn wz-ghost" data-act="back">Back</button>';
  var contLabel = opts.contLabel || 'Continue';
  var contId = opts.contId || 'wz-cont';
  var disabled = opts.contDisabled ? 'disabled' : '';
  var cont = opts.cont===false ? '<span></span>' : '<button class="wz-btn wz-primary" id="'+contId+'" data-act="cont" '+disabled+'>'+contLabel+'</button>';
  var skip = opts.skip ? '<div style="margin-top:10px;text-align:right"><button class="wz-skip" data-act="skip">'+opts.skip+'</button></div>' : '';
  return '<div class="wz-nav">'+(back||'<span></span>')+cont+'</div>'+skip;
}
function wireNav(card){
  card.querySelectorAll('[data-act="back"]').forEach(function(b){ b.onclick=function(){ go(-1); }; });
  card.querySelectorAll('[data-act="cont"]').forEach(function(b){ b.onclick=function(){ go(1); }; });
  card.querySelectorAll('[data-act="skip"]').forEach(function(b){ b.onclick=function(){ go(1); }; });
}

// ══ VIEWS ════════════════════════════════════════════════════════════
var VIEWS = {}, WIRE = {};

// Welcome
VIEWS.welcome = function(){
  return '<span class="wz-eyebrow">Welcome</span>'
    + '<h1>Let’s set up Saathi</h1>'
    + '<p class="wz-lead">A quick guided setup for <b>'+esc(BOOT.site)+'</b> — activate your licence, connect an AI provider, craft your assistant’s personality, and pick its look. Takes about 2 minutes. You can change anything later.</p>'
    + '<div class="wz-note">You bring your own AI key (free or paid providers both work), so you stay fully in control of usage and cost.</div>'
    + nav({ back:false, contLabel:'Get started →' });
};
WIRE.welcome = wireNav;

// License gate
VIEWS.license = function(){
  if (state.licensed){
    return '<span class="wz-eyebrow">Licence</span><h2>You’re activated 🎉</h2>'
      + '<p class="wz-lead">This site is running on the <b>'+esc(planLabel(state.plan))+'</b> plan. You’re all set to continue.</p>'
      + '<div class="wz-note">Manage or replace your key anytime from <b>Saathi → Settings → Licence</b>.</div>'
      + nav({ back:true });
  }
  var lockNote = BOOT.enforce ? '<div class="wz-note warn" style="margin-bottom:14px">Saathi stays locked until a valid licence is verified. Even the Free plan needs a key — it takes 30 seconds.</div>' : '';
  return '<span class="wz-eyebrow">Step 1 · Licence</span><h2>Activate Saathi</h2>'
    + '<p class="wz-lead">Enter your licence key to unlock Saathi on this site.</p>'
    + lockNote
    + '<div class="wz-field"><label>Licence key</label>'
    + '<input class="wz-input" id="wz-key" placeholder="SAATHI-XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false"></div>'
    + '<div class="wz-row"><button class="wz-btn wz-primary" id="wz-activate">Activate</button>'
    + '<a href="'+esc(BOOT.getKey)+'" target="_blank" rel="noopener" class="wz-skip" style="text-decoration:underline">I don’t have a key — get your free key</a></div>'
    + '<div class="wz-status" id="wz-licmsg"></div>'
    + '<details style="margin-top:14px"><summary style="cursor:pointer;color:var(--muted);font-size:13px;font-weight:600">Why is a key required?</summary>'
    + '<div class="wz-note" style="margin-top:8px">It links this site to your plan, enables automatic updates, and keeps your bot online. Your key is verified over a secure connection, and you can move it to another site anytime from your dashboard.</div></details>'
    + nav({ back:true, cont:false });
};
WIRE.license = function(card){
  wireNav(card);
  var keyEl = card.querySelector('#wz-key');
  var btn = card.querySelector('#wz-activate');
  var msg = card.querySelector('#wz-licmsg');
  if (!btn) return;
  btn.onclick = function(){
    var key = (keyEl.value||'').trim();
    if (!key){ keyEl.classList.add('bad'); keyEl.focus(); return; }
    keyEl.classList.remove('bad');
    btn.disabled = true; btn.innerHTML = '<span class="wz-spin"></span>Checking your key…';
    msg.className = 'wz-status';
    api('/license/activate','POST',{ key:key }).then(function(res){
      var d = res.data||{};
      if (d.success || d.status==='active'){
        state.licensed = true; state.plan = d.plan||''; state.tier = tierOf(d.plan);
        keyEl.classList.add('ok');
        msg.className='wz-status show ok'; msg.innerHTML = 'Key verified — '+esc(planLabel(d.plan))+' plan activated. Loading…';
        setTimeout(function(){ renderStepper(); go(1); }, 1300);
      } else {
        btn.disabled=false; btn.textContent='Activate'; keyEl.classList.add('bad');
        msg.className='wz-status show bad'; msg.innerHTML = esc(licError(d));
      }
    });
  };
  keyEl.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); btn.click(); } });
};
function licError(d){
  var r = (d.message||d.status||d.reason||'').toString().toLowerCase();
  if (r.indexOf('not_found')>=0||r.indexOf('not found')>=0) return 'Key not found — check for typos and try again.';
  if (r.indexOf('domain')>=0||r.indexOf('activation')>=0||r.indexOf('limit')>=0) return 'This key is already active on another site. Deactivate it there first, or use a different key.';
  if (r.indexOf('expired')>=0) return 'This key has expired — renew it from your dashboard.';
  if (r.indexOf('product')>=0) return 'This key is for a different product.';
  return d.message ? d.message : 'Could not verify this key. Please check it and try again.';
}

// Provider + playground
VIEWS.provider = function(){
  return '<span class="wz-eyebrow">Step 2 · AI Provider</span><h2>Connect an AI provider</h2>'
    + '<p class="wz-lead">Saathi works with ~15 providers. Paste a key from any one of them — <b>free and paid providers both work</b>. You can test it right here before continuing.</p>'
    + '<div class="wz-field"><label>Provider</label><select class="wz-select" id="wz-prov"><option value="">Loading providers…</option></select></div>'
    + '<div class="wz-field"><label>API key</label><div class="wz-pwwrap"><input class="wz-input" id="wz-pkey" type="password" placeholder="Paste your API key" autocomplete="off"><button type="button" class="wz-eye" id="wz-eye">Show</button></div>'
    + '<div id="wz-getkey" style="margin-top:6px;font-size:13px"></div></div>'
    + '<div class="wz-field" id="wz-modelwrap" style="display:none"><label>Model <span style="color:var(--muted);font-weight:500">(optional)</span></label><input class="wz-input" id="wz-model" placeholder="Leave blank for the default" list="wz-models"><datalist id="wz-models"></datalist></div>'
    + '<div class="wz-row"><button class="wz-btn wz-ghost" id="wz-savetest">Save &amp; test</button><span id="wz-provhint" style="font-size:13px;color:var(--muted)"></span></div>'
    + '<div class="wz-status" id="wz-provmsg"></div>'
    + '<div id="wz-playwrap" style="display:none;margin-top:14px"><div class="wz-chatlog" id="wz-chatlog"></div>'
    + '<div class="wz-row" style="margin-top:8px;flex-wrap:nowrap"><input class="wz-input" id="wz-playmsg" placeholder="Ask a test question…"><button class="wz-btn wz-primary" id="wz-playsend" style="white-space:nowrap">Send</button></div></div>'
    + nav({ back:true, contLabel:'Continue', skip:'Skip for now — I’ll add a provider later' });
};
WIRE.provider = function(card){
  wireNav(card);
  var sel=card.querySelector('#wz-prov'), pkey=card.querySelector('#wz-pkey'), eye=card.querySelector('#wz-eye'),
      modelWrap=card.querySelector('#wz-modelwrap'), modelEl=card.querySelector('#wz-model'), models=card.querySelector('#wz-models'),
      saveBtn=card.querySelector('#wz-savetest'), provmsg=card.querySelector('#wz-provmsg'), getkey=card.querySelector('#wz-getkey'),
      playWrap=card.querySelector('#wz-playwrap'), chatlog=card.querySelector('#wz-chatlog'), playMsg=card.querySelector('#wz-playmsg'), playSend=card.querySelector('#wz-playsend'),
      provhint=card.querySelector('#wz-provhint');
  var catalog = {};
  eye.onclick=function(){ if(pkey.type==='password'){pkey.type='text';eye.textContent='Hide';}else{pkey.type='password';eye.textContent='Show';} };

  api('/settings/providers','GET').then(function(res){
    var d=res.data||{}; catalog=d.catalog||{};
    var keys = d.available||Object.keys(catalog);
    sel.innerHTML='';
    keys.forEach(function(k){
      var c=catalog[k]||{}; var label=c.label||c.name||k;
      var free = c.free||c.is_free|| (c.free_tier);
      var o=document.createElement('option'); o.value=k; o.textContent=label+(free?'  • free tier available':''); sel.appendChild(o);
    });
    var def=d.default||keys[0]; if(def){ sel.value=def; }
    onProv();
  });
  function onProv(){
    var k=sel.value, c=catalog[k]||{};
    var url=c.api_key_url||c.keys_url||c.signup_url||'';
    getkey.innerHTML = url ? 'Get a key: <a href="'+esc(url)+'" target="_blank" rel="noopener">'+esc((c.label||k))+' dashboard →</a>' : '';
    var ms = c.models||[];
    if (ms && ms.length){ models.innerHTML = ms.map(function(m){return '<option value="'+esc(m)+'">';}).join(''); modelWrap.style.display='block'; }
    else { modelWrap.style.display='none'; }
  }
  sel.onchange=onProv;

  saveBtn.onclick=function(){
    var prov=sel.value, key=(pkey.value||'').trim();
    if(!prov){ return; }
    if(!key){ pkey.classList.add('bad'); pkey.focus(); return; }
    pkey.classList.remove('bad');
    saveBtn.disabled=true; saveBtn.innerHTML='<span class="wz-spin"></span>Saving & testing…'; provmsg.className='wz-status';
    var body={ api_key:key }; var mdl=(modelEl.value||'').trim(); if(mdl) body.model=mdl;
    api('/settings/providers/'+encodeURIComponent(prov), 'POST', body).then(function(){
      return api('/playground/chat','POST',{ message:'Hello! Reply with a short friendly greeting.', provider:prov, model:mdl });
    }).then(function(res){
      var d=res.data||{};
      saveBtn.disabled=false; saveBtn.textContent='Save & test';
      if(d.success){
        state.hasProvider=true;
        provmsg.className='wz-status show ok'; provmsg.innerHTML='Connected! '+(d.model?('Model: '+esc(d.model)+' · '):'')+(d.ms?(d.ms+'ms'):'');
        playWrap.style.display='block'; chatlog.innerHTML='';
        addMsg('a', d.reply||'Connected.');
        var cont=card.querySelector('#wz-cont'); if(cont){ cont.classList.add('pulse'); }
        toast('Provider saved');
      } else {
        provmsg.className='wz-status show bad'; provmsg.innerHTML='<b>'+esc(d.error||'Test failed')+'</b>'+(d.hint?'<span class="hint">'+esc(d.hint)+'</span>':'');
      }
    });
  };
  function addMsg(role, text){ var d=document.createElement('div'); d.className='wz-msg '+role; d.innerHTML='<div class="b">'+esc(text)+'</div>'; chatlog.appendChild(d); chatlog.scrollTop=chatlog.scrollHeight; }
  function send(){
    var m=(playMsg.value||'').trim(); if(!m) return; addMsg('u',m); playMsg.value='';
    var typing=document.createElement('div'); typing.className='wz-msg a'; typing.innerHTML='<div class="b"><span class="wz-skel" style="width:80px;margin:0"></span></div>'; chatlog.appendChild(typing); chatlog.scrollTop=chatlog.scrollHeight;
    api('/playground/chat','POST',{ message:m, provider:sel.value, model:(modelEl.value||'').trim() }).then(function(res){
      typing.remove(); var d=res.data||{};
      addMsg('a', d.success ? (d.reply||'') : ('⚠ '+(d.error||'Error')));
    });
  }
  playSend.onclick=send;
  playMsg.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); send(); } });
};

// Persona builder
VIEWS.persona = function(){
  var cards = BOOT.presets.map(function(p){
    return '<button class="wz-pcard" data-id="'+p.id+'" style="--pc:'+p.color+'">'
      + '<div class="ic">'+p.icon+'</div><div class="pt">'+esc(p.title)+'</div>'
      + '<div class="pr">'+esc(p.role)+'</div><div class="tn">'+esc(p.tone)+'</div>'
      + '<div class="wz-bubble">'+esc(p.hello)+'</div></button>';
  }).join('');
  return '<span class="wz-eyebrow">Step 3 · Persona</span><h2>Give your assistant a personality</h2>'
    + '<p class="wz-lead">Answer a couple of quick questions, then pick a starting personality. You can edit everything before saving.</p>'
    + '<div id="wz-qbox"></div>'
    + '<div id="wz-builder" style="display:none">'
    + '<h4 style="margin:18px 0 8px;font-size:16px">Choose a starting personality</h4>'
    + '<div class="wz-cards">'+cards+'</div>'
    + '<div class="wz-row" style="margin-top:6px"><button class="wz-btn wz-ghost" id="wz-genai">✨ Generate with AI</button>'
    + '<button class="wz-btn wz-ghost" id="wz-addown" style="background:#f6f5fb;color:var(--muted)">+ Write my own</button>'
    + '<span id="wz-gennote" style="font-size:12.5px;color:var(--muted)"></span></div>'
    + '<div id="wz-result" style="display:none;margin-top:14px"></div>'
    + '</div>'
    + nav({ back:true, contLabel:'Continue', contDisabled:true });
};
WIRE.persona = function(card){
  wireNav(card);
  var cont=card.querySelector('#wz-cont');
  var qbox=card.querySelector('#wz-qbox');
  var builder=card.querySelector('#wz-builder');
  var result=card.querySelector('#wz-result');
  var selected=null;

  // One-at-a-time questions
  var QS=[
    { k:'name', q:'What should we call your assistant?', ph:'e.g. Aria', ack:function(v){return 'Nice to meet you, '+v+'!';} },
    { k:'company', q:'What’s your company or site name?', ph:BOOT.site||'e.g. Acme Co', ack:function(v){return 'Got it — working for '+v+'.';} },
    { k:'field', q:'What field or industry are you in?', ph:'e.g. skincare, SaaS, education', ack:function(){return 'Perfect. Let’s build the personality.';} }
  ];
  var qi=0;
  function renderQ(){
    if (qi>=QS.length){ builder.style.display='block'; return; }
    var Q=QS[qi];
    qbox.innerHTML = '<div class="wz-q"><div class="wz-field"><label>'+esc(Q.q)+'</label>'
      + '<input class="wz-input" id="wz-qin" placeholder="'+esc(Q.ph)+'" value="'+esc(state.answers[Q.k]||'')+'"></div>'
      + '<div class="wz-row"><button class="wz-btn wz-primary" id="wz-qnext">'+(qi<QS.length-1?'Next':'Build it')+'</button>'
      + (qi>0?'<button class="wz-skip" id="wz-qback">back</button>':'')
      + '</div><p class="wz-ack" id="wz-qack" style="display:none"></p></div>';
    var inp=qbox.querySelector('#wz-qin'); setTimeout(function(){inp.focus();},120);
    function next(){
      var v=(inp.value||'').trim(); state.answers[Q.k]=v;
      var ack=qbox.querySelector('#wz-qack');
      if (v){ ack.textContent=Q.ack(v); ack.style.display='block'; }
      setTimeout(function(){ qi++; renderQ(); }, v?320:0);
    }
    qbox.querySelector('#wz-qnext').onclick=next;
    inp.addEventListener('keydown',function(e){ if(e.key==='Enter'){e.preventDefault(); next();} });
    var bk=qbox.querySelector('#wz-qback'); if(bk) bk.onclick=function(){ qi=Math.max(0,qi-1); renderQ(); };
  }
  renderQ();

  function fill(tpl){
    return tpl.replace(/\{assistant\}/g, state.answers.name||'Saathi')
              .replace(/\{company\}/g, state.answers.company||BOOT.site||'your business')
              .replace(/\{site\}/g, BOOT.site||state.answers.company||'this site')
              .replace(/\{field\}/g, state.answers.field||'your');
  }
  function showResult(name, text, tone){
    state.persona.name = name; state.persona.text = text; state.persona.tone = tone||'';
    var tones=['Friendly','Professional','Playful','Formal','Technical'];
    result.style.display='block';
    result.innerHTML = '<div class="wz-field"><label>Assistant name</label><input class="wz-input" id="wz-pname" value="'+esc(name)+'"></div>'
      + '<div class="wz-field"><label>Personality &amp; instructions</label><textarea class="wz-textarea" id="wz-ptext">'+esc(text)+'</textarea></div>'
      + '<div class="wz-field"><label>Tone</label><div class="wz-chiprow" id="wz-tones">'+tones.map(function(t){return '<button type="button" class="wz-chip'+(tone===t?' sel':'')+'" data-t="'+t+'">'+t+'</button>';}).join('')+'</div></div>';
    result.querySelector('#wz-pname').addEventListener('input',function(e){ state.persona.name=e.target.value; });
    result.querySelector('#wz-ptext').addEventListener('input',function(e){ state.persona.text=e.target.value; });
    result.querySelectorAll('#wz-tones .wz-chip').forEach(function(ch){ ch.onclick=function(){ result.querySelectorAll('#wz-tones .wz-chip').forEach(function(x){x.classList.remove('sel');}); ch.classList.add('sel'); state.persona.tone=ch.getAttribute('data-t'); }; });
    cont.disabled=false; cont.classList.add('pulse');
    result.scrollIntoView({behavior:'smooth',block:'nearest'});
  }

  card.querySelectorAll('.wz-pcard').forEach(function(pc){
    pc.onclick=function(){
      card.querySelectorAll('.wz-pcard').forEach(function(x){x.classList.remove('sel');});
      pc.classList.add('sel');
      var p=BOOT.presets.filter(function(x){return x.id===pc.getAttribute('data-id');})[0];
      selected=p;
      showResult(state.answers.name||p.title, fill(p.prompt), p.tone.split(' ')[0]);
    };
  });
  card.querySelector('#wz-addown').onclick=function(){ showResult(state.answers.name||'Saathi', state.persona.text||'', 'Friendly'); };

  // Generate with AI (gated on provider)
  var gen=card.querySelector('#wz-genai'), note=card.querySelector('#wz-gennote');
  if (!state.hasProvider){
    gen.setAttribute('aria-disabled','true'); gen.style.opacity='.55';
    note.innerHTML='Needs a connected provider. <a href="#" id="wz-toprov">Add a provider →</a>';
    note.querySelector('#wz-toprov').onclick=function(e){ e.preventDefault(); gotoStep('provider'); };
    gen.onclick=function(){ toast('Add an AI provider first (Step 2) to generate with AI.'); gotoStep('provider'); };
  } else {
    gen.onclick=function(){
      var desc='An assistant named '+(state.answers.name||'Saathi')+' for '+(state.answers.company||BOOT.site)+', in the '+(state.answers.field||'general')+' field'+(selected?(', styled like a '+selected.title+' ('+selected.tone+')'):'')+'.';
      gen.disabled=true; gen.innerHTML='<span class="wz-spin"></span>Generating…';
      result.style.display='block';
      result.innerHTML='<div class="wz-skel" style="width:40%"></div><div class="wz-skel"></div><div class="wz-skel"></div><div class="wz-skel" style="width:70%"></div>';
      api('/persona/generate','POST',{ description:desc, answers:{ 'Assistant name:':state.answers.name, 'Company:':state.answers.company, 'Field:':state.answers.field } }).then(function(res){
        gen.disabled=false; gen.innerHTML='✨ Generate with AI';
        var d=res.data||{};
        if(d.success){ showResult(d.name||state.answers.name||'Saathi', d.persona||'', (selected?selected.tone.split(' ')[0]:'Friendly')); toast('Persona generated'); }
        else { result.innerHTML='<div class="wz-status show bad"><b>'+esc(d.error||'Could not generate')+'</b>'+(d.hint?'<span class="hint">'+esc(d.hint)+'</span>':'')+'</div>'; }
      });
    };
  }

  // Save persona when leaving the step
  cont.addEventListener('click', function(){
    if (cont.disabled) return;
    api('/settings','POST',{ sathi_persona_name: state.persona.name||'Saathi', sathi_persona_text: state.persona.text||'' });
  }, true);
};

// Look & feel (mascot + colour)
VIEWS.look = function(){
  return '<span class="wz-eyebrow">Step 4 · Look &amp; feel</span><h2>Pick a mascot &amp; colour</h2>'
    + '<p class="wz-lead">Choose the face and accent colour for your chat widget. The default mascot and colour are free for everyone; the full mascot set unlocks on Pro & Max.</p>'
    + '<div class="wz-field"><label>Mascot</label><div class="wz-mascots" id="wz-masgrid"><div class="wz-skel" style="height:60px"></div></div></div>'
    + '<div class="wz-field"><label>Or upload your own</label><input type="file" id="wz-upload" accept="image/png,image/jpeg,image/webp,image/svg+xml"><div id="wz-upmsg" style="font-size:12.5px;color:var(--muted);margin-top:5px"></div></div>'
    + '<div class="wz-field"><label>Accent colour</label><div class="wz-swatches" id="wz-sw"></div><input type="color" id="wz-color" value="'+esc(state.accent)+'" style="margin-top:8px;width:46px;height:34px;border:none;background:none;cursor:pointer"></div>'
    + nav({ back:true, contLabel:'Continue' });
};
WIRE.look = function(card){
  wireNav(card);
  var grid=card.querySelector('#wz-masgrid');
  var paid = state.tier==='pro' || state.tier==='max';
  var swwrap=card.querySelector('#wz-sw'), colorEl=card.querySelector('#wz-color');
  ['#6D5DFB','#FF6B5E','#0EA5E9','#10B981','#F59E0B','#EC4899','#111827'].forEach(function(c){
    var b=document.createElement('button'); b.type='button'; b.className='wz-sw'+(c.toLowerCase()===state.accent.toLowerCase()?' sel':''); b.style.background=c;
    b.onclick=function(){ swwrap.querySelectorAll('.wz-sw').forEach(function(x){x.classList.remove('sel');}); b.classList.add('sel'); state.accent=c; colorEl.value=c; saveLook(); };
    swwrap.appendChild(b);
  });
  colorEl.oninput=function(){ state.accent=colorEl.value; swwrap.querySelectorAll('.wz-sw').forEach(function(x){x.classList.remove('sel');}); saveLook(); };

  api('/settings/mascots','GET').then(function(res){
    var d=res.data||{}; state.mascots=d.mascots||{}; var labels=d.labels||{};
    var ids=Object.keys(state.mascots);
    if(!ids.length){ grid.innerHTML='<div class="wz-note">No bundled mascots found.</div>'; return; }
    grid.innerHTML='';
    ids.forEach(function(id, i){
      var locked = !paid && i>0; // first mascot free for all; rest locked on Free
      var el=document.createElement('button'); el.type='button';
      el.className='wz-mas'+(state.avatar===id?' sel':'')+(locked?' locked':'');
      el.innerHTML='<img src="'+state.mascots[id]+'" alt=""><div class="mn">'+esc(labels[id]||id)+'</div>'+(locked?'<span class="wz-lock">🔒</span>':'');
      el.onclick=function(){
        if(locked){ openUpgrade('mascots'); return; }
        grid.querySelectorAll('.wz-mas').forEach(function(x){x.classList.remove('sel');}); el.classList.add('sel');
        state.avatar=id; saveLook();
      };
      grid.appendChild(el);
    });
  });

  card.querySelector('#wz-upload').onchange=function(e){
    var f=e.target.files&&e.target.files[0]; if(!f) return;
    var msg=card.querySelector('#wz-upmsg');
    if(f.size>650000){ msg.style.color='var(--bad)'; msg.textContent='Image is too large (max ~600KB). Pick a smaller file.'; return; }
    var rd=new FileReader();
    rd.onload=function(){ state.avatar='custom'; state.customAvatar=rd.result;
      grid.querySelectorAll('.wz-mas').forEach(function(x){x.classList.remove('sel');});
      msg.style.color='var(--ok)'; msg.textContent='Custom mascot ready.';
      api('/settings','POST',{ sathi_widget_avatar:'custom', sathi_widget_avatar_custom:rd.result, sathi_accent_color:state.accent });
      toast('Mascot uploaded');
    };
    rd.readAsDataURL(f);
  };

  function saveLook(){ api('/settings','POST',{ sathi_widget_avatar:state.avatar, sathi_accent_color:state.accent }); }
  // ensure leaving saves
  card.querySelector('#wz-cont').addEventListener('click',function(){ saveLook(); }, true);
};

// Deep scan (Max only)
VIEWS.scan = function(){
  return '<span class="wz-eyebrow">Step 5 · Deep scan · Max</span><h2>Teach Saathi your site</h2>'
    + '<p class="wz-lead">Deep scan reads your published pages and products so Saathi can answer precisely and recommend the right products — a Max feature.</p>'
    + '<div class="wz-note" style="margin-bottom:8px"><b>Good to know:</b><br>• It indexes only published, public content.<br>• It can take a few minutes on large sites; you can keep working.<br>• Re-run it anytime after you add or change content.<br>• For semantic answers, set an embeddings provider in Settings (keyword search works without one).</div>'
    + '<div class="wz-row"><button class="wz-btn wz-primary" id="wz-scan">Run deep scan</button><span id="wz-scanhint" style="font-size:13px;color:var(--muted)"></span></div>'
    + '<div class="wz-status" id="wz-scanmsg"></div>'
    + '<div id="wz-scanres" style="display:none;margin-top:12px"></div>'
    + nav({ back:true, contLabel:'Continue', skip:'Skip — I’ll scan later' });
};
WIRE.scan = function(card){
  wireNav(card);
  var btn=card.querySelector('#wz-scan'), msg=card.querySelector('#wz-scanmsg'), res=card.querySelector('#wz-scanres'), hint=card.querySelector('#wz-scanhint');
  if(!state.hasProvider){ hint.innerHTML='Tip: add a provider (Step 2) for the smartest results.'; }
  btn.onclick=function(){
    btn.disabled=true; btn.innerHTML='<span class="wz-spin"></span>Scanning your site…'; msg.className='wz-status';
    api('/knowledge/index','POST',{}).then(function(){
      return api('/knowledge/stats','GET');
    }).then(function(r){
      btn.disabled=false; btn.textContent='Re-run scan';
      var d=r.data||{}; var sources=d.sources!=null?d.sources:(d.total_sources!=null?d.total_sources:'—'); var chunks=d.chunks!=null?d.chunks:(d.total_chunks!=null?d.total_chunks:(d.embedded_chunks!=null?d.embedded_chunks:'—'));
      msg.className='wz-status show ok'; msg.textContent='Scan started — Saathi is learning your site.';
      res.style.display='block';
      res.innerHTML='<div class="wz-note"><b>Indexed so far:</b> '+esc(String(sources))+' source(s), '+esc(String(chunks))+' chunk(s). '
        + 'Review and curate everything in <a href="'+esc(BOOT.dashboard).replace('sathi-dashboard','sathi-knowledge')+'">Knowledge Base</a>.</div>';
      toast('Deep scan running');
    });
  };
};

// Done
VIEWS.done = function(){
  var isFree = state.tier==='free';
  var extra = (state.tier==='max')
    ? '<li>✨ <span><b>Max unlocked.</b> WooCommerce showcase, deep scan, self-improvement, follow-ups and add-to-cart are all on.</span></li>'
    : (state.tier==='pro'
        ? '<li>⭐ <span><b>Pro active.</b> All 8 mascots, AI personas, memory and navigation are on. Upgrade to Max for commerce features.</span></li>'
        : '<li>🔓 <span><b>You’re on Free.</b> Upgrade to Pro/Max anytime to unlock more mascots, memory, WooCommerce selling and more.</span></li>');
  return '<div style="text-align:center">'
    + '<div class="wz-success-ic"><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></div>'
    + '<h1>Saathi is ready 🎉</h1>'
    + '<p class="wz-lead" style="text-align:center">Your assistant is set up for <b>'+esc(BOOT.site)+'</b>. Here’s what to do next:</p>'
    + '<ul class="wz-checklist">'
    + '<li>💬 <span><b>Test your bot</b> on the front end of your site.</span></li>'
    + '<li>🎨 <span><b>Fine-tune persona, mascot & widget</b> anytime in Saathi settings.</span></li>'
    + extra
    + '</ul>'
    + '<div class="wz-nav" style="justify-content:center;gap:12px"><a class="wz-btn wz-primary" href="'+esc(BOOT.completeUrl)+'">Go to dashboard</a>'
    + '<a class="wz-btn wz-ghost" href="'+esc(BOOT.settings)+'">Review settings</a></div>'
    + (isFree?'<p style="margin-top:14px"><a href="'+esc(BOOT.pricing)+'" target="_blank" rel="noopener">See Pro & Max plans →</a></p>':'')
    + '</div>';
};
WIRE.done = function(card){ /* links navigate away (completeUrl marks setup done) */ };

// ── Upgrade popover (visible-but-locked features) ────────────────────
var FEATURE_COPY = {
  mascots: { title:'Unlock all 8 mascots', line:'The full mascot set is a Pro & Max feature.' },
  woocommerce: { title:'Show products inside your chat', line:'WooCommerce product showcase is a Max feature.' },
  deep_scan: { title:'Let Saathi learn your entire site', line:'Deep scan is a Max feature.' },
  add_to_cart: { title:'Let visitors buy without leaving chat', line:'Direct add-to-cart is a Max feature.' }
};
function openUpgrade(feature){
  var c=FEATURE_COPY[feature]||{title:'Upgrade your plan',line:'This is a paid feature.'};
  var pop=document.createElement('div'); pop.className='wz-pop show';
  pop.innerHTML='<div class="wz-popcard"><div class="crown">👑</div><h2 style="margin:6px 0 4px">'+esc(c.title)+'</h2>'
    + '<p class="wz-lead" style="text-align:center">'+esc(c.line)+' Upgrade to keep Free fully usable and add more power.</p>'
    + '<div class="wz-cols"><div class="wz-col"><h4>Your plan</h4><ul><li>Core AI chat</li><li>1 provider</li><li>Default mascot + colour</li></ul></div>'
    + '<div class="wz-col max"><h4>Max · ₹699/mo</h4><ul><li>All 8 mascots</li><li>WooCommerce showcase</li><li>Deep scan + add-to-cart</li><li>Follow-ups + self-improve</li></ul></div></div>'
    + '<div class="wz-nav" style="justify-content:center;gap:10px"><a class="wz-btn wz-primary" href="'+esc(BOOT.pricing)+'" target="_blank" rel="noopener">Upgrade to Max — ₹699/mo</a>'
    + '<button class="wz-btn wz-ghost" id="wz-popclose">Maybe later</button></div></div>';
  document.body.appendChild(pop);
  pop.querySelector('#wz-popclose').onclick=function(){ pop.remove(); };
  pop.onclick=function(e){ if(e.target===pop) pop.remove(); };
}

// ── Helpers ──────────────────────────────────────────────────────────
function tierOf(plan){ plan=(plan||'').toLowerCase(); if(['max','lifetime','agency'].indexOf(plan)>=0) return 'max'; if(['pro','pro_annual'].indexOf(plan)>=0) return 'pro'; return 'free'; }
function planLabel(plan){ plan=(plan||'').toLowerCase(); if(plan==='max'||plan==='lifetime'||plan==='agency') return 'Max'; if(plan==='pro'||plan==='pro_annual') return 'Pro'; if(plan==='free') return 'Free'; return plan?plan.charAt(0).toUpperCase()+plan.slice(1):'Free'; }

// ── Boot ─────────────────────────────────────────────────────────────
// Gate active (enforcement on + not licensed) → open straight on the licence
// step so the owner can unlock immediately. Otherwise start at Welcome.
idx = ( BOOT.enforce && !state.licensed ) ? steps().indexOf('license') : 0;
paint();
})();
JS;
    }
}
