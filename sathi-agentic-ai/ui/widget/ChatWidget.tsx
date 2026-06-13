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
                {stripThink(message.content)}
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
              onClick={() => onCopy(message.content)}
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

const ProductCards: React.FC<{ products: any[] }> = ({ products }) => (
  <div className="sathi-products mt-2 space-y-2" role="list" aria-label="Matching products">
    {products.map((p) => <ProductCard key={p.id} product={p} />)}
  </div>
);

const ProductCard: React.FC<{ product: any }> = ({ product }) => {
  const [status, setStatus] = useState<'' | 'adding' | 'added' | 'error'>('');
  const accent = (config.persona?.color) || config.accentColor || '#6D5DFB';

  const addToCart = async () => {
    if (!product.purchasable) { window.open(product.permalink, '_blank'); return; }
    setStatus('adding');
    try {
      const res = await fetch(`${config.restUrl}/cart/add`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
        credentials: 'include',
        body: JSON.stringify({ product_id: product.id, quantity: 1 }),
      });
      const d = await res.json().catch(() => ({}));
      setStatus(res.ok && d.success ? 'added' : 'error');
      if (!(res.ok && d.success) && d.redirect) window.open(d.redirect, '_blank');
      setTimeout(() => setStatus(''), 2600);
    } catch { setStatus('error'); setTimeout(() => setStatus(''), 2600); }
  };

  const buyNow = () => { window.location.href = product.buy_now_url || product.permalink; };

  return (
    <div className="sathi-product-card" role="listitem" style={{ display: 'flex', gap: 10, padding: 10, border: '1px solid #e5e7eb', borderRadius: 12, background: '#fff' }}>
      {product.image && (
        <a href={product.permalink} target="_blank" rel="noopener noreferrer" style={{ flexShrink: 0 }}>
          <img src={product.image} alt={product.name} style={{ width: 60, height: 60, objectFit: 'cover', borderRadius: 8 }} />
        </a>
      )}
      <div style={{ flex: 1, minWidth: 0 }}>
        <a href={product.permalink} target="_blank" rel="noopener noreferrer" className="sathi-product-name" style={{ fontWeight: 600, fontSize: 13, color: '#141414', textDecoration: 'none', display: 'block', lineHeight: 1.3 }}>
          {product.name}
        </a>
        <div style={{ fontSize: 13, color: accent, fontWeight: 600, margin: '2px 0' }} dangerouslySetInnerHTML={{ __html: product.price_html || '' }} />
        {!product.in_stock && <div style={{ fontSize: 11, color: '#A23B3B' }}>Out of stock</div>}
        <div style={{ display: 'flex', gap: 6, marginTop: 6 }}>
          <button onClick={addToCart} disabled={status === 'adding' || !product.in_stock}
            style={{ flex: 1, fontSize: 12, fontWeight: 600, padding: '6px 8px', borderRadius: 8, border: 'none', cursor: 'pointer', color: '#fff', background: accent, opacity: (status === 'adding' || !product.in_stock) ? 0.6 : 1 }}>
            {status === 'adding' ? 'Adding…' : status === 'added' ? '✓ Added' : status === 'error' ? 'Try again' : 'Add to Cart'}
          </button>
          {product.in_stock && (
            <button onClick={buyNow} style={{ flex: 1, fontSize: 12, fontWeight: 600, padding: '6px 8px', borderRadius: 8, border: '1px solid ' + accent, cursor: 'pointer', color: accent, background: '#fff' }}>
              Buy Now
            </button>
          )}
        </div>
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
        <strong style={{ color: '#6D5DFB', fontWeight: 700 }}>Saathi</strong> · a product by RAI
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
