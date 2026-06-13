import React, { useEffect, useState, useCallback, useRef } from 'react';

declare global {
  interface Window {
    sathiAdmin: { restUrl: string; nonce: string; siteName: string; accentColor: string; version: string; logo?: string };
  }
}
const admin = window.sathiAdmin || ({} as Window['sathiAdmin']);

interface Stats { total_chunks: number; total_sources: number; total_tokens: number; last_crawl: string | null }
type Cfg = { api_key?: string; model?: string; base_url?: string; temperature?: number; max_tokens?: number };
type CatalogEntry = { label: string; group: string; base_url: string; default_model: string; needs_key: boolean; has_model_list: boolean; color: string; docs: string; models: string[] };
type SettingsMap = Record<string, any>;
type TabKey = 'overview' | 'providers' | 'chatbot' | 'personas' | 'knowledge' | 'license';

const POSITIONS = [
  { v: 'bottom-right', l: 'Bottom Right' }, { v: 'bottom-left', l: 'Bottom Left' },
  { v: 'top-right', l: 'Top Right' }, { v: 'top-left', l: 'Top Left' },
];
const LAUNCHER_ICONS = ['chat', '💬', '🤖', '✨', '🎓', '🛟', '👋', '⚡'];
// Ready-made brand palettes for the header/widget colour.
const BRAND_PRESETS = ['#6D5DFB', '#5B6CF0', '#2DB4FF', '#19C37D', '#FF6B5E', '#F0567A', '#F59E0B', '#0E9F6E', '#7C3AED', '#111827'];
// Signature colour per mascot — picking a mascot tints the whole bot window.
const MASCOT_COLORS: Record<string, string> = {
  'mascot-1': '#6D5DFB', 'mascot-2': '#5B6CF0', 'mascot-3': '#FF6B5E', 'mascot-4': '#7A5CFF',
  'mascot-5': '#2DB4FF', 'mascot-6': '#7C3AED', 'mascot-7': '#19C37D', 'mascot-8': '#F0567A',
};
const GROUP_LABELS: Record<string, string> = {
  cloud: 'Cloud providers', aggregator: 'Aggregators & fast inference', local: 'Self-hosted / local', custom: 'Universal',
};
const GROUP_ORDER = ['cloud', 'aggregator', 'local', 'custom'];

const api = {
  get: (p: string) => fetch(`${admin.restUrl}${p}`, { headers: { 'X-WP-Nonce': admin.nonce } }),
  post: (p: string, b: any) => fetch(`${admin.restUrl}${p}`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': admin.nonce }, body: JSON.stringify(b) }),
  del: (p: string) => fetch(`${admin.restUrl}${p}`, { method: 'DELETE', headers: { 'X-WP-Nonce': admin.nonce } }),
};

const inp = 'w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-rai-blue-400 bg-white';

const AdminPanel: React.FC = () => {
  const [tab, setTab] = useState<TabKey>('overview');
  const [loading, setLoading] = useState(true);
  const [stats, setStats] = useState<Stats | null>(null);
  const [providers, setProviders] = useState<Record<string, Cfg>>({});
  const [catalog, setCatalog] = useState<Record<string, CatalogEntry>>({});
  const [embeddingKeys, setEmbeddingKeys] = useState<string[]>([]);
  const [defaultProvider, setDefaultProvider] = useState('');
  const [embed, setEmbed] = useState({ provider: '', model: 'text-embedding-3-small' });
  const [settings, setSettings] = useState<SettingsMap>({});
  const [toast, setToast] = useState<{ msg: string; ok: boolean } | null>(null);
  const [pg, setPg] = useState<{ provider: string; model: string } | null>(null);

  const flash = (msg: string, ok = true) => { setToast({ msg, ok }); setTimeout(() => setToast(null), 3200); };

  const fetchAll = useCallback(async () => {
    setLoading(true);
    try {
      const [s, p, st] = await Promise.all([
        api.get('/settings'), api.get('/settings/providers'), api.get('/knowledge/stats'),
      ]);
      if (s.ok) setSettings((await s.json()).settings || {});
      if (p.ok) {
        const d = await p.json();
        setProviders(d.providers || {}); setCatalog(d.catalog || {});
        setEmbeddingKeys(d.embedding_keys || []); setDefaultProvider(d.default || '');
        setEmbed({ provider: d.embed_provider || '', model: d.embed_model || 'text-embedding-3-small' });
      }
      if (st.ok) setStats(await st.json());
    } catch (e) { console.error('[Saathi]', e); } finally { setLoading(false); }
  }, []);
  useEffect(() => { fetchAll(); }, [fetchAll]);

  const saveSettings = async (patch: SettingsMap) => {
    setSettings((cur) => ({ ...cur, ...patch }));
    const res = await api.post('/settings', patch);
    flash(res.ok ? 'Saved' : 'Save failed', res.ok);
  };

  if (loading) {
    return (
      <div className="sathi-admin-panel" style={{ maxWidth: 1080, margin: '0 auto', padding: 34 }}>
        <div className="flex flex-col items-center justify-center py-24 text-gray-400">
          <div className="animate-spin w-9 h-9 border-[3px] border-rai-blue-600 border-t-transparent rounded-full mb-4" />
          <p className="text-sm">Loading Saathi…</p>
        </div>
      </div>
    );
  }

  const configuredCount = Object.values(providers).filter((c) => c?.api_key).length;
  const TABS: { k: TabKey; label: string }[] = [
    { k: 'overview', label: 'Overview' }, { k: 'providers', label: 'AI Providers' },
    { k: 'chatbot', label: 'Chatbot' }, { k: 'personas', label: 'Personas' }, { k: 'knowledge', label: 'Knowledge' }, { k: 'license', label: 'License' },
  ];

  return (
    <div className="sathi-admin-panel" style={{ maxWidth: 1080, margin: '0 auto', padding: '21px 34px 55px' }}>
      {/* Header */}
      <div className="flex items-center gap-3 mb-5">
        <div className="w-12 h-12 rounded-2xl flex items-center justify-center shadow-md bg-white border border-gray-100 p-1.5">
          {admin.logo
            ? <img src={admin.logo} alt="Saathi" className="w-full h-full object-contain" />
            : <span className="text-2xl" style={{ color: '#6D5DFB' }}>✦</span>}
        </div>
        <div className="flex-1">
          <h1 className="text-2xl font-bold text-rai-black leading-tight tracking-tight">Saathi AI</h1>
          <p className="text-xs text-gray-500">v{admin.version} · {admin.siteName} · a product by RAI</p>
        </div>
        {configuredCount === 0 && (
          <span className="px-3 py-1.5 rounded-full text-xs font-medium bg-rai-gold-50 text-rai-gold-600 border border-rai-gold-100">
            Connect an AI provider to begin
          </span>
        )}
      </div>

      {/* Tabs */}
      <div className="flex gap-1 p-1 bg-gray-100 rounded-xl mb-6 w-fit max-w-full overflow-x-auto">
        {TABS.map((t) => (
          <button key={t.k} onClick={() => setTab(t.k)}
            className={`px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap transition-all ${tab === t.k ? 'sathi-tab-active' : 'text-gray-500 hover:text-gray-800'}`}>
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'overview' && <Overview stats={stats} personaName={(settings.sathi_persona_name as string) || 'Saathi'} configuredCount={configuredCount} defaultProvider={defaultProvider} goto={setTab} />}
      {tab === 'providers' && (
        <ProvidersTab catalog={catalog} providers={providers} embeddingKeys={embeddingKeys} embed={embed} defaultProvider={defaultProvider}
          onChangeDefault={(k: string) => { setDefaultProvider(k); saveSettings({ sathi_default_provider: k }); }}
          onSaveEmbed={(prov: string, model: string) => { setEmbed({ provider: prov, model }); saveSettings({ sathi_embed_provider: prov, sathi_embed_model: model }); }}
          onSaved={fetchAll} flash={flash} onPlayground={(provider: string, model: string) => setPg({ provider, model })} />
      )}
      {tab === 'chatbot' && <ChatbotTab settings={settings} onSave={saveSettings} accent={admin.accentColor} />}
      {tab === 'personas' && <PersonasTab settings={settings} onSave={saveSettings} flash={flash} />}
      {tab === 'knowledge' && <KnowledgeTab stats={stats} flash={flash} refresh={fetchAll} />}
      {tab === 'license' && <LicenseTab settings={settings} onSave={saveSettings} flash={flash} />}

      {pg && <PlaygroundDrawer cfg={pg} onClose={() => setPg(null)} />}

      {/* RAI brand credit */}
      <div className="mt-10 pt-5 border-t border-gray-100 text-center">
        <span className="text-xs text-gray-400"><strong className="text-rai-blue-700 font-semibold">Saathi</strong> · a product by RAI</span>
      </div>

      {toast && (
        <div className={`fixed bottom-6 right-6 px-4 py-3 rounded-xl text-sm font-medium shadow-lg z-[99999] ${toast.ok ? 'bg-rai-black text-white' : 'bg-red-600 text-white'}`}>
          {toast.ok ? '✓ ' : '✕ '}{toast.msg}
        </div>
      )}
    </div>
  );
};

// ── Overview ──────────────────────────────────────────────────────────
const Overview: React.FC<any> = ({ stats, personaName, configuredCount, defaultProvider, goto }) => {
  const steps = [
    { done: configuredCount > 0, label: 'Connect an AI provider', tab: 'providers' as TabKey },
    { done: !!stats && stats.total_chunks > 0, label: 'Scan & index your site content', tab: 'knowledge' as TabKey },
    { done: true, label: 'Customize the chatbot appearance & placement', tab: 'chatbot' as TabKey },
  ];
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <Stat label="AI Providers" value={`${configuredCount}`} icon="⚡" tone={configuredCount ? 'good' : 'warn'} />
        <Stat label="Assistant" value={personaName} icon="🎭" />
        <Stat label="Knowledge Chunks" value={(stats?.total_chunks ?? 0).toLocaleString()} icon="🧠" />
        <Stat label="Default Provider" value={defaultProvider || '—'} icon="◎" />
      </div>
      <Card title="Setup checklist" subtitle="A few quiet steps to bring Saathi to life.">
        <div className="space-y-2">
          {steps.map((s: any, i: number) => (
            <button key={i} onClick={() => goto(s.tab)} className="sathi-card w-full flex items-center gap-3 p-3 rounded-xl border border-gray-100 hover:border-rai-blue-200 hover:shadow-sm text-left bg-white">
              <span className={`w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold ${s.done ? 'bg-rai-blue-50 text-rai-blue-700' : 'bg-gray-100 text-gray-400'}`}>{s.done ? '✓' : i + 1}</span>
              <span className={`text-sm flex-1 ${s.done ? 'text-gray-400 line-through' : 'text-gray-800 font-medium'}`}>{s.label}</span>
              <span className="text-gray-300">→</span>
            </button>
          ))}
        </div>
      </Card>
    </div>
  );
};

// ── Providers (catalog-driven) ────────────────────────────────────────
const ProvidersTab: React.FC<any> = ({ catalog, providers, embeddingKeys, embed, defaultProvider, onChangeDefault, onSaveEmbed, onSaved, flash, onPlayground }) => {
  const keys = Object.keys(catalog);
  const byGroup: Record<string, string[]> = {};
  keys.forEach((k) => { const g = catalog[k].group || 'cloud'; (byGroup[g] = byGroup[g] || []).push(k); });

  return (
    <div className="space-y-5">
      <Card title="AI Providers" subtitle="Bring your own key for any provider. The chatbot uses your default; the rest stay ready as fallbacks. Keys are stored encrypted and never sent to the browser.">
        {GROUP_ORDER.filter((g) => byGroup[g]).map((g) => (
          <div key={g} className="mb-5 last:mb-0">
            <div className="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2">{GROUP_LABELS[g]}</div>
            <div className="space-y-3">
              {byGroup[g].map((k) => (
                <ProviderCard key={k} pkey={k} meta={catalog[k]} cfg={providers[k] || {}} isDefault={defaultProvider === k}
                  onMakeDefault={() => onChangeDefault(k)} onSaved={onSaved} flash={flash} onPlayground={onPlayground} />
              ))}
            </div>
          </div>
        ))}
      </Card>

      <EmbeddingsCard catalog={catalog} embeddingKeys={embeddingKeys} embed={embed} defaultProvider={defaultProvider} onSave={onSaveEmbed} />
    </div>
  );
};

const ProviderCard: React.FC<any> = ({ pkey, meta, cfg, isDefault, onMakeDefault, onSaved, flash, onPlayground }) => {
  const hasKey = !!cfg.api_key;
  const [apiKey, setApiKey] = useState('');
  const [model, setModel] = useState(cfg.model || meta.default_model || '');
  const [baseUrl, setBaseUrl] = useState(cfg.base_url || meta.base_url || '');
  const [temp, setTemp] = useState(cfg.temperature ?? 0.7);
  const [maxTok, setMaxTok] = useState(cfg.max_tokens ?? 4096);
  const [show, setShow] = useState(false);
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState<'' | 'save' | 'test' | 'models'>('');
  const [modelOpts, setModelOpts] = useState<string[]>(meta.models || []);
  const [testMsg, setTestMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const isLocalOrCustom = meta.group === 'local' || pkey === 'custom';

  const payload = () => ({ api_key: apiKey, model: model || meta.default_model, base_url: baseUrl, temperature: Number(temp), max_tokens: Number(maxTok) });

  const save = async () => {
    setBusy('save');
    const res = await api.post(`/settings/providers/${pkey}`, payload());
    setBusy(''); if (apiKey) setApiKey('');
    if (res.ok) { flash(`${meta.label} saved`); onSaved(); } else flash(`Could not save ${meta.label}`, false);
  };
  const test = async () => {
    setBusy('test'); setTestMsg(null);
    await api.post(`/settings/providers/${pkey}`, payload());
    const res = await api.post(`/settings/providers/${pkey}/test`, {});
    const d = await res.json().catch(() => ({}));
    setBusy(''); if (apiKey) setApiKey(''); onSaved();
    setTestMsg(res.ok && d.success ? { ok: true, text: d.response ? `Reply: ${String(d.response).slice(0, 40)}` : 'Connection OK' } : { ok: false, text: d.error || 'Test failed' });
  };
  const fetchModels = async () => {
    setBusy('models');
    const res = await api.get(`/settings/providers/${pkey}/models`);
    const d = await res.json().catch(() => ({}));
    setBusy('');
    if (d.models?.length) { setModelOpts(d.models); flash(`Loaded ${d.models.length} models`); }
    else flash('No models returned (enter manually)', false);
  };
  const openPlayground = async () => {
    // Persist the latest key/model first so the playground uses what's on screen.
    setBusy('save');
    await api.post(`/settings/providers/${pkey}`, payload());
    setBusy(''); if (apiKey) setApiKey(''); onSaved();
    onPlayground && onPlayground(pkey, model || meta.default_model || '');
  };

  return (
    <div className="sathi-card rounded-2xl border border-gray-200 bg-white overflow-hidden">
      <button onClick={() => setOpen((o) => !o)} className="w-full flex items-center gap-3 px-4 py-3 text-left">
        <span className="w-2.5 h-2.5 rounded-full flex-shrink-0" style={{ background: meta.color }} />
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="font-semibold text-sm text-rai-black">{meta.label}</span>
            {hasKey && <span className="px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-50 text-emerald-700">Configured</span>}
            {isDefault && <span className="px-2 py-0.5 rounded-full text-[11px] font-medium bg-rai-blue-50 text-rai-blue-700">Default</span>}
          </div>
          <div className="text-[11px] text-gray-400 truncate">{meta.docs}</div>
        </div>
        <span className="text-gray-300 text-xs">{open ? '▲' : '▼'}</span>
      </button>

      {open && (
        <div className="px-4 pb-4 border-t border-gray-100 pt-3 space-y-3">
          <div className="grid md:grid-cols-2 gap-3">
            {meta.needs_key && (
              <div>
                <label className="block text-[11px] font-medium text-gray-500 mb-1">API Key</label>
                <div className="flex">
                  <input type={show ? 'text' : 'password'} value={apiKey} onChange={(e) => setApiKey(e.target.value)}
                    placeholder={hasKey ? '•••••••• (saved)' : 'Paste API key'} className="flex-1 px-3 py-2 text-sm border border-gray-200 rounded-l-lg focus:outline-none focus:border-rai-blue-400" />
                  <button onClick={() => setShow((s) => !s)} className="px-3 border border-l-0 border-gray-200 rounded-r-lg text-gray-400 text-xs">{show ? '🙈' : '👁'}</button>
                </div>
              </div>
            )}
            <div>
              <label className="block text-[11px] font-medium text-gray-500 mb-1">Model</label>
              <div className="flex gap-1">
                <ModelSelect value={model} onChange={setModel} options={modelOpts} placeholder={meta.default_model} />
                {meta.has_model_list && (
                  <button onClick={fetchModels} disabled={!!busy} title="Fetch available models" className="px-2 border border-gray-200 rounded-lg text-gray-500 text-xs hover:bg-gray-50">{busy === 'models' ? '…' : '⟳'}</button>
                )}
              </div>
            </div>
            {isLocalOrCustom && (
              <div className="md:col-span-2">
                <label className="block text-[11px] font-medium text-gray-500 mb-1">Base URL</label>
                <input value={baseUrl} onChange={(e) => setBaseUrl(e.target.value)} placeholder={meta.base_url || 'https://…/v1'} className={inp} />
              </div>
            )}
            <div>
              <label className="block text-[11px] font-medium text-gray-500 mb-1">Temperature</label>
              <input type="number" step="0.1" min="0" max="2" value={temp} onChange={(e) => setTemp(parseFloat(e.target.value))} className={inp} />
            </div>
            <div>
              <label className="block text-[11px] font-medium text-gray-500 mb-1">Max tokens</label>
              <input type="number" min="1" value={maxTok} onChange={(e) => setMaxTok(parseInt(e.target.value) || 0)} className={inp} />
            </div>
          </div>
          <div className="flex items-center gap-2 flex-wrap">
            <button onClick={save} disabled={!!busy} className="px-3.5 py-2 rounded-lg text-sm font-medium text-white bg-rai-blue-600 hover:bg-rai-blue-700 disabled:opacity-50">{busy === 'save' ? 'Saving…' : 'Save'}</button>
            <button onClick={test} disabled={!!busy} className="px-3.5 py-2 rounded-lg text-sm font-medium border border-gray-200 text-gray-700 hover:bg-gray-50 disabled:opacity-50">{busy === 'test' ? 'Testing…' : 'Test connection'}</button>
            <button onClick={openPlayground} disabled={!!busy} title="Open a live test chat" className="px-3.5 py-2 rounded-lg text-sm font-medium text-white bg-rai-gold-500 hover:brightness-95 disabled:opacity-50">▶ Playground</button>
            {!isDefault && hasKey && <button onClick={onMakeDefault} className="px-3 py-2 rounded-lg text-sm border border-gray-200 text-gray-600 hover:bg-gray-50">Make default</button>}
            {testMsg && <span className={`text-xs font-medium ${testMsg.ok ? 'text-emerald-600' : 'text-red-600'}`}>{testMsg.ok ? '✓ ' : '✕ '}{testMsg.text}</span>}
          </div>
        </div>
      )}
    </div>
  );
};

const EmbeddingsCard: React.FC<any> = ({ catalog, embeddingKeys, embed, defaultProvider, onSave }) => {
  const [provider, setProvider] = useState(embed.provider || '');
  const [model, setModel] = useState(embed.model || 'text-embedding-3-small');
  useEffect(() => { setProvider(embed.provider || ''); setModel(embed.model || 'text-embedding-3-small'); }, [embed.provider, embed.model]);
  return (
    <Card title="Embeddings" subtitle="Used for site search / knowledge. Often a cheaper model than chat. Leave provider blank to reuse your default.">
      <div className="grid md:grid-cols-2 gap-3">
        <Field label="Embeddings provider">
          <select className={inp} value={provider} onChange={(e) => setProvider(e.target.value)}>
            <option value="">Same as default ({defaultProvider || 'none'})</option>
            {embeddingKeys.map((k: string) => <option key={k} value={k}>{catalog[k]?.label || k}</option>)}
          </select>
        </Field>
        <Field label="Embeddings model"><input className={inp} value={model} onChange={(e) => setModel(e.target.value)} placeholder="text-embedding-3-small" /></Field>
      </div>
      <div className="mt-3"><button onClick={() => onSave(provider, model)} className="px-4 py-2 rounded-lg text-sm font-medium text-white bg-rai-blue-600 hover:bg-rai-blue-700">Save embeddings</button></div>
    </Card>
  );
};

// ── Chatbot (Appearance + Placement) ──────────────────────────────────
const ChatbotTab: React.FC<any> = ({ settings, onSave, accent }) => {
  const [draft, setDraft] = useState<SettingsMap>(settings);
  useEffect(() => setDraft(settings), [settings]);
  const set = (k: string, v: any) => setDraft((d) => ({ ...d, [k]: v }));
  const g = (k: string, dflt: any) => (draft[k] ?? dflt);
  const mode = g('sathi_widget_display_mode', 'all');
  const [mascots, setMascots] = useState<Record<string, string>>({});
  const [mascotLabels, setMascotLabels] = useState<Record<string, string>>({});
  useEffect(() => { api.get('/settings/mascots').then((r) => (r.ok ? r.json() : null)).then((d) => { if (d) { setMascots(d.mascots || {}); setMascotLabels(d.labels || {}); } }).catch(() => {}); }, []);
  const avatar = g('sathi_widget_avatar', 'mascot-1');
  const customImg = g('sathi_widget_avatar_custom', '');
  const onUploadCustom = (file?: File) => {
    if (!file) return;
    if (file.size > 600000) { alert('Please choose an image under 600 KB.'); return; }
    const reader = new FileReader();
    reader.onload = () => { set('sathi_widget_avatar_custom', String(reader.result)); set('sathi_widget_avatar', 'custom'); };
    reader.readAsDataURL(file);
  };
  return (
    <div className="space-y-4">
      <Card title="Appearance" subtitle="How the chat widget presents itself.">
        <div className="grid md:grid-cols-2 gap-4">
          <Toggle label="Enable floating chat widget" checked={!!g('sathi_floating_widget', true)} onChange={(v: boolean) => set('sathi_floating_widget', v)} />
          <Toggle label="Auto-open after a delay" checked={!!g('sathi_widget_auto_open', false)} onChange={(v: boolean) => set('sathi_widget_auto_open', v)} />
          <Field label="Widget title"><input className={inp} value={g('sathi_widget_title', '')} placeholder="e.g. Your Company Support" onChange={(e) => set('sathi_widget_title', e.target.value)} /></Field>
          <Field label="Greeting message"><input className={inp} value={g('sathi_chat_greeting', '')} placeholder="How can I help you today?" onChange={(e) => set('sathi_chat_greeting', e.target.value)} /></Field>
          <Field label="Position"><select className={inp} value={g('sathi_floating_position', 'bottom-right')} onChange={(e) => set('sathi_floating_position', e.target.value)}>{POSITIONS.map((p) => <option key={p.v} value={p.v}>{p.l}</option>)}</select></Field>
          <Field label="Theme"><select className={inp} value={g('sathi_widget_theme', 'light')} onChange={(e) => set('sathi_widget_theme', e.target.value)}><option value="light">Light</option><option value="dark">Dark</option><option value="auto">Auto (system)</option></select></Field>
          <Field label="Header & widget colour">
            <div className="flex items-center gap-2">
              <input type="color" value={g('sathi_accent_color', accent || '#6D5DFB')} onChange={(e) => set('sathi_accent_color', e.target.value)} className="w-10 h-9 rounded-lg border border-gray-200" />
              <input className={inp} value={g('sathi_accent_color', accent || '#6D5DFB')} onChange={(e) => set('sathi_accent_color', e.target.value)} />
            </div>
            <div className="flex flex-wrap gap-1.5 mt-2">
              {BRAND_PRESETS.map((c) => {
                const cur = String(g('sathi_accent_color', accent || '#6D5DFB') || '').toLowerCase() === c.toLowerCase();
                return (
                  <button key={c} type="button" title={c} aria-label={`Use ${c}`} onClick={() => set('sathi_accent_color', c)}
                    className={`w-7 h-7 rounded-full border-2 transition ${cur ? 'border-gray-800 scale-110' : 'border-white shadow hover:scale-110'}`}
                    style={{ background: c }} />
                );
              })}
            </div>
            <p className="text-[11px] text-gray-400 mt-1.5">Pick your brand colour — or it auto-matches the mascot you choose below.</p>
          </Field>
          <Field label="Launcher icon (if no mascot)">
            <div className="flex flex-wrap gap-1.5">
              {LAUNCHER_ICONS.map((ic) => (
                <button key={ic} onClick={() => set('sathi_widget_launcher_icon', ic)} className={`w-9 h-9 rounded-lg border text-lg flex items-center justify-center ${g('sathi_widget_launcher_icon', 'chat') === ic ? 'border-rai-blue-500 bg-rai-blue-50' : 'border-gray-200 hover:bg-gray-50'}`}>{ic === 'chat' ? '💭' : ic}</button>
              ))}
            </div>
          </Field>
          <div className="md:col-span-2">
            <Field label="Chat avatar / mascot — pick the one you like">
              <div className="flex flex-wrap gap-3 items-start">
                {Object.keys(mascots).length === 0 && <span className="text-xs text-gray-400">Loading mascots…</span>}
                {Object.entries(mascots).map(([id, src]) => (
                  <button key={id} type="button" onClick={() => { set('sathi_widget_avatar', id); if (MASCOT_COLORS[id]) set('sathi_accent_color', MASCOT_COLORS[id]); }} title={mascotLabels[id] || id}
                    className="flex flex-col items-center gap-1">
                    <span className={`w-16 h-16 rounded-2xl border-2 p-1 bg-white flex items-center justify-center transition ${avatar === id ? 'border-rai-blue-500 shadow-md' : 'border-gray-200 hover:border-gray-300'}`}>
                      <img src={src as string} alt={id} className="w-full h-full object-contain rounded-xl" />
                    </span>
                    <span className="text-[10px] text-gray-500">{mascotLabels[id] || id}</span>
                  </button>
                ))}
                {/* Custom mascot */}
                <button type="button" onClick={() => set('sathi_widget_avatar', 'custom')} title="Use your own image"
                  className="flex flex-col items-center gap-1">
                  <span className={`w-16 h-16 rounded-2xl border-2 flex items-center justify-center overflow-hidden transition ${avatar === 'custom' ? 'border-rai-blue-500 shadow-md bg-white' : 'border-dashed border-gray-300 hover:border-gray-400 bg-gray-50'}`}>
                    {customImg ? <img src={customImg} alt="custom" className="w-full h-full object-contain rounded-xl" /> : <span className="text-2xl text-gray-400">＋</span>}
                  </span>
                  <span className="text-[10px] text-gray-500">Custom</span>
                </button>
                {/* No mascot */}
                <button type="button" onClick={() => set('sathi_widget_avatar', 'spark')} title="Spark icon (no mascot)"
                  className="flex flex-col items-center gap-1">
                  <span className={`w-16 h-16 rounded-2xl border-2 flex items-center justify-center text-3xl transition ${avatar === 'spark' ? 'border-rai-blue-500 bg-rai-blue-50' : 'border-gray-200 hover:bg-gray-50'}`} style={{ color: '#6D5DFB' }}>✦</span>
                  <span className="text-[10px] text-gray-500">None</span>
                </button>
              </div>
            </Field>
            {avatar === 'custom' && (
              <div className="mt-3 p-3 rounded-xl border border-gray-200 bg-gray-50 space-y-2">
                <div className="text-[11px] font-medium text-gray-600">Your custom mascot — shown on the launcher, header & replies.</div>
                <div className="flex flex-col sm:flex-row gap-2">
                  <input className={inp} placeholder="Paste an image URL (https://…)" value={typeof customImg === 'string' && customImg.startsWith('http') ? customImg : ''} onChange={(e) => set('sathi_widget_avatar_custom', e.target.value)} />
                  <label className="px-3 py-2 rounded-lg text-sm font-medium border border-gray-200 text-gray-700 hover:bg-white cursor-pointer whitespace-nowrap text-center">
                    Upload image
                    <input type="file" accept="image/*" className="hidden" onChange={(e) => onUploadCustom(e.target.files?.[0])} />
                  </label>
                  {customImg && <button type="button" onClick={() => set('sathi_widget_avatar_custom', '')} className="px-3 py-2 rounded-lg text-sm border border-gray-200 text-gray-500 hover:bg-white">Clear</button>}
                </div>
                <div className="text-[10px] text-gray-400">PNG, JPG, WEBP or SVG. Keep uploads under 600&nbsp;KB; for larger images paste a URL. A square, transparent image looks best.</div>
              </div>
            )}
          </div>
          {!!g('sathi_widget_auto_open', false) && <Field label="Auto-open delay (seconds)"><input type="number" min={0} className={inp} value={g('sathi_widget_auto_open_delay', 5)} onChange={(e) => set('sathi_widget_auto_open_delay', parseInt(e.target.value) || 0)} /></Field>}
        </div>
      </Card>
      <Card title="Placement" subtitle="Where on your site the chatbot appears.">
        <div className="space-y-4">
          <div className="grid sm:grid-cols-3 gap-2">
            {[{ v: 'all', t: 'Everywhere', d: 'Whole site' }, { v: 'include', t: 'Only on selected', d: 'Specific pages' }, { v: 'exclude', t: 'Hide on selected', d: 'All except some' }].map((o) => (
              <button key={o.v} onClick={() => set('sathi_widget_display_mode', o.v)} className={`sathi-card text-left p-3 rounded-xl border ${mode === o.v ? 'border-rai-blue-500 bg-rai-blue-50' : 'border-gray-200 hover:border-gray-300'}`}>
                <div className="text-sm font-semibold text-gray-800">{o.t}</div><div className="text-[11px] text-gray-500">{o.d}</div>
              </button>
            ))}
          </div>
          {mode !== 'all' && (
            <Field label={`Page / post IDs to ${mode === 'include' ? 'show on' : 'hide on'} (comma separated)`}>
              <input className={inp} placeholder="e.g. 12, 48, 105" value={(Array.isArray(g('sathi_widget_display_pages', [])) ? g('sathi_widget_display_pages', []).join(', ') : '')} onChange={(e) => set('sathi_widget_display_pages', e.target.value.split(/[\s,]+/).filter(Boolean).map(Number))} />
            </Field>
          )}
          <Field label="Limit to post types (optional, comma separated)"><input className={inp} placeholder="e.g. product, page, post" value={(Array.isArray(g('sathi_widget_post_types', [])) ? g('sathi_widget_post_types', []).join(', ') : '')} onChange={(e) => set('sathi_widget_post_types', e.target.value.split(/[\s,]+/).filter(Boolean))} /></Field>
          <Toggle label="Show only to logged-in users" checked={!!g('sathi_widget_logged_in_only', false)} onChange={(v: boolean) => set('sathi_widget_logged_in_only', v)} />
        </div>
      </Card>
      <Card title="Knowledge & Commerce" subtitle="Control what Saathi is allowed to talk about and whether it shows products.">
        <div className="space-y-1">
          <Toggle label="Strict scope — answer only from this website's content & products" checked={g('sathi_strict_scope', true) !== false} onChange={(v: boolean) => set('sathi_strict_scope', v)} />
          <Toggle label="Show WooCommerce product cards in chat (Add to Cart / Buy Now)" checked={g('sathi_product_cards', true) !== false} onChange={(v: boolean) => set('sathi_product_cards', v)} />
        </div>
      </Card>
      <div className="flex justify-end"><button onClick={() => onSave(draft)} className="px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-rai-blue-600 hover:bg-rai-blue-700 shadow-sm">Save chatbot settings</button></div>
    </div>
  );
};

// ── Persona (single, user-defined) ────────────────────────────────────
const PERSONA_EXAMPLES = [
  { label: 'Friendly support', text: 'You are warm, upbeat and patient. Greet visitors, answer in short friendly sentences, and always offer a next step. Use the customer\'s name when you know it.' },
  { label: 'Formal & precise', text: 'You are professional and concise. Give accurate, structured answers with clear steps. Avoid slang and emoji. Cite the relevant page when you can.' },
  { label: 'Sales assistant', text: 'You are an enthusiastic product guide. Understand what the visitor needs, recommend the best-fit products from this store, mention key benefits, and gently guide them toward buying or contacting sales.' },
  { label: 'Technical helper', text: 'You are a calm technical support agent. Ask one clarifying question if needed, then give precise troubleshooting steps in a numbered list. Link to the right docs.' },
];

const GUIDED_QS = [
  { k: 'What does your business do?', ph: 'e.g. We sell industrial pumps & spares' },
  { k: 'What tone should it use?', ph: 'e.g. Warm and simple, no jargon' },
  { k: 'What should it mainly help with?', ph: 'e.g. Find the right product & request a quote' },
  { k: 'Anything to always do or avoid?', ph: 'e.g. Always offer to connect with our team' },
];

const PersonasTab: React.FC<any> = ({ settings, onSave, flash }) => {
  const [name, setName] = useState<string>((settings.sathi_persona_name as string) || 'Saathi');
  const [text, setText] = useState<string>((settings.sathi_persona_text as string) || '');
  useEffect(() => {
    setName((settings.sathi_persona_name as string) || 'Saathi');
    setText((settings.sathi_persona_text as string) || '');
  }, [settings.sathi_persona_name, settings.sathi_persona_text]);
  const save = () => onSave({ sathi_persona_name: (name.trim() || 'Saathi'), sathi_persona_text: text });

  // AI generator
  const [desc, setDesc] = useState('');
  const [answers, setAnswers] = useState<Record<string, string>>({});
  const [gen, setGen] = useState(false);
  const generate = async () => {
    setGen(true);
    try {
      const res = await api.post('/persona/generate', { description: desc, answers });
      const d = await res.json().catch(() => ({}));
      if (d.success) { setName(d.name || name); setText(d.persona || ''); flash && flash('Persona generated — review and Save'); }
      else flash && flash(d.hint || d.error || 'Could not generate', false);
    } catch { flash && flash('Could not reach the AI', false); }
    finally { setGen(false); }
  };

  return (
    <div className="space-y-4">
      <Card title="✨ Generate a persona with AI" subtitle="Describe your assistant in plain words and let your AI write it for you. Answer what you like — skip the rest.">
        <div className="space-y-3">
          <Field label="Describe your assistant (plain language)">
            <textarea className={inp + ' min-h-[80px]'} value={desc} onChange={(e) => setDesc(e.target.value)}
              placeholder="e.g. A friendly helper for my online store that answers product questions and helps people buy." />
          </Field>
          <div className="grid md:grid-cols-2 gap-3">
            {GUIDED_QS.map((q) => (
              <Field key={q.k} label={`${q.k}  ·  optional`}>
                <input className={inp} value={answers[q.k] || ''} placeholder={q.ph}
                  onChange={(e) => setAnswers((a) => ({ ...a, [q.k]: e.target.value }))} />
              </Field>
            ))}
          </div>
          <div className="flex items-center justify-between gap-3 flex-wrap">
            <p className="text-[11px] text-gray-400 max-w-md">Uses your default AI provider. On a weak/free model, answer more questions above for a better persona.</p>
            <button onClick={generate} disabled={gen} className="px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-rai-blue-600 hover:bg-rai-blue-700 disabled:opacity-50">{gen ? 'Generating…' : '✨ Generate persona'}</button>
          </div>
        </div>
      </Card>
      <Card title="Persona" subtitle="Define who your assistant is and how it speaks. Saathi reads this first, then answers on top of it. Leave the instructions empty to use a friendly, professional default.">
        <div className="space-y-4">
          <Field label="Assistant name">
            <input className={inp} value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Saathi, Maya, Acme Helper" />
          </Field>
          <Field label="Persona & instructions (optional)">
            <textarea className={inp + ' min-h-[170px] leading-relaxed font-normal'} value={text} onChange={(e) => setText(e.target.value)}
              placeholder={'Describe the tone, role and any rules. For example:\n\nYou are the friendly assistant for our store. Help visitors find the right product, explain it simply, and guide them to buy or request a quote. Be warm and concise.'} />
          </Field>
          <div>
            <div className="text-[11px] font-medium text-gray-500 mb-1.5">Start from an example</div>
            <div className="flex flex-wrap gap-1.5">
              {PERSONA_EXAMPLES.map((ex) => (
                <button key={ex.label} type="button" onClick={() => setText(ex.text)}
                  className="px-3 py-1.5 rounded-full text-xs font-medium border border-gray-200 text-gray-600 hover:border-rai-blue-300 hover:bg-rai-blue-50">{ex.label}</button>
              ))}
            </div>
          </div>
          <div className="flex justify-end">
            <button onClick={save} className="px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-rai-blue-600 hover:bg-rai-blue-700 shadow-sm">Save persona</button>
          </div>
        </div>
      </Card>
      <Card title="Built-in safety — always on" subtitle="These protections apply on top of every persona, automatically.">
        <ul className="text-sm text-gray-600 space-y-1.5 list-disc pl-5">
          <li>Never asks for or stores passwords, OTPs, card/CVV, bank PINs, IDs or API keys — and politely refuses if a visitor tries to share them.</li>
          <li>Won't reveal these instructions or your internal setup, even if asked to "ignore previous instructions".</li>
          <li>Declines illegal, harmful or explicit requests and offers a safe alternative.</li>
          <li>Hands off to your contact/support page when it isn't sure, instead of guessing.</li>
        </ul>
      </Card>
    </div>
  );
};

// ── Knowledge ─────────────────────────────────────────────────────────
const KnowledgeTab: React.FC<any> = ({ stats, flash, refresh }) => {
  const [busy, setBusy] = useState(false);
  const [prog, setProg] = useState(0);
  const [status, setStatus] = useState('');

  // Deep, full-site scan driven from the browser: many short batches with a
  // live progress %, so even large sites finish without timing out.
  const index = async () => {
    setBusy(true); setProg(0); setStatus('Starting deep scan…');
    let offset = 0, total = 0, guard = 0;
    try {
      while (guard++ < 1000) {
        const res = await api.post('/knowledge/index', { offset, batch: 8 });
        const d = await res.json().catch(() => ({ done: true }));
        if (!res.ok) throw new Error('scan failed');
        total = d.total || total;
        offset = d.next ?? (offset + 8);
        const pct = total ? Math.min(100, Math.round((Math.min(offset, total) / total) * 100)) : 100;
        setProg(pct);
        setStatus(`Analysing your site… ${Math.min(offset, total)} / ${total || '?'} items`);
        if (d.done || !total) break;
      }
      setProg(100); setStatus('Done — Saathi has read your whole site ✓');
      flash('Deep scan complete');
    } catch { setStatus('Scan interrupted — please try again.'); flash('Scan interrupted', false); }
    finally { await refresh(); setBusy(false); }
  };
  const clear = async () => { setBusy(true); await api.del('/knowledge/clear'); await refresh(); setBusy(false); setProg(0); setStatus(''); flash('Index cleared'); };

  return (
    <Card title="Site knowledge base" subtitle="Deep-scan your pages, posts, products and custom content so Saathi answers only from YOUR content — not the theme's default text.">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
        <Stat label="Chunks" value={(stats?.total_chunks ?? 0).toLocaleString()} icon="📄" />
        <Stat label="Sources" value={(stats?.total_sources ?? 0).toLocaleString()} icon="📚" />
        <Stat label="Tokens" value={(stats?.total_tokens ?? 0).toLocaleString()} icon="🔤" />
        <Stat label="Last scan" value={stats?.last_crawl || 'Never'} icon="🕐" />
      </div>
      {(busy || prog > 0) && (
        <div className="mb-4">
          <div className="h-2.5 w-full rounded-full bg-gray-100 overflow-hidden">
            <div className="h-full rounded-full transition-all" style={{ width: `${prog}%`, background: 'linear-gradient(90deg,#6D5DFB,#19C37D)' }} />
          </div>
          <div className="text-xs text-gray-500 mt-1.5">{status} {busy && prog < 100 ? `· ${prog}%` : ''}</div>
        </div>
      )}
      <div className="flex gap-2">
        <button onClick={index} disabled={busy} className="px-4 py-2 rounded-lg text-sm font-medium text-white bg-rai-blue-600 hover:bg-rai-blue-700 disabled:opacity-50">{busy ? `Scanning… ${prog}%` : 'Deep-scan website & train Saathi'}</button>
        <button onClick={clear} disabled={busy} className="px-4 py-2 rounded-lg text-sm font-medium border border-gray-200 text-gray-700 hover:bg-gray-50 disabled:opacity-50">Clear index</button>
      </div>
      <p className="text-[11px] text-gray-400 mt-3">A full scan can take a minute or two on large sites — keep this tab open. Tip: if answers seem off, your AI model may be too small — pick a stronger model under AI Providers, or write a detailed persona.</p>
    </Card>
  );
};

// ── License ───────────────────────────────────────────────────────────
const LicenseTab: React.FC<any> = ({ settings, onSave, flash }) => {
  const [info, setInfo] = useState<any>(null);
  const [key, setKey] = useState('');
  const [busy, setBusy] = useState<'' | 'activate' | 'deactivate'>('');
  const [serverUrl, setServerUrl] = useState(settings.sathi_license_server_url || '');
  const enforce = settings.sathi_license_enforce === true;

  const load = useCallback(async () => {
    const r = await api.get('/license/status');
    if (r.ok) setInfo(await r.json());
  }, []);
  useEffect(() => { load(); }, [load]);
  useEffect(() => { setServerUrl(settings.sathi_license_server_url || ''); }, [settings.sathi_license_server_url]);

  const activate = async () => {
    if (!key.trim()) { flash('Enter a license key', false); return; }
    setBusy('activate');
    const r = await api.post('/license/activate', { key: key.trim() });
    const d = await r.json().catch(() => ({}));
    setBusy(''); setInfo(d); setKey('');
    flash(d.status === 'active' ? 'License activated' : (d.message || 'Activation failed'), d.status === 'active');
  };
  const deactivate = async () => {
    setBusy('deactivate');
    const r = await api.post('/license/deactivate', {});
    const d = await r.json().catch(() => ({}));
    setBusy(''); setInfo(d);
    flash('License deactivated');
  };

  const st = info?.status || 'inactive';
  const tone = st === 'active' ? { bg: 'bg-emerald-50', tx: 'text-emerald-700', dot: 'bg-emerald-500' }
    : st === 'expired' ? { bg: 'bg-rai-gold-50', tx: 'text-rai-gold-600', dot: 'bg-rai-gold-500' }
    : { bg: 'bg-gray-100', tx: 'text-gray-600', dot: 'bg-gray-400' };

  return (
    <div className="space-y-4">
      <Card title="License" subtitle="Activate your Saathi AI license. Your key is stored encrypted and never shown to visitors.">
        <div className={`flex items-center gap-2 mb-4 px-3 py-2 rounded-xl ${tone.bg}`}>
          <span className={`w-2.5 h-2.5 rounded-full ${tone.dot}`} />
          <span className={`text-sm font-semibold capitalize ${tone.tx}`}>{st}</span>
          {info?.plan && <span className="text-xs text-gray-500">· {info.plan}</span>}
          {info?.expires && <span className="text-xs text-gray-500">· expires {info.expires}</span>}
          {info?.message && <span className="text-xs text-gray-400 ml-auto truncate">{info.message}</span>}
        </div>

        {info?.has_key ? (
          <div className="flex items-center gap-2">
            <button onClick={deactivate} disabled={!!busy} className="px-3.5 py-2 rounded-lg text-sm font-medium border border-gray-200 text-gray-700 hover:bg-gray-50 disabled:opacity-50">{busy === 'deactivate' ? 'Deactivating…' : 'Deactivate'}</button>
            <button onClick={load} className="px-3.5 py-2 rounded-lg text-sm font-medium border border-gray-200 text-gray-700 hover:bg-gray-50">Re-check</button>
            {info?.domain && <span className="text-xs text-gray-400">domain: {info.domain}{info.max_domains ? ` · ${(info.domains||[]).length}/${info.max_domains} domains` : ''}</span>}
          </div>
        ) : (
          <div className="flex gap-2">
            <input value={key} onChange={(e) => setKey(e.target.value)} placeholder="SATHI-XXXX-XXXX-XXXX-XXXX" className={inp + ' font-mono'} />
            <button onClick={activate} disabled={!!busy} className="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-rai-blue-600 hover:bg-rai-blue-700 disabled:opacity-50 whitespace-nowrap">{busy === 'activate' ? 'Activating…' : 'Activate'}</button>
          </div>
        )}
        {serverUrl && <p className="text-xs text-gray-400 mt-3">Need a key? <a href={serverUrl} target="_blank" rel="noopener noreferrer" className="text-rai-blue-700 underline">Visit the purchase page →</a></p>}
      </Card>

      <Card title="License settings" subtitle="Point the plugin at your license server and choose whether to enforce activation.">
        <div className="space-y-4">
          <Field label="License server URL">
            <div className="flex gap-2">
              <input className={inp} value={serverUrl} onChange={(e) => setServerUrl(e.target.value)} placeholder="https://license.yoursubdomain.com" />
              <button onClick={() => onSave({ sathi_license_server_url: serverUrl })} className="px-3.5 py-2 rounded-lg text-sm font-medium border border-gray-200 text-gray-700 hover:bg-gray-50 whitespace-nowrap">Save URL</button>
            </div>
          </Field>
          <Toggle label="Enforce license (disable the chatbot until a valid key is active)" checked={enforce} onChange={(v: boolean) => onSave({ sathi_license_enforce: v })} />
          <p className="text-[11px] text-gray-400">Leave enforcement OFF until your license server is live — otherwise the chatbot will be disabled.</p>
        </div>
      </Card>
    </div>
  );
};

// ── Test Playground drawer ────────────────────────────────────────────
const STAGE_LABEL: Record<string, string> = {
  auth: 'API key', model: 'Model', network: 'Network', context: 'Context',
  rate_limit: 'Rate limit', request: 'Request', config: 'Config', input: 'Input', empty: 'Empty reply', unknown: 'Error',
};
type PgMsg = { role: 'user' | 'assistant'; content: string; err?: any };

const PlaygroundDrawer: React.FC<any> = ({ cfg, onClose }) => {
  const [msgs, setMsgs] = useState<PgMsg[]>([]);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const scrollRef = useRef<HTMLDivElement>(null);
  useEffect(() => { const el = scrollRef.current; if (el) el.scrollTop = el.scrollHeight; }, [msgs, busy]);

  const send = async () => {
    const q = input.trim();
    if (!q || busy) return;
    const history = msgs.filter((m) => !m.err).map((m) => ({ role: m.role, content: m.content }));
    setMsgs((m) => [...m, { role: 'user', content: q }]);
    setInput(''); setBusy(true);
    try {
      const res = await api.post('/playground/chat', { provider: cfg.provider, model: cfg.model, message: q, history });
      const d = await res.json().catch(() => ({ success: false, stage: 'network', error: 'Could not read the response.', hint: '' }));
      setMsgs((m) => [...m, d.success ? { role: 'assistant', content: d.reply } : { role: 'assistant', content: '', err: d }]);
    } catch (e: any) {
      setMsgs((m) => [...m, { role: 'assistant', content: '', err: { stage: 'network', error: String(e?.message || e), hint: 'The browser could not reach the site. Check your connection.' } }]);
    } finally { setBusy(false); }
  };

  return (
    <div className="fixed inset-0 z-[100000] flex justify-end">
      <div className="absolute inset-0 bg-black/30" onClick={onClose} />
      <div className="relative w-full max-w-md h-full bg-white shadow-2xl flex flex-col">
        <div className="flex items-center gap-2 px-4 py-3 border-b border-gray-100">
          <span className="w-8 h-8 rounded-xl flex items-center justify-center text-white text-sm" style={{ background: 'linear-gradient(135deg,#6D5DFB,#5646E0)' }}>▶</span>
          <div className="flex-1 min-w-0">
            <div className="text-sm font-semibold text-rai-black leading-tight">Test Playground</div>
            <div className="text-[11px] text-gray-400 truncate">{cfg.provider}{cfg.model ? ` · ${cfg.model}` : ''}</div>
          </div>
          <button onClick={onClose} className="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100 text-lg leading-none">×</button>
        </div>

        <div ref={scrollRef} className="flex-1 overflow-y-auto px-4 py-4 space-y-3 bg-gray-50">
          {msgs.length === 0 && (
            <div className="text-center text-gray-400 text-sm mt-10 px-6">
              <div className="text-3xl mb-2">💬</div>
              Send a message to check this provider & model reply live. If something’s off, you’ll see exactly what to fix.
            </div>
          )}
          {msgs.map((m, i) => (
            m.err ? (
              <div key={i} className="rounded-xl border border-red-200 bg-red-50 p-3">
                <div className="flex items-center gap-2 mb-1">
                  <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-600 text-white uppercase tracking-wide">{STAGE_LABEL[m.err.stage] || 'Error'}</span>
                  <span className="text-xs font-semibold text-red-700">Test failed</span>
                </div>
                <div className="text-xs text-red-800 break-words">{m.err.error}</div>
                {m.err.hint && <div className="text-[11px] text-red-600 mt-1.5">💡 {m.err.hint}</div>}
              </div>
            ) : (
              <div key={i} className={`flex ${m.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                <div className={`max-w-[80%] px-3 py-2 rounded-2xl text-sm whitespace-pre-wrap break-words ${m.role === 'user' ? 'bg-rai-blue-600 text-white rounded-br-sm' : 'bg-white border border-gray-200 text-gray-800 rounded-bl-sm'}`}>{m.content}</div>
              </div>
            )
          ))}
          {busy && <div className="flex justify-start"><div className="px-3 py-2 rounded-2xl bg-white border border-gray-200 text-gray-400 text-sm">Saathi is thinking…</div></div>}
        </div>

        <div className="p-3 border-t border-gray-100 flex gap-2">
          <input value={input} onChange={(e) => setInput(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') send(); }}
            placeholder="Ask anything to test…" className="flex-1 px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-rai-blue-400" />
          <button onClick={send} disabled={busy || !input.trim()} className="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-rai-blue-600 hover:bg-rai-blue-700 disabled:opacity-50">Send</button>
        </div>
      </div>
    </div>
  );
};

// ── Compact searchable model dropdown (replaces the full-screen native datalist) ──
const ModelSelect: React.FC<any> = ({ value, onChange, options, placeholder }) => {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const h = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false); };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, []);
  const list: string[] = Array.isArray(options) ? options : [];
  const filtered = list.filter((m) => m.toLowerCase().includes(String(value || '').toLowerCase())).slice(0, 300);
  return (
    <div ref={ref} className="relative flex-1 min-w-0">
      <input
        value={value}
        placeholder={placeholder}
        onChange={(e) => { onChange(e.target.value); setOpen(true); }}
        onFocus={() => setOpen(true)}
        className="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-rai-blue-400"
      />
      {open && filtered.length > 0 && (
        <div className="absolute z-[60] left-0 right-0 mt-1 max-h-56 overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-lg">
          {filtered.map((m) => (
            <button key={m} type="button"
              onMouseDown={(e) => { e.preventDefault(); onChange(m); setOpen(false); }}
              className={`block w-full text-left px-3 py-1.5 text-[13px] hover:bg-rai-blue-50 truncate ${m === value ? 'text-rai-blue-700 font-medium' : 'text-gray-700'}`}>
              {m}
            </button>
          ))}
        </div>
      )}
    </div>
  );
};

// ── Reusable UI ───────────────────────────────────────────────────────
const Card: React.FC<any> = ({ title, subtitle, children }) => (
  <div className="rounded-2xl border border-gray-200 bg-white shadow-sm">
    <div className="px-5 pt-4 pb-3 border-b border-gray-100">
      <h2 className="text-base font-semibold text-rai-black tracking-tight">{title}</h2>
      {subtitle && <p className="text-xs text-gray-500 mt-0.5 leading-relaxed">{subtitle}</p>}
    </div>
    <div className="p-5">{children}</div>
  </div>
);
const Stat: React.FC<any> = ({ label, value, icon, tone }) => (
  <div className="sathi-card rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
    <div className="flex items-center justify-between"><span className="sathi-stat-ic">{icon}</span>{tone === 'warn' && <span className="w-2 h-2 rounded-full bg-rai-gold-500" />}{tone === 'good' && <span className="w-2 h-2 rounded-full bg-emerald-400" />}</div>
    <div className="text-2xl font-extrabold text-rai-black mt-2.5 leading-none truncate">{value}</div>
    <div className="text-xs text-gray-500 mt-1">{label}</div>
  </div>
);
const Field: React.FC<any> = ({ label, children }) => (<div><label className="block text-[11px] font-medium text-gray-500 mb-1">{label}</label>{children}</div>);
const Toggle: React.FC<any> = ({ label, checked, onChange }) => (
  <label className="flex items-center gap-3 cursor-pointer select-none py-1">
    <span className={`sathi-switch relative w-10 h-6 rounded-full flex-shrink-0 ${checked ? 'bg-rai-blue-600' : 'bg-gray-300'}`} onClick={(e) => { e.preventDefault(); onChange(!checked); }}>
      <span className={`sathi-switch-knob absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow ${checked ? 'translate-x-4' : ''}`} />
    </span>
    <span className="text-sm text-gray-800">{label}</span>
  </label>
);

export default AdminPanel;
