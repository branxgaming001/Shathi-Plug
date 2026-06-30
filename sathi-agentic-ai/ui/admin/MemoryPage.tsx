import React, { useEffect, useState, useCallback } from 'react';

declare global {
  interface Window {
    sathiAdmin: {
      restUrl: string;
      nonce: string;
      siteName: string;
      accentColor: string;
      version: string;
    };
  }
}

const admin = window.sathiAdmin || {};
const api = (endpoint: string, options: RequestInit = {}) =>
  fetch(`${admin.restUrl}${endpoint}`, {
    ...options,
    headers: {
      ...(options.headers || {}),
      'X-WP-Nonce': admin.nonce,
      ...(options.method !== 'GET' ? { 'Content-Type': 'application/json' } : {}),
    },
  }).then((r) => {
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    return r.json();
  });

// ── Types ────────────────────────────────────────────────────────────

interface MemoryEntry {
  id: number;
  user_id: number | null;
  guest_id: string | null;
  key: string;
  value: string | Record<string, unknown>;
  importance: number;
  source_conversation_id: number | null;
  expires_at: string | null;
  created_at: string;
  updated_at: string;
}

interface Stats {
  total_entries: number;
  unique_users: number;
  unique_guests: number;
  top_keys: { key: string; count: number }[];
  oldest_entry: string | null;
  newest_entry: string | null;
}

interface EntriesResponse {
  entries: MemoryEntry[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
}

// ── Helpers ──────────────────────────────────────────────────────────

function formatValue(val: string | Record<string, unknown>): string {
  if (typeof val === 'string') return val;
  try {
    return JSON.stringify(val);
  } catch {
    return String(val);
  }
}

function valuePreview(val: string | Record<string, unknown>, maxLen = 60): string {
  const s = formatValue(val);
  return s.length > maxLen ? s.slice(0, maxLen) + '…' : s;
}

function importanceColor(imp: number): string {
  if (imp >= 8) return 'bg-green-100 text-green-800';
  if (imp >= 5) return 'bg-yellow-100 text-yellow-800';
  return 'bg-gray-100 text-gray-600';
}

function formatDate(iso: string | null): string {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

// ── Stat Card ────────────────────────────────────────────────────────

const StatCard: React.FC<{ label: string; value: string; icon: string }> = ({
  label,
  value,
  icon,
}) => (
  <div className="p-4 rounded-xl border border-gray-200 bg-white">
    <div className="text-2xl mb-2">{icon}</div>
    <div className="text-2xl font-bold text-gray-900">{value}</div>
    <div className="text-xs text-gray-500 mt-1">{label}</div>
  </div>
);

// ── Main Component ───────────────────────────────────────────────────

const MemoryPage: React.FC = () => {
  const [stats, setStats] = useState<Stats | null>(null);
  const [entries, setEntries] = useState<EntriesResponse | null>(null);
  const [profile, setProfile] = useState<string>('');
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [extracting, setExtracting] = useState(false);
  const [extractResult, setExtractResult] = useState<{
    extracted_count: number;
    fallback: boolean;
    error?: string;
  } | null>(null);
  const [showProfile, setShowProfile] = useState(false);
  const [deleteConfirm, setDeleteConfirm] = useState<number | null>(null);

  // ── Data Fetching ────────────────────────────────────────────────

  const fetchStats = useCallback(async () => {
    try {
      const data = await api('/memory/stats');
      setStats(data);
    } catch (err) {
      console.error('[Saathi Memory] Stats fetch error:', err);
    }
  }, []);

  const fetchEntries = useCallback(async () => {
    try {
      const params = new URLSearchParams({
        page: String(page),
        per_page: '20',
      });
      if (search) params.set('search', search);

      const data = await api(`/memory/entries?${params.toString()}`);
      setEntries(data);
    } catch (err) {
      console.error('[Saathi Memory] Entries fetch error:', err);
    }
  }, [page, search]);

  const fetchProfile = useCallback(async () => {
    try {
      const data = await api('/memory/profile');
      setProfile(data.profile || '');
      setShowProfile(Boolean(data.has_memories));
    } catch {
      // profile is optional; ignore errors
    }
  }, []);

  const loadAll = useCallback(async () => {
    setLoading(true);
    await Promise.all([fetchStats(), fetchEntries(), fetchProfile()]);
    setLoading(false);
  }, [fetchStats, fetchEntries, fetchProfile]);

  useEffect(() => {
    loadAll();
  }, [loadAll]);

  useEffect(() => {
    fetchEntries();
  }, [fetchEntries]);

  // ── Actions ──────────────────────────────────────────────────────

  const handleDelete = async (id: number) => {
    try {
      await api(`/memory/entry/${id}`, { method: 'DELETE' });
      setDeleteConfirm(null);
      await Promise.all([fetchEntries(), fetchStats()]);
    } catch (err) {
      console.error('[Saathi Memory] Delete error:', err);
    }
  };

  const handleExtract = async () => {
    setExtracting(true);
    setExtractResult(null);

    try {
      // First get the most recent conversations to pick from
      const convsRes = await api('/chat/conversations?per_page=5');
      const conversations = convsRes.conversations || [];

      if (conversations.length === 0) {
        setExtractResult({
          extracted_count: 0,
          fallback: false,
          error: 'No conversations found.',
        });
        setExtracting(false);
        return;
      }

      // Extract from the most recent conversation
      const conv = conversations[0];
      const result = await api('/memory/extract', {
        method: 'POST',
        body: JSON.stringify({ conversation_uuid: conv.uuid }),
      });

      setExtractResult({
        extracted_count: result.extracted_count || result.extracted?.length || 0,
        fallback: result.fallback || false,
        error: result.error || null,
      });

      // Refresh data
      await Promise.all([fetchEntries(), fetchStats(), fetchProfile()]);
    } catch (err: any) {
      console.error('[Saathi Memory] Extract error:', err);
      setExtractResult({
        extracted_count: 0,
        fallback: false,
        error: err.message || 'Unknown error',
      });
    } finally {
      setExtracting(false);
    }
  };

  const handleSearchKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      setPage(1);
      fetchEntries();
    }
  };

  // ── Loading State ────────────────────────────────────────────────

  if (loading) {
    return (
      <div className="sathi-memory-loading py-12 text-center text-gray-400">
        <div className="animate-spin w-8 h-8 border-2 border-sathi-600 border-t-transparent rounded-full mx-auto mb-4" />
        Loading memory data…
      </div>
    );
  }

  // ── Render ───────────────────────────────────────────────────────

  return (
    <div
      className="sathi-memory-page"
      style={{
        fontFamily: 'Inter, system-ui, sans-serif',
        maxWidth: '1040px',
        margin: '0 auto',
        padding: '24px',
      }}
    >
      {/* Header */}
      <div className="flex items-center justify-between mb-8">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 m-0 p-0">
            User Memory
          </h1>
          <p className="text-sm text-gray-500 mt-1 m-0">
            Persistent memory entries stored across conversations
          </p>
        </div>
        <div className="flex items-center gap-3">
          <button
            className="px-4 py-2 bg-white border border-gray-200 rounded-lg text-sm text-gray-600 hover:bg-gray-50 transition-colors"
            onClick={() => setShowProfile((v) => !v)}
          >
            {showProfile ? 'Hide Profile' : 'User Profile'}
          </button>
          <button
            className="px-4 py-2 bg-sathi-600 text-white rounded-lg text-sm hover:bg-sathi-700 transition-colors disabled:opacity-50"
            onClick={handleExtract}
            disabled={extracting}
          >
            {extracting ? 'Extracting…' : 'Extract from Conversations'}
          </button>
        </div>
      </div>

      {/* Extraction Result Toast */}
      {extractResult && (
        <div
          className={`mb-4 p-3 rounded-lg text-sm ${
            extractResult.error || extractResult.extracted_count === 0
              ? 'bg-red-50 text-red-700 border border-red-200'
              : extractResult.fallback
              ? 'bg-yellow-50 text-yellow-700 border border-yellow-200'
              : 'bg-green-50 text-green-700 border border-green-200'
          }`}
        >
          {extractResult.error
            ? `Extraction failed: ${extractResult.error}`
            : extractResult.fallback
            ? `Regex fallback used — ${extractResult.extracted_count} entries extracted.`
            : `LLM extraction complete — ${extractResult.extracted_count} facts extracted.`}
          <button
            className="ml-3 underline text-xs"
            onClick={() => setExtractResult(null)}
          >
            Dismiss
          </button>
        </div>
      )}

      {/* Stats Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <StatCard
          label="Total Entries"
          value={(stats?.total_entries ?? 0).toLocaleString()}
          icon="🧠"
        />
        <StatCard
          label="Registered Users"
          value={(stats?.unique_users ?? 0).toLocaleString()}
          icon="👤"
        />
        <StatCard
          label="Guest Sessions"
          value={(stats?.unique_guests ?? 0).toLocaleString()}
          icon="👻"
        />
        <StatCard
          label="Top Key"
          value={stats?.top_keys?.[0]?.key || '—'}
          icon="🔑"
        />
      </div>

      {/* LLM-Generated Profile Panel */}
      {showProfile && (
        <div className="mb-8 p-5 rounded-xl border border-sathi-200 bg-sathi-50">
          <div className="flex items-center justify-between mb-3">
            <h3 className="text-sm font-semibold text-sathi-800 m-0">
              AI-Generated User Profile
            </h3>
            <span className="text-[10px] text-sathi-500 bg-white px-2 py-0.5 rounded-full border border-sathi-200">
              LLM Summary
            </span>
          </div>
          {profile ? (
            <p className="text-sm text-gray-700 leading-relaxed m-0">
              {profile}
            </p>
          ) : (
            <p className="text-sm text-gray-400 italic m-0">
              No memories stored yet. Chat with users to build profiles.
            </p>
          )}
        </div>
      )}

      {/* Top Keys Panel */}
      {stats?.top_keys && stats.top_keys.length > 0 && (
        <div className="mb-8 p-4 rounded-xl border border-gray-200 bg-white">
          <h3 className="text-sm font-semibold text-gray-700 mb-3 m-0">
            Most Common Memory Keys
          </h3>
          <div className="flex flex-wrap gap-2">
            {stats.top_keys.map((tk) => (
              <span
                key={tk.key}
                className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700"
              >
                <span className="text-gray-400">{tk.key}</span>
                <span className="text-sathi-600 font-semibold">
                  {tk.count}
                </span>
              </span>
            ))}
          </div>
        </div>
      )}

      {/* Search + Filters */}
      <div className="flex items-center gap-3 mb-4">
        <div className="relative flex-1 max-w-sm">
          <input
            type="text"
            className="w-full pl-9 pr-3 py-2 rounded-lg border border-gray-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-sathi-500 focus:border-transparent placeholder-gray-400"
            placeholder="Search keys or values…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            onKeyDown={handleSearchKeyDown}
          />
          <svg
            className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
          >
            <circle cx="11" cy="11" r="8" />
            <path d="M21 21l-4.35-4.35" />
          </svg>
        </div>
        <button
          className="px-3 py-2 bg-white border border-gray-200 rounded-lg text-sm text-gray-600 hover:bg-gray-50 transition-colors"
          onClick={() => {
            setSearch('');
            setPage(1);
          }}
        >
          Clear
        </button>
        <span className="text-xs text-gray-400">
          {entries ? `${entries.total.toLocaleString()} total` : ''}
        </span>
      </div>

      {/* Entries Table */}
      <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="bg-gray-50 border-b border-gray-200">
                <th className="text-left px-4 py-3 font-medium text-gray-600 text-xs uppercase tracking-wider">
                  Key
                </th>
                <th className="text-left px-4 py-3 font-medium text-gray-600 text-xs uppercase tracking-wider">
                  Value
                </th>
                <th className="text-left px-4 py-3 font-medium text-gray-600 text-xs uppercase tracking-wider hidden md:table-cell">
                  User
                </th>
                <th className="text-center px-4 py-3 font-medium text-gray-600 text-xs uppercase tracking-wider w-20">
                  Imp.
                </th>
                <th className="text-left px-4 py-3 font-medium text-gray-600 text-xs uppercase tracking-wider hidden lg:table-cell">
                  Updated
                </th>
                <th className="text-center px-4 py-3 font-medium text-gray-600 text-xs uppercase tracking-wider w-16">
                  Del
                </th>
              </tr>
            </thead>
            <tbody>
              {(!entries || entries.entries.length === 0) ? (
                <tr>
                  <td
                    colSpan={6}
                    className="px-4 py-12 text-center text-gray-400"
                  >
                    {search
                      ? 'No memory entries match your search.'
                      : 'No memory entries yet. User conversations will populate this table.'}
                  </td>
                </tr>
              ) : (
                entries.entries.map((entry) => (
                  <tr
                    key={entry.id}
                    className="border-b border-gray-100 hover:bg-gray-50 transition-colors"
                  >
                    {/* Key */}
                    <td className="px-4 py-2.5">
                      <code className="text-xs font-mono bg-sathi-50 text-sathi-700 px-1.5 py-0.5 rounded">
                        {entry.key}
                      </code>
                    </td>
                    {/* Value preview */}
                    <td className="px-4 py-2.5 text-gray-700 max-w-[300px] truncate">
                      <span title={formatValue(entry.value)}>
                        {valuePreview(entry.value)}
                      </span>
                    </td>
                    {/* User */}
                    <td className="px-4 py-2.5 text-gray-500 hidden md:table-cell">
                      {entry.user_id ? (
                        <span className="inline-flex items-center gap-1">
                          <span className="w-2 h-2 rounded-full bg-green-400" />
                          User #{entry.user_id}
                        </span>
                      ) : entry.guest_id ? (
                        <span className="inline-flex items-center gap-1">
                          <span className="w-2 h-2 rounded-full bg-gray-300" />
                          <span className="text-xs font-mono">
                            {entry.guest_id.slice(0, 8)}…
                          </span>
                        </span>
                      ) : (
                        <span className="text-gray-400">—</span>
                      )}
                    </td>
                    {/* Importance */}
                    <td className="px-4 py-2.5 text-center">
                      <span
                        className={`inline-block px-2 py-0.5 rounded-full text-xs font-medium ${importanceColor(
                          entry.importance
                        )}`}
                      >
                        {entry.importance}
                      </span>
                    </td>
                    {/* Updated */}
                    <td className="px-4 py-2.5 text-gray-400 text-xs hidden lg:table-cell">
                      {formatDate(entry.updated_at)}
                    </td>
                    {/* Delete */}
                    <td className="px-4 py-2.5 text-center">
                      {deleteConfirm === entry.id ? (
                        <div className="flex items-center gap-1 justify-center">
                          <button
                            className="px-2 py-0.5 rounded text-xs bg-red-500 text-white hover:bg-red-600 transition-colors"
                            onClick={() => handleDelete(entry.id)}
                          >
                            Confirm
                          </button>
                          <button
                            className="px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-600 hover:bg-gray-200 transition-colors"
                            onClick={() => setDeleteConfirm(null)}
                          >
                            No
                          </button>
                        </div>
                      ) : (
                        <button
                          className="p-1 rounded text-gray-400 hover:text-red-500 hover:bg-red-50 transition-colors"
                          onClick={() => setDeleteConfirm(entry.id)}
                          title="Delete entry"
                        >
                          <svg
                            width="14"
                            height="14"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                          >
                            <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2" />
                          </svg>
                        </button>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {entries && entries.pages > 1 && (
          <div className="flex items-center justify-between px-4 py-3 border-t border-gray-200 bg-gray-50">
            <span className="text-xs text-gray-500">
              Page {entries.page} of {entries.pages} ({entries.total} entries)
            </span>
            <div className="flex gap-1">
              <button
                className="px-3 py-1 rounded text-xs border border-gray-200 bg-white text-gray-600 hover:bg-gray-100 transition-colors disabled:opacity-40"
                disabled={entries.page <= 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
              >
                Previous
              </button>
              <button
                className="px-3 py-1 rounded text-xs border border-gray-200 bg-white text-gray-600 hover:bg-gray-100 transition-colors disabled:opacity-40"
                disabled={entries.page >= entries.pages}
                onClick={() => setPage((p) => Math.min(entries.pages, p + 1))}
              >
                Next
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Footer: oldest / newest */}
      <div className="mt-6 flex items-center justify-between text-xs text-gray-400">
        <span>
          {stats?.oldest_entry
            ? `Oldest entry: ${formatDate(stats.oldest_entry)}`
            : ''}
        </span>
        <span>
          {stats?.newest_entry
            ? `Newest entry: ${formatDate(stats.newest_entry)}`
            : ''}
        </span>
      </div>
    </div>
  );
};

export default MemoryPage;
