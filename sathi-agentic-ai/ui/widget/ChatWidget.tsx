import React, { useEffect, useRef, useState, useCallback, Suspense, lazy } from 'react';
import ReactMarkdown from 'react-markdown';
import { useChatStore, config, Message, ClientAction } from './store';
import ThemeCustomizer from './ThemeCustomizer';
import AnimatedAvatar from './AnimatedAvatar';

// Strip a reasoning model's chain-of-thought (<think>…</think> etc.) so only
// the final answer is shown. Mirrors the server-side Helpers::strip_reasoning
// as a display-time safety net (covers live-streamed tokens too).
function stripThink(text: string): string {
  if (!text) return text;
  let t = text
    .replace(/◁think▷/gi, '<think>').replace(/◁\/think▷/gi, '</think>')
    .replace(/<\s*think(?:ing)?\s*>[\s\S]*?<\s*\/\s*think(?:ing)?\s*>/gi, '');
  if (/<\s*\/\s*think(?:ing)?\s*>/i.test(t)) t = t.replace(/^[\s\S]*?<\s*\/\s*think(?:ing)?\s*>/i, '');
  t = t.replace(/<\s*think(?:ing)?\s*>[\s\S]*$/i, '');
  t = t.replace(/<\s*\/?\s*think(?:ing)?\s*>/gi, '');
  return t.trim();
}

// Parse an optional <followups> block the model appends at the very end of a
// reply: a short question + a few tappable options. Returns the cleaned text
// (block removed, so it never shows as raw markup — even mid-stream) and, when
// available, the parsed { question, options }. While the block is still
// streaming we already hide it from the text but don't render chips until it
// closes, so options never flicker in half-typed.
interface Followups { question: string; options: string[]; }
function parseFollowups(text: string): { clean: string; followups: Followups | null } {
  if (!text) return { clean: text, followups: null };
  const open = text.search(/<\s*followups\s*>/i);
  if (open === -1) return { clean: text, followups: null };

  const clean = text.slice(0, open).trim();
  const rest = text.slice(open);
  const m = rest.match(/<\s*followups\s*>([\s\S]*?)<\s*\/\s*followups\s*>/i);
  if (!m) {
    // Block opened but not closed yet (still streaming) — hide it, no chips yet.
    return { clean, followups: null };
  }
  const lines = m[1].split('\n').map((l) => l.trim()).filter(Boolean);
  let question = '';
  const options: string[] = [];
  for (const l of lines) {
    if (/^[-•*]\s+/.test(l)) options.push(l.replace(/^[-•*]\s+/, '').trim());
    else if (/^\d+[.)]\s+/.test(l)) options.push(l.replace(/^\d+[.)]\s+/, '').trim());
    else if (!question) question = l.replace(/[:：]\s*$/, '');
    else options.push(l);
  }
  const cleaned = options.filter(Boolean).slice(0, 5);
  if (cleaned.length === 0) return { clean, followups: null };
  return { clean, followups: { question, options: cleaned } };
}

// ═══════════════════════════════════════════════════════════════════════
// ChatWidget — Enhanced with full accessibility, dark mode, loading
// skeleton, screen reader announcements, and keyboard navigation.
// ═══════════════════════════════════════════════════════════════════

// ── Lazy-loaded syntax highlighter ──────────────────────────────────────
const SyntaxHighlighter = lazy(() =>
  import('react-syntax-highlighter').then((m) => ({
    default: m.Prism,
  }))
);
const oneDarkPromise = import('react-syntax-highlighter/dist/esm/styles/prism/one-dark').then(
  (m: any) => m.oneDark
);

// ── Code Block (lazy syntax highlighting) ──────────────────────────────

const CodeBlock: React.FC<{ language: string; children: string }> = ({
  language,
  children,
}) => {
  const [style, setStyle] = useState<any>(null);

  useEffect(() => {
    oneDarkPromise.then(setStyle);
  }, []);

  return (
    <Suspense
      fallback={
        <pre className="bg-gray-900 text-gray-400 p-3 rounded-lg text-xs overflow-auto" aria-label={`Code block in ${language}`}>
          <code>{children}</code>
        </pre>
      }
    >
      {style ? (
        <SyntaxHighlighter
          style={style}
          language={language}
          PreTag="div"
          aria-label={`Code block in ${language}`}
        >
          {children.replace(/\n$/, '')}
        </SyntaxHighlighter>
      ) : (
        <pre className="bg-gray-900 text-gray-400 p-3 rounded-lg text-xs overflow-auto" aria-label={`Code block in ${language}`}>
          <code>{children}</code>
        </pre>
      )}
    </Suspense>
  );
};

// ── Screen Reader Announcer ─────────────────────────────────────────────

const SrAnnouncer: React.FC<{ message: string; clearAfter?: number }> = ({
  message,
  clearAfter = 0,
}) => {
  const [text, setText] = useState(message);
  const timerRef = useRef<ReturnType<typeof setTimeout>>();

  useEffect(() => {
    setText(message);
    if (clearAfter > 0) {
      if (timerRef.current) clearTimeout(timerRef.current);
      timerRef.current = setTimeout(() => setText(''), clearAfter);
    }
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, [message, clearAfter]);

  return (
    <div
      className="sathi-sr-only"
      role="status"
      aria-live="polite"
      aria-atomic="true"
    >
      {text}
    </div>
  );
};

// ── Loading Skeleton ────────────────────────────────────────────────────

const LoadingSkeleton: React.FC = () => {
  const skeletonMessages = [
    { lines: 2 },
    { lines: 1, short: true },
    { lines: 3 },
    { lines: 2 },
  ];

  return (
    <div role="status" aria-label="Loading chat history" className="px-3 py-4">
      {skeletonMessages.map((sk, idx) => (
        <div key={idx} className="sathi-skeleton-message">
          {idx % 2 === 0 && <div className="sathi-skeleton-avatar" />}
          <div className="sathi-skeleton-bubble" style={{ flex: 1 }}>
            {Array.from({ length: sk.lines }).map((_, li) => (
              <div
                key={li}
                className={`sathi-skeleton sathi-skeleton-line ${
                  sk.short && li === sk.lines - 1 ? 'sathi-skeleton-line-short' : ''
                }`}
              />
            ))}
          </div>
        </div>
      ))}
      <span className="sathi-sr-only">Loading conversation, please wait.</span>
    </div>
  );
};

// ── Loading Dots (while streaming) ──────────────────────────────────────

const THINKING_WORDS = ['Thinking…', 'Looking that up…', 'Checking the site…', 'Putting it together…', 'Almost there…'];
const LoadingDots: React.FC = () => {
  const [i, setI] = useState(0);
  useEffect(() => {
    const reduce = typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) return;
    const t = setInterval(() => setI((p) => (p + 1) % THINKING_WORDS.length), 1600);
    return () => clearInterval(t);
  }, []);
  const name = (config.persona && config.persona.name) || 'Saathi';
  return (
    <div className="sathi-message flex gap-2 mb-4" role="status" aria-label="Assistant is typing">
      <div className="sathi-msg-avatar">
        {config.avatar ? <AnimatedAvatar frames={config.avatarFrames} fallback={config.avatar} size={28} style={{ width: '100%', height: '100%' }} /> : (config.persona?.avatar || '🤖')}
      </div>
      <div className="sathi-bubble sathi-bubble-assistant" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <div className="sathi-loading-dots">
          <div className="sathi-loading-dot" />
          <div className="sathi-loading-dot" />
          <div className="sathi-loading-dot" />
        </div>
        <span style={{ fontSize: 12.5, color: '#8a86a3' }}>{i === 0 ? `${name} is thinking…` : THINKING_WORDS[i]}</span>
      </div>
    </div>
  );
};

// ── Header ──────────────────────────────────────────────────────────────

interface HeaderProps {
  onSidebarToggle: () => void;
}

/** Quick dark-mode cycler that reads/writes the same localStorage key as ThemeCustomizer */
function cycleDarkModeQuick(): void {
  try {
    const raw = localStorage.getItem('sathi_theme_settings');
    const current = raw ? JSON.parse(raw) : { darkMode: 'auto' };
    const modes = ['auto', 'dark', 'light'];
    const idx = modes.indexOf(current.darkMode || 'auto');
    const next = modes[(idx + 1) % 3];
    current.darkMode = next;
    localStorage.setItem('sathi_theme_settings', JSON.stringify(current));

    const root = document.querySelector<HTMLElement>('.sathi-floating-root');
    if (root) {
      root.classList.remove('sathi-dark', 'sathi-light');
      if (next === 'dark') root.classList.add('sathi-dark');
      else if (next === 'light') root.classList.add('sathi-light');
    }
  } catch { /* ignore */ }
}

/** Read current dark mode from localStorage for the icon */
function getDarkModeState(): 'auto' | 'dark' | 'light' {
  try {
    const raw = localStorage.getItem('sathi_theme_settings');
    return raw ? JSON.parse(raw).darkMode || 'auto' : 'auto';
  } catch {
    return 'auto';
  }
}

const ChatHeader: React.FC<HeaderProps> = ({ onSidebarToggle }) => {
  const { persona, clearMessages, toggle } = useChatStore();
  const [darkState, setDarkState] = useState<'auto' | 'dark' | 'light'>(getDarkModeState);
  const p = persona || (config.persona as any) || {
    name: 'Saathi',
    avatar: '🤖',
    color: '#6D5DFB',
  };

  // Listen for storage changes so icon stays in sync with ThemeCustomizer
  useEffect(() => {
    const onStorage = () => setDarkState(getDarkModeState());
    window.addEventListener('storage', onStorage);
    // Also listen for custom event from ThemeCustomizer (same-page sync)
    document.addEventListener('sathi:theme-changed', onStorage);
    return () => {
      window.removeEventListener('storage', onStorage);
      document.removeEventListener('sathi:theme-changed', onStorage);
    };
  }, []);

  const handleDarkModeToggle = () => {
    cycleDarkModeQuick();
    setDarkState(getDarkModeState());
  };

  return (
    <div
      className="sathi-header flex items-center gap-2.5 px-3.5 py-3 flex-shrink-0"
      style={{
        background: `linear-gradient(135deg, ${p.color}, ${p.color}dd)`,
      }}
      role="banner"
      aria-label="Chat header"
    >
      {/* Avatar — the mascot, no background */}
      <div className="sathi-avatar" aria-hidden="true" style={{ overflow: 'hidden' }}>
        {config.avatar ? <AnimatedAvatar frames={config.avatarFrames} fallback={config.avatar} size={40} style={{ width: '100%', height: '100%' }} /> : (p.avatar || '🤖')}
      </div>

      {/* Title + status */}
      <div className="flex-1 min-w-0">
        <div className="text-white font-semibold text-sm leading-tight truncate" id="sathi-header-title">
          {config.title || p.name || 'Saathi'}
        </div>
        <div className="text-white/85 text-[11px] flex items-center gap-1.5">
          <span style={{ width: 6, height: 6, borderRadius: '50%', background: '#7CF0BD', display: 'inline-block' }} />
          {config.i18n?.online || 'Online'}
        </div>
      </div>

      {/* New chat */}
      <button
        className="sathi-header-btn"
        title={config.i18n?.newChat || 'New chat'}
        aria-label={config.i18n?.newChat || 'Start a new chat'}
        onClick={clearMessages}
      >
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
          <path d="M12 5v14M5 12h14" />
        </svg>
      </button>

      {/* Close */}
      <button
        className="sathi-header-btn"
        title="Close"
        aria-label="Close chat"
        onClick={() => toggle()}
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
          <path d="M18 6L6 18M6 6l12 12" />
        </svg>
      </button>
    </div>
  );
};

// ── Message Bubble ──────────────────────────────────────────────────────

interface BubbleProps {
  message: Message;
  isLastAssistant?: boolean;
  onCopy: (text: string) => void;
  onRegenerate?: () => void;
  copiedId: string | null;
}

const MessageBubble: React.FC<BubbleProps> = ({
  message,
  isLastAssistant,
  onCopy,
  onRegenerate,
  copiedId,
}) => {
  const isUser = message.role === 'user';
  const [feedback, setFeedback] = useState<'up' | 'down' | null>(null);
  const messageRef = useRef<HTMLDivElement>(null);

  // Assistant replies may carry a trailing <followups> block — strip it from
  // the visible text and render it as tappable options instead.
  const parsed = !isUser
    ? parseFollowups(stripThink(message.content))
    : { clean: message.content, followups: null as Followups | null };

  const submitFeedback = useCallback(
    async (rating: 'up' | 'down') => {
      setFeedback(rating);
      try {
        await fetch(`${config.restUrl}/chat/feedback`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': config.nonce,
          },
          body: JSON.stringify({
            message_id: message.id,
            rating,
            conversation_id: useChatStore.getState().conversationId,
          }),
        });
      } catch {
        // Fire-and-forget
      }
    },
    [message.id]
  );

  return (
    <div
      ref={messageRef}
      className={`sathi-message flex gap-2 mb-4 ${isUser ? 'flex-row-reverse' : ''}`}
      role="listitem"
      aria-label={isUser ? 'Your message' : `${config.persona?.name || 'Assistant'} message`}
    >
      {/* Avatar (assistant only) */}
      {!isUser && (
        <div
          className="sathi-msg-avatar"
          aria-hidden="true"
        >
          {config.avatar ? <img src={config.avatar} alt="" style={{ width: '100%', height: '100%', objectFit: 'contain' }} /> : (config.persona?.avatar || '🤖')}
        </div>
      )}

      <div
        className={`sathi-bubble-group max-w-[82%] ${isUser ? 'items-end' : ''}`}
      >
        {/* Bubble */}
        <div
          className={`sathi-bubble ${
            isUser ? 'sathi-bubble-user' : 'sathi-bubble-assistant'
          }`}
        >
          {isUser ? (
            <p className="text-sm leading-relaxed whitespace-pre-wrap break-words">
              {message.content}
            </p>
          ) : (
            <div className="sathi-markdown text-sm leading-relaxed">
              <ReactMarkdown
                components={{
                  code({ node, className, children, ...props }) {
                    const match = /language-(\w+)/.exec(className || '');
                    const inline = !match;
                    return !inline ? (
                      <CodeBlock language={match![1]}>
                        {String(children)}
                      </CodeBlock>
                    ) : (
                      <code className={className} {...props}>
                        {children}
                      </code>
                    );
                  },
                }}
              >
                {parsed.clean}
              </ReactMarkdown>
            </div>
          )}

          {/* Client actions */}
          {message.actions && message.actions.length > 0 && (
            <div className="mt-2 flex flex-wrap gap-1.5" role="group" aria-label="Available actions">
              {message.actions.map((action, i) => (
                <ActionButton key={i} action={action} />
              ))}
            </div>
          )}
        </div>

        {/* Message action bar (assistant only) */}
        {!isUser && (
          <div
            className="sathi-msg-actions"
            role="toolbar"
            aria-label="Message actions"
          >
            {/* Copy */}
            <button
              className="sathi-msg-action-btn"
              onClick={() => onCopy(parsed.clean || message.content)}
              title={config.i18n?.copy || 'Copy'}
              aria-label={
                copiedId === message.id
                  ? 'Copied to clipboard'
                  : 'Copy message to clipboard'
              }
            >
              {copiedId === message.id ? (
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                  <path d="M20 6L9 17l-5-5" />
                </svg>
              ) : (
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                  <rect x="9" y="9" width="13" height="13" rx="2" />
                  <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" />
                </svg>
              )}
            </button>

            {/* Thumbs up */}
            <button
              className={`sathi-msg-action-btn ${
                feedback === 'up' ? 'text-green-500' : ''
              }`}
              onClick={() => submitFeedback('up')}
              title="Helpful"
              aria-label="Mark as helpful"
              aria-pressed={feedback === 'up'}
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill={feedback === 'up' ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth="2" aria-hidden="true">
                <path d="M7 22V11M2 13v7a2 2 0 002 2h12.4a2 2 0 001.94-1.52l2.1-8.4A2 2 0 0018.5 10H15V5a3 3 0 00-3-3l-4 9v11" />
              </svg>
            </button>

            {/* Thumbs down */}
            <button
              className={`sathi-msg-action-btn ${
                feedback === 'down' ? 'text-red-500' : ''
              }`}
              onClick={() => submitFeedback('down')}
              title="Not helpful"
              aria-label="Mark as not helpful"
              aria-pressed={feedback === 'down'}
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill={feedback === 'down' ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth="2" aria-hidden="true">
                <path d="M17 2v11m5-9v7a2 2 0 01-2 2H7.6a2 2 0 01-1.94-1.52l-2.1-8.4A2 2 0 015.5 2H9V7a3 3 0 013-3l4 9V2" />
              </svg>
            </button>

            {/* Regenerate (last assistant only) */}
            {isLastAssistant && onRegenerate && (
              <button
                className="sathi-msg-action-btn"
                onClick={onRegenerate}
                title="Regenerate response"
                aria-label="Regenerate this response"
              >
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                  <path d="M1 4v6h6M23 20v-6h-6" />
                  <path d="M20.49 9A9 9 0 005.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 013.51 15" />
                </svg>
              </button>
            )}
          </div>
        )}

        {/* WooCommerce product cards (assistant only) */}
        {!isUser && message.products && message.products.length > 0 && (
          <ProductCards products={message.products} />
        )}

        {/* Follow-up question options — only on the most recent reply, so the
            chat always offers the latest next steps without piling up. */}
        {!isUser && isLastAssistant && parsed.followups && (
          <FollowUpQuestions data={parsed.followups} />
        )}
      </div>
    </div>
  );
};

// ── Follow-up question options ──────────────────────────────────────────
// Renders the bot's suggested next steps. Short option sets show as wrapping
// pills; longer ones show as full-width selectable rows with a radio dot.

const FollowUpQuestions: React.FC<{ data: Followups }> = ({ data }) => {
  const { setInput, isStreaming } = useChatStore();
  const accent = (config.persona?.color) || (config as any).accentColor || '#6D5DFB';

  const pick = (t: string) => {
    if (isStreaming) return;
    setInput(t);
    setTimeout(() => document.dispatchEvent(new CustomEvent('sathi:send')), 50);
  };

  const maxLen = data.options.reduce((m, o) => Math.max(m, o.length), 0);
  const compact = data.options.length >= 3 && maxLen <= 18;

  return (
    <div className="sathi-followups" style={{ ['--sathi-accent' as any]: accent }}>
      {data.question && <div className="sathi-followups-q">{data.question}</div>}
      <div className={compact ? 'sathi-followups-pills' : 'sathi-followups-rows'}>
        {data.options.map((o, i) => (
          <button
            key={i}
            type="button"
            className={compact ? 'sathi-followup-pill' : 'sathi-followup-row'}
            onClick={() => pick(o)}
            disabled={isStreaming}
          >
            {!compact && <span className="sathi-followup-dot" aria-hidden="true" />}
            <span className="sathi-followup-label">{o}</span>
          </button>
        ))}
      </div>
    </div>
  );
};

// ── Action Button ──────────────────────────────────────────────────────

const ActionButton: React.FC<{ action: ClientAction }> = ({ action }) => {
  const executeActions = useChatStore((s) => s.executeActions);
  const labels: Record<string, string> = {
    navigate: 'Open page',
    scroll_to: 'Show me',
    highlight: 'Highlight',
    focus_input: 'Start typing',
    open_contact: 'Contact us',
  };
  // No arrow/glyph icon — just a clean, human label.
  const label = (action.params && action.params.label) || labels[action.type] || action.type.replace(/_/g, ' ');

  return (
    <button
      className="sathi-action-btn"
      onClick={() => executeActions([action])}
      aria-label={label}
    >
      <span>{label}</span>
    </button>
  );
};

// ── Product Cards (WooCommerce) ─────────────────────────────────────────

// Compact star rating (accurate fractional fill via an overlaid clipped layer).
const Stars: React.FC<{ rating: number }> = ({ rating }) => {
  const pct = Math.max(0, Math.min(100, (rating / 5) * 100));
  return (
    <span className="sathi-stars" role="img" aria-label={`${rating.toFixed(1)} out of 5`}>
      <span className="sathi-stars-empty">★★★★★</span>
      <span className="sathi-stars-full" style={{ width: pct + '%' }}>★★★★★</span>
    </span>
  );
};

const fmtCount = (n: number): string =>
  n >= 1000 ? (n / 1000).toFixed(n % 1000 >= 100 ? 1 : 0).replace(/\.0$/, '') + 'k' : String(n);

const ProductCards: React.FC<{ products: any[] }> = ({ products }) => {
  const single = products.length === 1;
  return (
    <div className="sathi-products" role="list" aria-label="Matching products">
      {products.map((p) => <ProductCard key={p.id} product={p} />)}
    </div>
  );
};

const ProductCard: React.FC<{ product: any }> = ({ product }) => {
  const [status, setStatus] = useState<'' | 'adding' | 'added' | 'error'>('');
  const [fav, setFav] = useState<boolean>(() => {
    try { return JSON.parse(localStorage.getItem('sathi_wishlist') || '[]').includes(product.id); }
    catch { return false; }
  });

  const toggleFav = () => {
    setFav((prev) => {
      const next = !prev;
      try {
        const arr: number[] = JSON.parse(localStorage.getItem('sathi_wishlist') || '[]');
        const set = new Set(arr);
        if (next) set.add(product.id); else set.delete(product.id);
        localStorage.setItem('sathi_wishlist', JSON.stringify([...set]));
      } catch { /* storage unavailable */ }
      return next;
    });
  };

  const addToCart = async () => {
    if (!product.purchasable) { window.open(product.permalink, '_blank'); return; }
    setStatus('adding');
    const wcAjax = (config as any).wcAjaxUrl as string | undefined;
    try {
      // Preferred path: WooCommerce's own front-end AJAX endpoint
      // (?wc-ajax=add_to_cart). It runs in the browser with the live Woo
      // session cookie, so the product reliably lands in the visitor's real
      // cart — the REST cart route frequently can't share that session cookie.
      if (wcAjax) {
        const body = new URLSearchParams();
        body.set('product_id', String(product.id));
        body.set('quantity', '1');
        const res = await fetch(wcAjax, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'include',
          body: body.toString(),
        });
        const d = await res.json().catch(() => ({} as any));
        if (res.ok && d && !d.error && (d.fragments || d.cart_hash)) {
          setStatus('added');
          // Let the host theme refresh its cart count / mini-cart.
          try {
            const jq = (window as any).jQuery;
            if (jq) jq(document.body).trigger('added_to_cart', [d.fragments, d.cart_hash]);
            else document.body.dispatchEvent(new CustomEvent('added_to_cart', { detail: d }));
          } catch { /* non-fatal */ }
          setTimeout(() => setStatus(''), 2600);
          return;
        }
        // Variable products etc. ask Woo to redirect to the product page.
        if (d && d.error && d.product_url) { window.location.href = d.product_url; return; }
      }
      // Fallback: REST cart route (used when wcAjaxUrl is unavailable).
      const res2 = await fetch(`${config.restUrl}/cart/add`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
        credentials: 'include',
        body: JSON.stringify({ product_id: product.id, quantity: 1 }),
      });
      const d2 = await res2.json().catch(() => ({}));
      setStatus(res2.ok && d2.success ? 'added' : 'error');
      if (!(res2.ok && d2.success) && d2.redirect) window.open(d2.redirect, '_blank');
      setTimeout(() => setStatus(''), 2600);
    } catch { setStatus('error'); setTimeout(() => setStatus(''), 2600); }
  };

  const buyNow = () => { window.location.href = product.buy_now_url || product.permalink; };

  const ratingCount = Number(product.rating_count) || 0;
  const avg = Number(product.average_rating) || 0;
  const addLabel = status === 'adding' ? '…' : status === 'added' ? '✓' : status === 'error' ? '!' : '+';

  return (
    <div className="sathi-product-card" role="listitem">
      <div className="sathi-product-media">
        <a href={product.permalink} target="_blank" rel="noopener noreferrer" aria-label={product.name}>
          {product.image
            ? <img src={product.image} alt={product.name} loading="lazy" />
            : <div className="sathi-product-noimg" aria-hidden="true">🛍️</div>}
        </a>
        {product.on_sale && <span className="sathi-product-badge">Sale</span>}
        <button
          type="button"
          className={`sathi-wishlist ${fav ? 'is-fav' : ''}`}
          onClick={toggleFav}
          aria-pressed={fav}
          aria-label={fav ? 'Remove from wishlist' : 'Save to wishlist'}
          title={fav ? 'Saved' : 'Save'}
        >
          <svg width="13" height="13" viewBox="0 0 24 24" fill={fav ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth="2" aria-hidden="true">
            <path d="M20.8 4.6a5.5 5.5 0 00-7.8 0L12 5.6l-1-1a5.5 5.5 0 00-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 000-7.8z" />
          </svg>
        </button>
      </div>

      <div className="sathi-product-body">
        <a href={product.permalink} target="_blank" rel="noopener noreferrer" className="sathi-product-name">
          {product.name}
        </a>
        {ratingCount > 0 && (
          <div className="sathi-product-rating">
            <Stars rating={avg} />
            <span className="sathi-product-rcount">({fmtCount(ratingCount)})</span>
          </div>
        )}
        {product.subtitle && <div className="sathi-product-sub">{product.subtitle}</div>}

        <div className="sathi-product-price">
          {product.regular_display && <del>{product.regular_display}</del>}
          <span className="sathi-product-now">{product.price_display || product.price_html || ''}</span>
        </div>

        {product.in_stock ? (
          <div className="sathi-product-actions">
            <button type="button" className="sathi-buy-btn" onClick={buyNow}>
              Buy now
            </button>
            <button
              type="button"
              className={`sathi-add-btn ${status ? 'is-' + status : ''}`}
              onClick={addToCart}
              disabled={status === 'adding'}
              aria-label={`Add ${product.name} to cart`}
              title="Add to cart"
            >
              {addLabel}
            </button>
          </div>
        ) : (
          <span className="sathi-product-oos">Out of stock</span>
        )}
      </div>
    </div>
  );
};

// ── Empty State ────────────────────────────────────────────────────────

const DEFAULT_SUGGESTIONS = [
  'What do you offer?',
  'How can I contact you?',
  'Tell me about pricing',
  'Help me get started',
];

const EmptyState: React.FC = () => {
  const { setInput } = useChatStore();
  const p = config.persona || {
    name: 'Saathi',
    avatar: '🤖',
    role: 'Support Agent',
    color: '#6D5DFB',
  };
  const suggestions = (Array.isArray((config as any).suggestions) && (config as any).suggestions.length)
    ? (config as any).suggestions as string[]
    : DEFAULT_SUGGESTIONS;

  const pick = (t: string) => {
    setInput(t);
    setTimeout(() => document.dispatchEvent(new CustomEvent('sathi:send')), 60);
  };

  return (
    <div className="sathi-empty-state" role="status" style={{ alignItems: 'stretch', textAlign: 'left', justifyContent: 'flex-start', paddingTop: 18 }}>
      <div style={{ display: 'flex', justifyContent: 'center', marginBottom: 10 }}>
        {config.avatar
          ? <AnimatedAvatar frames={config.avatarFrames} fallback={config.avatar} size={64} />
          : <div className="sathi-empty-icon" aria-hidden="true">{p.avatar || '🤖'}</div>}
      </div>
      <div className="sathi-bubble sathi-bubble-assistant" style={{ alignSelf: 'flex-start', maxWidth: '100%' }}>
        <p className="text-sm leading-relaxed">
          {config.greeting || `Hi! I'm ${p.name} 👋 Ask me anything about ${config.siteName || 'us'} — or tap an option below.`}
        </p>
      </div>
      <div className="sathi-suggestions">
        {suggestions.map((s) => (
          <button key={s} type="button" className="sathi-suggestion" onClick={() => pick(s)}>{s}</button>
        ))}
      </div>
    </div>
  );
};

// ── Chat Input ─────────────────────────────────────────────────────────

interface ChatInputProps {
  onStop: () => void;
}

const ChatInput: React.FC<ChatInputProps> = ({ onStop }) => {
  const { input, setInput, isStreaming } = useChatStore();
  const inputRef = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    inputRef.current?.focus();
  }, []);

  /** Send a chat message to the main app */
  const dispatchSend = useCallback(() => {
    if (!input.trim() || isStreaming) return;
    document.dispatchEvent(new CustomEvent('sathi:send'));
  }, [input, isStreaming]);

  const handleKeyDown = (e: React.KeyboardEvent) => {
    // Enter without Shift sends the message
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      dispatchSend();
    }

    // Ctrl+Enter also sends (accessibility: alternative for Enter)
    if (e.key === 'Enter' && e.ctrlKey) {
      e.preventDefault();
      dispatchSend();
    }
  };

  const personaColor = config.persona?.color || '#6D5DFB';

  return (
    <div
      className="sathi-input-area"
      role="form"
      aria-label="Message input"
    >
      <div className="flex items-end gap-2">
        <textarea
          ref={inputRef}
          className="sathi-input"
          rows={1}
          placeholder={config.i18n?.placeholder || 'Type your message…'}
          value={input}
          onChange={(e) => setInput(e.target.value)}
          onKeyDown={handleKeyDown}
          disabled={isStreaming}
          style={{ minHeight: '44px' }}
          aria-label="Type a message"
          aria-describedby="sathi-input-hint"
        />
        <span id="sathi-input-hint" className="sathi-sr-only">
          Press Enter to send, Shift+Enter for a new line.
        </span>

        {isStreaming ? (
          <button
            className="sathi-stop-btn"
            onClick={onStop}
            title="Stop generating"
            aria-label="Stop generating response"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <rect x="4" y="4" width="16" height="16" rx="2" />
            </svg>
          </button>
        ) : (
          <button
            className="sathi-send-btn"
            style={{ background: personaColor }}
            disabled={!input.trim()}
            onClick={dispatchSend}
            title="Send message"
            aria-label="Send message"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" />
            </svg>
          </button>
        )}
      </div>

      {/* Brand credit */}
      <div className="sathi-credit" style={{ textAlign: 'center', fontSize: '10px', letterSpacing: '0.02em', color: '#9ca3af', marginTop: '6px' }}>
        <strong style={{ color: '#6D5DFB', fontWeight: 700 }}>Saathi</strong> · a product by RAI Labs Pvt. Ltd.
      </div>
    </div>
  );
};

// ═══════════════════════════════════════════════════════════════════════
// Main Widget
// ═══════════════════════════════════════════════════════════════════════

interface WidgetProps {
  onStop: () => void;
  onRegenerate: () => void;
  /** Optional: called when Escape key is pressed to close the widget */
  onClose?: () => void;
  /** Optional: show loading skeleton (e.g., while fetching history) */
  isLoading?: boolean;
}

const ChatWidget: React.FC<WidgetProps> = ({
  onStop,
  onRegenerate,
  onClose,
  isLoading = false,
}) => {
  const { messages, isStreaming, toggleSidebar } = useChatStore();
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const messagesContainerRef = useRef<HTMLDivElement>(null);
  const [copiedId, setCopiedId] = useState<string | null>(null);
  const [srAnnouncement, setSrAnnouncement] = useState('');
  const [srLastCount, setSrLastCount] = useState(0);

  // ── Auto-scroll to bottom ───────────────────────────────────────────
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  // ── Screen reader announcements for new messages ────────────────────
  useEffect(() => {
    if (messages.length > srLastCount) {
      const newMsgs = messages.slice(srLastCount);
      const lastNewMsg = newMsgs[newMsgs.length - 1];

      if (lastNewMsg?.role === 'assistant') {
        setSrAnnouncement(
          `New reply from ${config.persona?.name || 'Saathi'}.`
        );
      } else if (lastNewMsg?.role === 'user') {
        setSrAnnouncement(`You sent a message.`);
      }

      setSrLastCount(messages.length);
    }
  }, [messages.length, srLastCount]);

  // ── Keyboard navigation ─────────────────────────────────────────────
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      // Escape closes the widget
      if (e.key === 'Escape') {
        onClose?.();
      }

      // Ctrl+K focuses the input (accessibility shortcut)
      if (e.key === 'k' && e.ctrlKey) {
        e.preventDefault();
        const inputEl = document.querySelector<HTMLTextAreaElement>(
          '.sathi-input'
        );
        inputEl?.focus();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [onClose]);

  // ── Copy to clipboard ───────────────────────────────────────────────
  const copyToClipboard = useCallback(
    async (text: string) => {
      const lastMsgId = messages[messages.length - 1]?.id || '';
      try {
        await navigator.clipboard.writeText(text);
        setCopiedId(lastMsgId);
        setSrAnnouncement('Message copied to clipboard.');
        setTimeout(() => setCopiedId(null), 2000);
      } catch {
        // Fallback for older browsers
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        setCopiedId(lastMsgId);
        setSrAnnouncement('Message copied to clipboard.');
        setTimeout(() => setCopiedId(null), 2000);
      }
    },
    [messages]
  );

  // ── Determine assistant name for ARIA label ─────────────────────────
  const assistantName = config.persona?.name || 'Saathi';

  return (
    <div
      className="sathi-chat-widget"
      role="complementary"
      aria-label={`Chat with ${assistantName}`}
    >
      {/* Screen reader announcer */}
      <SrAnnouncer message={srAnnouncement} clearAfter={3000} />

      {/* Header */}
      <ChatHeader onSidebarToggle={toggleSidebar} />

      {/* Messages area */}
      <div
        ref={messagesContainerRef}
        className="sathi-messages"
        role="log"
        aria-live="polite"
        aria-label="Chat messages"
        aria-relevant="additions"
        tabIndex={0}
      >
        {isLoading ? (
          <LoadingSkeleton />
        ) : messages.length === 0 ? (
          <EmptyState />
        ) : (
          <div role="list">
            {messages.map((msg, idx) => (
              <MessageBubble
                key={msg.id}
                message={msg}
                isLastAssistant={
                  idx === messages.length - 1 && msg.role === 'assistant'
                }
                onCopy={copyToClipboard}
                onRegenerate={onRegenerate}
                copiedId={copiedId}
              />
            ))}
          </div>
        )}

        {/* Loading dots while streaming and no assistant message yet */}
        {isStreaming &&
          messages.length > 0 &&
          messages[messages.length - 1]?.role !== 'assistant' && (
            <LoadingDots />
          )}

        {/* Streaming cursor while actively receiving tokens */}
        {isStreaming &&
          messages.length > 0 &&
          messages[messages.length - 1]?.role === 'assistant' && (
            <StreamingCursor />
          )}

        {/* Scroll anchor */}
        <div ref={messagesEndRef} aria-hidden="true" />
      </div>

      {/* Input */}
      <ChatInput onStop={onStop} />
    </div>
  );
};

// ── Streaming Cursor ───────────────────────────────────────────────────

const StreamingCursor: React.FC = () => (
  <span
    className="sathi-streaming-cursor"
    role="status"
    aria-label="Assistant is typing"
  />
);

export default ChatWidget;
export { MessageBubble, LoadingDots, LoadingSkeleton, SrAnnouncer };
