import React, { useEffect, useState, useCallback } from 'react';
import { useChatStore, config } from './store';

interface ConvSummary {
  uuid: string;
  title: string | null;
  status: string;
  message_count: number;
  persona_id: string;
  updated_at: string;
  created_at: string;
}

const ConversationSidebar: React.FC = () => {
  const {
    conversationId,
    setConversationId,
    setMessages,
    clearMessages,
    toggleSidebar,
    setStreaming,
  } = useChatStore();

  const [conversations, setConversations] = useState<ConvSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [deleteConfirm, setDeleteConfirm] = useState<string | null>(null);

  const fetchConversations = useCallback(async () => {
    try {
      const params = new URLSearchParams();
      if (search) params.set('search', search);
      const url = `${config.restUrl}/chat/conversations${params.toString() ? '?' + params.toString() : ''}`;
      const res = await fetch(url, {
        headers: { 'X-WP-Nonce': config.nonce },
      });
      if (res.ok) {
        const data = await res.json();
        setConversations(data.conversations || []);
      }
    } catch (e) {
      console.error('[Saathi] Failed to fetch conversations:', e);
    } finally {
      setLoading(false);
    }
  }, [search]);

  useEffect(() => {
    fetchConversations();
  }, [fetchConversations]);

  // Switch to a conversation
  const switchTo = async (uuid: string) => {
    if (conversationId === uuid) return;

    setLoading(true);
    try {
      const res = await fetch(`${config.restUrl}/chat/conversations/${uuid}`, {
        headers: { 'X-WP-Nonce': config.nonce },
      });
      if (res.ok) {
        const data = await res.json();
        setConversationId(uuid);
        const msgs = (data.messages || []).map((m: any) => ({
          id: `sathi-msg-${m.created_at}-${Date.now()}-${Math.random()}`,
          role: m.role,
          content: m.content,
          timestamp: m.created_at,
          actions: m.metadata?.actions || undefined,
        }));
        setMessages(msgs);
      }
    } catch (e) {
      console.error('[Saathi] Failed to load conversation:', e);
    } finally {
      setLoading(false);
    }
  };

  // New chat
  const newChat = () => {
    clearMessages();
    setConversationId(null as any);
  };

  // Delete conversation
  const deleteConv = async (uuid: string) => {
    try {
      await fetch(`${config.restUrl}/chat/conversations/${uuid}`, {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': config.nonce },
      });
      setConversations((prev) => prev.filter((c) => c.uuid !== uuid));
      if (conversationId === uuid) {
        clearMessages();
      }
      setDeleteConfirm(null);
    } catch (e) {
      console.error('[Saathi] Failed to delete:', e);
    }
  };

  const formatDate = (iso: string) => {
    const d = new Date(iso);
    const now = new Date();
    const diff = now.getTime() - d.getTime();
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));

    if (days === 0) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    if (days === 1) return 'Yesterday';
    if (days < 7) return `${days} days ago`;
    return d.toLocaleDateString();
  };

  return (
    <div className="sathi-sidebar w-64 flex-shrink-0 border-r border-gray-200 bg-gray-50 flex flex-col h-full">
      {/* Header */}
      <div className="px-3 py-3 border-b border-gray-200">
        <div className="flex items-center justify-between mb-2">
          <h3 className="text-sm font-semibold text-gray-900 m-0">
            {config.i18n?.newChat || 'Conversations'}
          </h3>
          <button
            className="p-1 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-200 transition-colors"
            onClick={toggleSidebar}
            title="Close sidebar"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M15 18l-6-6 6-6" />
            </svg>
          </button>
        </div>
        <button
          className="w-full px-3 py-2 text-xs font-medium text-white rounded-lg transition-colors hover:opacity-90 flex items-center gap-2 justify-center"
          style={{ background: config.persona?.color || '#6D5DFB' }}
          onClick={newChat}
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M12 5v14M5 12h14" />
          </svg>
          {config.i18n?.newChat || 'New Chat'}
        </button>
      </div>

      {/* Search */}
      <div className="px-3 py-2">
        <input
          type="text"
          className="w-full px-2.5 py-1.5 text-xs rounded-md border border-gray-200 bg-white focus:outline-none focus:ring-1 focus:ring-sathi-500 focus:border-transparent placeholder-gray-400"
          placeholder="Search conversations..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      {/* List */}
      <div className="flex-1 overflow-y-auto">
        {loading ? (
          <div className="px-3 py-6 text-center text-xs text-gray-400">Loading...</div>
        ) : conversations.length === 0 ? (
          <div className="px-3 py-6 text-center text-xs text-gray-400">
            {search ? 'No results' : 'No conversations yet'}
          </div>
        ) : (
          conversations.map((conv) => (
            <div
              key={conv.uuid}
              className={`sathi-sidebar-item group relative px-3 py-2.5 border-b border-gray-100 cursor-pointer transition-colors ${
                conversationId === conv.uuid
                  ? 'bg-white border-l-2 border-l-sathi-600'
                  : 'hover:bg-white/60'
              }`}
              style={
                conversationId === conv.uuid
                  ? { borderLeftColor: config.persona?.color || '#6D5DFB' }
                  : {}
              }
              onClick={() => switchTo(conv.uuid)}
            >
              <div className="text-xs font-medium text-gray-900 truncate pr-6">
                {conv.title || 'New Conversation'}
              </div>
              <div className="text-[10px] text-gray-400 mt-0.5">
                {conv.message_count} messages · {formatDate(conv.updated_at)}
              </div>

              {/* Delete button */}
              {deleteConfirm === conv.uuid ? (
                <div className="absolute right-2 top-2 flex items-center gap-1">
                  <button
                    className="px-1.5 py-0.5 text-[10px] font-medium text-white bg-red-500 rounded hover:bg-red-600"
                    onClick={(e) => { e.stopPropagation(); deleteConv(conv.uuid); }}
                  >
                    Delete
                  </button>
                  <button
                    className="px-1.5 py-0.5 text-[10px] text-gray-500 hover:text-gray-700"
                    onClick={(e) => { e.stopPropagation(); setDeleteConfirm(null); }}
                  >
                    ✕
                  </button>
                </div>
              ) : (
                <button
                  className="absolute right-2 top-2 p-0.5 text-gray-300 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-all"
                  onClick={(e) => { e.stopPropagation(); setDeleteConfirm(conv.uuid); }}
                  title="Delete"
                >
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6" />
                  </svg>
                </button>
              )}
            </div>
          ))
        )}
      </div>
    </div>
  );
};

export default ConversationSidebar;
