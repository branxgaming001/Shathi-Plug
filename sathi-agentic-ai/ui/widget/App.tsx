import React, { useEffect, useCallback, useRef, useState } from 'react';
import { useChatStore, config, Message } from './store';
import ChatWidget from './ChatWidget';
import ConversationSidebar from './ConversationSidebar';
import AnimatedAvatar from './AnimatedAvatar';

interface AppProps {
  embedded?: boolean;
  defaultPersona?: string;
}

const App: React.FC<AppProps> = ({ embedded = false, defaultPersona }) => {
  const {
    isOpen,
    isMinimized,
    toggle,
    open,
    isStreaming,
    setStreaming,
    conversationId,
    setConversationId,
    addMessage,
    appendToken,
    setMessages,
    input,
    setInput,
    messages,
    persona,
    setPersona,
    clearMessages,
    executeActions,
    attachProducts,
    sidebarOpen,
  } = useChatStore();

  const abortRef = useRef<AbortController | null>(null);

  // Reliable non-streaming fallback. Used when the SSE stream endpoint is
  // unreachable or the host breaks streaming (LiteSpeed, proxies, temp-file
  // issues) so the visitor always gets a reply.
  const sendViaRest = async (text: string, convId: string | null) => {
    try {
      const res = await fetch(`${config.restUrl}/chat/send`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
        body: JSON.stringify({
          message: text,
          conversation_id: convId || '',
          persona: defaultPersona || 'sathi-guru',
          guest_id: config.guestId,
          stream: false,
        }),
      });
      const data = await res.json().catch(() => null);
      const content = data?.message?.content;
      if (res.ok && content) {
        addMessage({ id: `sathi-msg-${Date.now()}-ai`, role: 'assistant', content, timestamp: new Date().toISOString() });
        if (data.conversation_id) setConversationId(data.conversation_id);
        if (data.actions?.length) executeActions(data.actions);
        // Also fetch product cards (the REST path doesn't stream them).
        try {
          const pr = await fetch(`${config.restUrl}/products?q=${encodeURIComponent(text)}&limit=3`, { headers: { 'X-WP-Nonce': config.nonce } });
          const pd = await pr.json().catch(() => null);
          if (pd?.products?.length) attachProducts(pd.products);
        } catch { /* products are optional */ }
      } else {
        addMessage({ id: `sathi-msg-err-${Date.now()}`, role: 'assistant', content: config.i18n?.error || 'Sorry, I could not generate a reply. Please check the AI provider settings in the admin.', timestamp: new Date().toISOString() });
      }
    } catch {
      addMessage({ id: `sathi-msg-err-${Date.now()}`, role: 'assistant', content: config.i18n?.error || 'Connection failed. Please try again.', timestamp: new Date().toISOString() });
    }
  };

  // Initialize persona on mount
  useEffect(() => {
    if (!persona && config.persona) {
      setPersona({
        slug: defaultPersona || 'sathi-guru',
        name: config.persona.name || 'Sathi',
        role: config.persona.role || 'Support Agent',
        avatar: config.persona.avatar || '🤖',
        color: config.persona.color || '#6D5DFB',
      });
    }
  }, []);

  // Fetch persona data from API
  useEffect(() => {
    if (defaultPersona && !config.persona?.name) {
      fetch(`${config.restUrl}/personas/${defaultPersona}`)
        .then((r) => r.json())
        .then((data) => {
          if (data.persona) {
            setPersona({
              slug: defaultPersona,
              name: data.persona.name,
              role: data.persona.role || 'Support Agent',
              avatar: data.persona.avatar || '🤖',
              color: data.persona.color || '#6D5DFB',
            });
          }
        })
        .catch(() => {});
    }
  }, [defaultPersona]);

  // ── SSE Streaming Send ──────────────────────────────────────────────
  const sendMessage = useCallback(async () => {
    const text = input.trim();
    if (!text || isStreaming) return;

    // Start a new conversation if none exists
    let currentConvId = conversationId;
    if (!currentConvId) {
      try {
        const res = await fetch(`${config.restUrl}/chat/conversations`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
          body: JSON.stringify({
            persona: defaultPersona || 'sathi-guru',
            guest_id: config.guestId,
          }),
        });
        const data = await res.json();
        if (data.conversation?.uuid) {
          currentConvId = data.conversation.uuid;
          setConversationId(currentConvId!);
        }
      } catch (e) {
        console.error('[Sathi] Failed to create conversation:', e);
      }
    }

    // Add user message to UI
    const userMsg: Message = {
      id: `sathi-msg-${Date.now()}`,
      role: 'user',
      content: text,
      timestamp: new Date().toISOString(),
    };
    addMessage(userMsg);
    setInput('');
    setStreaming(true);

    // Create AbortController for stop
    const controller = new AbortController();
    abortRef.current = controller;
    let receivedAny = false;

    const streamUrl = currentConvId
      ? `${config.streamUrl}${currentConvId}`
      : `${config.streamUrl}new`;

    try {
      const response = await fetch(streamUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': config.nonce,
        },
        body: JSON.stringify({
          message: text,
          persona: defaultPersona || 'sathi-guru',
          guest_id: config.guestId,
        }),
        signal: controller.signal,
      });

      if (!response.ok) {
        const errText = await response.text();
        throw new Error(errText || `HTTP ${response.status}`);
      }

      // Consume SSE stream
      const reader = response.body?.getReader();
      if (!reader) {
        throw new Error('Stream not supported');
      }

      const decoder = new TextDecoder();
      let buffer = '';

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop() || '';

        for (const line of lines) {
          const trimmed = line.trim();
          if (!trimmed.startsWith('data: ')) continue;

          const data = trimmed.slice(6);
          if (data === '[DONE]') continue;

          try {
            const parsed = JSON.parse(data);

            switch (parsed.type) {
              case 'token':
                if (parsed.token) {
                  receivedAny = true;
                  appendToken(parsed.token);
                }
                break;

              case 'metadata':
                if (parsed.conversation_id) {
                  setConversationId(parsed.conversation_id);
                }
                break;

              case 'actions':
                if (parsed.actions?.length) {
                  executeActions(parsed.actions);
                }
                break;

              case 'products':
                if (parsed.products?.length) {
                  attachProducts(parsed.products);
                }
                break;

              case 'error':
                console.error('[Sathi] Stream error:', parsed.message);
                addMessage({
                  id: `sathi-msg-error-${Date.now()}`,
                  role: 'assistant',
                  content: parsed.message || config.i18n?.error || 'Something went wrong.',
                  timestamp: new Date().toISOString(),
                });
                break;
            }
          } catch {
            // Skip malformed JSON
          }
        }
      }

      // Stream completed but produced no content — use the reliable fallback.
      if (!receivedAny) {
        await sendViaRest(text, currentConvId);
      }
    } catch (err: any) {
      if (err.name === 'AbortError') {
        // User stopped generation — the partial content is already in the messages
      } else if (!receivedAny) {
        // Stream connection failed before any content (endpoint unreachable,
        // host blocked SSE, etc.) — fall back to the non-streaming REST route.
        await sendViaRest(text, currentConvId);
      } else {
        console.error('[Sathi] Stream error:', err);
      }
    } finally {
      abortRef.current = null;
      setStreaming(false);
    }
  }, [input, isStreaming, conversationId, config.guestId, config.streamUrl, defaultPersona]);

  // Stop streaming
  const stopStreaming = useCallback(() => {
    if (abortRef.current) {
      abortRef.current.abort();
      abortRef.current = null;
      setStreaming(false);
    }
  }, []);

  // Regenerate last response
  const regenerate = useCallback(async () => {
    if (isStreaming) return;

    const msgs = useChatStore.getState().messages;
    if (msgs.length < 2) return;

    // Find last user message
    let lastUserMsg = '';
    for (let i = msgs.length - 1; i >= 0; i--) {
      if (msgs[i].role === 'user') {
        lastUserMsg = msgs[i].content;
        break;
      }
    }
    if (!lastUserMsg) return;

    // Remove last assistant message from UI
    const newMsgs = [...msgs];
    while (newMsgs.length > 0 && newMsgs[newMsgs.length - 1].role !== 'user') {
      newMsgs.pop();
    }
    setMessages(newMsgs);

    // Re-send
    setInput(lastUserMsg);
    // Use setTimeout to let state update before sending
    setTimeout(() => {
      document.dispatchEvent(new CustomEvent('sathi:send'));
    }, 50);
  }, [isStreaming]);

  // Listen for send events from ChatInput
  useEffect(() => {
    const handler = () => sendMessage();
    document.addEventListener('sathi:send', handler);
    return () => document.removeEventListener('sathi:send', handler);
  }, [sendMessage]);

  // Auto-open after a configurable delay (only when enabled in admin)
  useEffect(() => {
    if (embedded || !config.autoOpen || messages.length > 0) return;
    const delay = Math.max(0, (config.autoOpenDelay ?? 5)) * 1000;
    const timer = setTimeout(() => open(), delay);
    return () => clearTimeout(timer);
  }, []);

  // Apply the admin-selected color theme to the widget root + keep the
  // ThemeCustomizer's localStorage key in sync.
  useEffect(() => {
    const theme = config.theme || 'light';
    if (theme === 'light' || theme === 'dark') {
      try {
        const raw = localStorage.getItem('sathi_theme_settings');
        const cur = raw ? JSON.parse(raw) : {};
        if (!cur.darkMode) {
          cur.darkMode = theme;
          localStorage.setItem('sathi_theme_settings', JSON.stringify(cur));
        }
      } catch { /* ignore */ }
    }
  }, []);

  // ── Render ──────────────────────────────────────────────────────────

  if (embedded) {
    return (
      <div className="sathi-embedded-root" style={{ width: '100%', height: '100%', minHeight: '400px' }}>
        <ChatWidget
          onStop={stopStreaming}
          onRegenerate={regenerate}
        />
      </div>
    );
  }

  const position = config.position || 'bottom-right';
  const posClasses: Record<string, string> = {
    'bottom-right': 'bottom-5 right-5',
    'bottom-left': 'bottom-5 left-5',
    'top-right': 'top-5 right-5',
    'top-left': 'top-5 left-5',
  };
  const themeClass = config.theme === 'dark' ? 'sathi-dark' : config.theme === 'light' ? 'sathi-light' : '';
  const launcher = config.launcherIcon || 'chat';

  return (
    <div
      className={`sathi-floating-root ${themeClass} fixed ${posClasses[position] || 'bottom-5 right-5'} z-[9999]`}
      style={{ fontFamily: 'Plus Jakarta Sans, Inter, system-ui, -apple-system, sans-serif' }}
    >
      {/* Floating toggle button */}
      {!isOpen && (
        <button
          className="sathi-trigger w-14 h-14 rounded-full shadow-float flex items-center justify-center text-white transition-all duration-300 hover:scale-110 hover:shadow-xl active:scale-95"
          style={{
            background: `linear-gradient(135deg, ${persona?.color || config.accentColor || '#6D5DFB'}, ${persona?.color || config.accentColor || '#6D5DFB'}dd)`,
          }}
          onClick={() => toggle()}
          aria-label="Open chat"
        >
          {config.avatar ? (
            <AnimatedAvatar frames={config.avatarFrames} fallback={config.avatar} size={46} />
          ) : launcher === 'chat' ? (
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
            </svg>
          ) : (
            <span className="text-2xl">{launcher}</span>
          )}
        </button>
      )}

      {/* Chat window with sidebar */}
      {isOpen && !isMinimized && (
        <div className="sathi-window w-[420px] h-[640px] max-w-[calc(100vw-2rem)] max-h-[calc(100vh-2rem)] animate-slide-up flex">
          {sidebarOpen && <ConversationSidebar />}
          <div className="flex-1 min-w-0">
            <ChatWidget
              onStop={stopStreaming}
              onRegenerate={regenerate}
            />
          </div>
        </div>
      )}

      {/* Minimized bar */}
      {isOpen && isMinimized && (
        <button
          className="sathi-mini-bar px-4 py-3 rounded-full shadow-float flex items-center gap-3 text-white animate-slide-up"
          style={{ background: persona?.color || '#6D5DFB' }}
          onClick={() => open()}
        >
          <span className="text-lg">{persona?.avatar || '🤖'}</span>
          <span className="text-sm font-medium">{config.i18n?.title || 'Chat with us'}</span>
        </button>
      )}
    </div>
  );
};

export default App;
