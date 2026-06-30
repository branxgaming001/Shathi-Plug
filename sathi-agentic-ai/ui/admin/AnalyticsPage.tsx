import React, { useEffect, useState } from 'react';

declare global {
  interface Window {
    sathiAdmin: { restUrl: string; nonce: string; siteName: string; accentColor: string; version: string };
  }
}

const admin = window.sathiAdmin || {};

interface UsageStats {
  total_requests: number;
  total_input_tokens: number;
  total_output_tokens: number;
  total_cost: number;
  monthly_cap: number;
  cap_reached: boolean;
  by_provider: Array<{
    provider: string;
    requests: string;
    total_in: string;
    total_out: string;
    total_cost: string;
  }>;
  daily: Array<{ date: string; cost: string; tokens: string }>;
}

const AnalyticsPage: React.FC = () => {
  const [stats, setStats] = useState<UsageStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [dateRange, setDateRange] = useState('30d');

  const fetchStats = async () => {
    setLoading(true);
    try {
      const res = await fetch(`${admin.restUrl}/settings/usage?range=${dateRange}`, {
        headers: { 'X-WP-Nonce': admin.nonce },
      });
      if (res.ok) {
        setStats(await res.json());
      }
    } catch (e) {
      console.error('[Saathi Analytics]', e);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { fetchStats(); }, [dateRange]);

  const formatTokens = (n: number) =>
    n >= 1_000_000 ? `${(n / 1_000_000).toFixed(1)}M` :
    n >= 1_000 ? `${(n / 1_000).toFixed(1)}K` : String(n);

  const formatCost = (n: number) => `$${n.toFixed(4)}`;

  const maxDailyCost = Math.max(...(stats?.daily.map((d: any) => parseFloat(d.cost)) || [0]), 0.001);

  if (loading) {
    return (
      <div className="sathi-admin-loading py-12 text-center text-gray-400">
        <div className="animate-spin w-8 h-8 border-2 border-sathi-600 border-t-transparent rounded-full mx-auto mb-4" />
        Loading analytics...
      </div>
    );
  }

  return (
    <div>
      {/* Date range selector */}
      <div className="flex items-center gap-2 mb-6">
        {['7d', '30d', '90d'].map((range) => (
          <button
            key={range}
            className={`px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
              dateRange === range ? 'bg-sathi-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
            }`}
            onClick={() => setDateRange(range)}
          >
            {range}
          </button>
        ))}
      </div>

      {/* Summary cards */}
      <div className="grid grid-cols-4 gap-4 mb-6">
        <StatCard label="Requests" value={String(stats?.total_requests || 0)} sub="" />
        <StatCard label="Input Tokens" value={formatTokens(stats?.total_input_tokens || 0)} sub="" />
        <StatCard label="Output Tokens" value={formatTokens(stats?.total_output_tokens || 0)} sub="" />
        <StatCard
          label={stats?.monthly_cap && stats.monthly_cap > 0 ? `Cost (cap: $${stats.monthly_cap})` : 'Total Cost'}
          value={formatCost(stats?.total_cost || 0)}
          sub={stats?.cap_reached ? '⚠️ Cap reached' : ''}
        />
      </div>

      {/* Mini bar chart */}
      {stats?.daily && stats.daily.length > 0 && (
        <div className="mb-6 p-4 rounded-xl border border-gray-200 bg-white">
          <h4 className="text-sm font-semibold text-gray-900 mb-3">Daily Cost</h4>
          <div className="flex items-end gap-1 h-24">
            {stats.daily.map((day: any, i: number) => {
              const h = maxDailyCost > 0 ? (parseFloat(day.cost) / maxDailyCost) * 100 : 0;
              return (
                <div
                  key={i}
                  className="flex-1 rounded-t-sm transition-all hover:opacity-80"
                  style={{ height: `${Math.max(h, 1)}%`, background: admin.accentColor || '#7c3aed' }}
                  title={`${day.date}: $${parseFloat(day.cost).toFixed(4)}`}
                />
              );
            })}
          </div>
        </div>
      )}

      {/* Per-provider breakdown */}
      {stats?.by_provider && stats.by_provider.length > 0 && (
        <div className="overflow-hidden rounded-xl border border-gray-200">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 text-gray-500">
              <tr>
                <th className="text-left px-4 py-2.5 font-medium">Provider</th>
                <th className="text-right px-4 py-2.5 font-medium">Requests</th>
                <th className="text-right px-4 py-2.5 font-medium">Tokens In</th>
                <th className="text-right px-4 py-2.5 font-medium">Tokens Out</th>
                <th className="text-right px-4 py-2.5 font-medium">Cost</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {stats.by_provider.map((row: any) => (
                <tr key={row.provider} className="hover:bg-gray-50">
                  <td className="px-4 py-2.5 font-medium text-gray-900 capitalize">{row.provider}</td>
                  <td className="px-4 py-2.5 text-right text-gray-600">{row.requests}</td>
                  <td className="px-4 py-2.5 text-right text-gray-600">{formatTokens(parseInt(row.total_in))}</td>
                  <td className="px-4 py-2.5 text-right text-gray-600">{formatTokens(parseInt(row.total_out))}</td>
                  <td className="px-4 py-2.5 text-right font-medium text-gray-900">{formatCost(parseFloat(row.total_cost))}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
};

const StatCard: React.FC<{ label: string; value: string; sub: string }> = ({ label, value, sub }) => (
  <div className="p-4 rounded-xl border border-gray-200 bg-white">
    <div className="text-xs text-gray-500 mb-1">{label}</div>
    <div className="text-xl font-bold text-gray-900">{value}</div>
    {sub && <div className="text-xs text-amber-600 mt-1">{sub}</div>}
  </div>
);

export default AnalyticsPage;
