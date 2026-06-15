import { create } from 'zustand';

interface Product {
  id: number;
  name: string;
  price_html: string;
  price_display?: string;
  regular_display?: string;
  image: string;
  permalink: string;
  excerpt: string;
  subtitle?: string;
  average_rating?: number;
  rating_count?: number;
  in_stock: boolean;
  purchasable: boolean;
  on_sale?: boolean;
  buy_now_url: string;
}

interface Message {
  id: string;
  role: 'user' | 'assistant' | 'system';
  content: string;
  timestamp: string;
  actions?: ClientAction[];
  products?: Product[];
}

interface ClientAction {
  type: 'navigate' | 'scroll_to' | 'highlight' | 'focus_input' | 'open_contact' | 'show_tooltip';
  params: Record<string, string>;
}

interface Persona {
  slug: string;
  name: string;
  role: string;
  avatar: string;
  color: string;
}

interface ChatState {
  // UI state
  isOpen: boolean;
  isMinimized: boolean;
  isStreaming: boolean;
  sidebarOpen: boolean;

  // Conversation
  conversationId: string | null;
  messages: Message[];
  input: string;
  persona: Persona | null;

  // Actions
  toggle: () => void;
  minimize: () => void;
  open: () => void;
  toggleSidebar: () => void;
  setInput: (val: string) => void;
  addMessage: (msg: Message) => void;
  updateLastAssistant: (content: string) => void;
  setConversationId: (id: string) => void;
  setStreaming: (s: boolean) => void;
  setPersona: (p: Persona) => void;
  clearMessages: () => void;
  executeActions: (actions: ClientAction[]) => void;
  appendToken: (token: string) => void;
  setMessages: (msgs: Message[]) => void;
  attachProducts: (products: Product[]) => void;
}

declare global {
  interface Window {
    sathiConfig: {
      restUrl: string;
      streamUrl: string;
      wcAjaxUrl?: string;
      nonce: string;
      siteName: string;
      siteDescription: string;
      position: string;
      accentColor: string;
      greeting: string;
      title?: string;
      theme?: 'light' | 'dark' | 'auto';
      launcherIcon?: string;
      avatar?: string;
      avatarFrames?: string[];
      autoOpen?: boolean;
      autoOpenDelay?: number;
      persona: Record<string, string>;
      streamingEnabled: boolean;
      memoryEnabled: boolean;
      guestId: string;
      i18n: Record<string, string>;
    };
  }
}

const config = window.sathiConfig || {};

/**
 * Session persistence — chat memory survives page refreshes for as long as the
 * browser tab stays open, but resets when the tab/browser is closed.
 * sessionStorage is exactly this lifecycle (per-tab, cleared on tab close),
 * so the conversation continues across any number of reloads of the same site.
 */
const PERSIST_KEY = 'sathi_chat_' + (config.guestId || 'guest');

interface PersistedState {
  isOpen: boolean;
  conversationId: string | null;
  messages: Message[];
}

function loadPersisted(): Partial<PersistedState> {
  try {
    const raw = sessionStorage.getItem(PERSIST_KEY);
    if (!raw) return {};
    const data = JSON.parse(raw);
    if (!data || typeof data !== 'object') return {};
    return {
      isOpen: !!data.isOpen,
      conversationId: typeof data.conversationId === 'string' ? data.conversationId : null,
      messages: Array.isArray(data.messages) ? data.messages : [],
    };
  } catch (e) {
    return {};
  }
}

const persisted = loadPersisted();

export const useChatStore = create<ChatState>((set, get) => ({
  isOpen: persisted.isOpen ?? false,
  isMinimized: false,
  isStreaming: false,
  sidebarOpen: false,
  conversationId: persisted.conversationId ?? null,
  messages: persisted.messages ?? [],
  input: '',
  persona: null,

  toggle: () => {
    const { isOpen } = get();
    set({ isOpen: !isOpen, isMinimized: false });
  },

  minimize: () => set({ isMinimized: true }),
  open: () => set({ isOpen: true, isMinimized: false }),
  toggleSidebar: () => set((s) => ({ sidebarOpen: !s.sidebarOpen })),

  setInput: (val) => set({ input: val }),

  addMessage: (msg) =>
    set((state) => ({ messages: [...state.messages, msg] })),

  updateLastAssistant: (content) =>
    set((state) => {
      const msgs = [...state.messages];
      const lastIdx = msgs.length - 1;
      if (lastIdx >= 0 && msgs[lastIdx].role === 'assistant') {
        msgs[lastIdx] = { ...msgs[lastIdx], content };
      }
      return { messages: msgs };
    }),

  setConversationId: (id) => set({ conversationId: id }),
  setStreaming: (s) => set({ isStreaming: s }),

  attachProducts: (products) =>
    set((state) => {
      const msgs = [...state.messages];
      // Attach to the most recent assistant message; create one if needed.
      for (let i = msgs.length - 1; i >= 0; i--) {
        if (msgs[i].role === 'assistant') {
          msgs[i] = { ...msgs[i], products };
          return { messages: msgs };
        }
      }
      msgs.push({ id: `sathi-msg-${Date.now()}-p`, role: 'assistant', content: '', timestamp: new Date().toISOString(), products });
      return { messages: msgs };
    }),

  setPersona: (p) => set({ persona: p }),

  clearMessages: () => set({ messages: [], conversationId: null }),

  setMessages: (msgs) => set({ messages: msgs }),

  appendToken: (token) =>
    set((state) => {
      const msgs = [...state.messages];
      const lastIdx = msgs.length - 1;

      if (lastIdx >= 0 && msgs[lastIdx].role === 'assistant') {
        // Append to existing assistant message
        msgs[lastIdx] = {
          ...msgs[lastIdx],
          content: msgs[lastIdx].content + token,
        };
      } else {
        // First token — create new assistant message
        msgs.push({
          id: `sathi-msg-${Date.now()}-ai`,
          role: 'assistant',
          content: token,
          timestamp: new Date().toISOString(),
        });
      }
      return { messages: msgs };
    }),

  executeActions: (actions) => {
    actions.forEach((action) => {
      switch (action.type) {
        case 'navigate':
          if (action.params.url) {
            window.location.href = action.params.url;
          }
          break;
        case 'scroll_to':
          if (action.params.selector) {
            const el = document.querySelector(action.params.selector);
            el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
          break;
        case 'highlight':
          if (action.params.selector) {
            const el = document.querySelector(action.params.selector);
            if (el) {
              el.classList.add('sathi-highlight');
              setTimeout(() => el.classList.remove('sathi-highlight'), 3000);
            }
          }
          break;
        case 'focus_input':
          if (action.params.selector) {
            const el = document.querySelector<HTMLInputElement>(action.params.selector);
            el?.focus();
          }
          break;
        case 'show_tooltip':
          // Tooltips are rendered by the TourOverlay component.
          // This is a no-op when called standalone.
          break;
        case 'open_contact':
          const contactEl = document.querySelector('#contact, .contact-form, .wpcf7');
          if (contactEl) {
            contactEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
          break;
      }
    });
  },
}));

/**
 * Persist the conversation to sessionStorage on every relevant change.
 * Only the durable bits (open state, conversation id, messages) are saved —
 * transient flags like isStreaming/input are intentionally left out so a
 * refresh never restores a half-finished streaming state.
 */
let persistTimer: ReturnType<typeof setTimeout> | null = null;
useChatStore.subscribe((state) => {
  if (persistTimer) clearTimeout(persistTimer);
  persistTimer = setTimeout(() => {
    try {
      const payload: PersistedState = {
        isOpen: state.isOpen,
        conversationId: state.conversationId,
        // Cap stored history so sessionStorage never overflows on long chats.
        messages: state.messages.slice(-60),
      };
      sessionStorage.setItem(PERSIST_KEY, JSON.stringify(payload));
    } catch (e) {
      /* storage full / unavailable — ignore, chat still works in-memory */
    }
  }, 150);
});

export { config };
export type { Message, ClientAction, Persona, Product };
