import React, { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { config } from './store';

/* ═══════════════════════════════════════════════════════════════════════
   ThemeCustomizer — Floating theme panel (gear icon → side panel)
   Persists to localStorage + WordPress REST API (when available).
   ═══════════════════════════════════════════════════════════════════ */

// ── Types ────────────────────────────────────────────────────────────────

type BubbleStyle = 'rounded' | 'square';
type FontSize = 'sm' | 'md' | 'lg';
type Position = 'bottom-right' | 'bottom-left' | 'top-right' | 'top-left';
type ColorPresetId = 'purple' | 'ocean' | 'emerald' | 'sunset' | 'rose' | 'slate' | 'custom';

interface ThemeSettings {
  colorPreset: ColorPresetId;
  accentColor: string;
  fontSize: FontSize;
  bubbleStyle: BubbleStyle;
  position: Position;
  darkMode: 'auto' | 'dark' | 'light';
}

interface ColorPreset {
  id: ColorPresetId;
  name: string;
  color: string;
  gradient: string;
}

// ── Constants ────────────────────────────────────────────────────────────

const LS_KEY = 'sathi_theme_settings';

const DEFAULT_SETTINGS: ThemeSettings = {
  colorPreset: 'purple',
  accentColor: '#6D5DFB',
  fontSize: 'md',
  bubbleStyle: 'rounded',
  position: 'bottom-right',
  darkMode: 'auto',
};

const COLOR_PRESETS: ColorPreset[] = [
  { id: 'purple',  name: 'Purple',  color: '#6D5DFB', gradient: 'linear-gradient(135deg, #6D5DFB, #a78bfa)' },
  { id: 'ocean',   name: 'Ocean',   color: '#0ea5e9', gradient: 'linear-gradient(135deg, #0ea5e9, #38bdf8)' },
  { id: 'emerald', name: 'Emerald', color: '#10b981', gradient: 'linear-gradient(135deg, #10b981, #34d399)' },
  { id: 'sunset',  name: 'Sunset',  color: '#f97316', gradient: 'linear-gradient(135deg, #f97316, #fb923c)' },
  { id: 'rose',    name: 'Rose',    color: '#ec4899', gradient: 'linear-gradient(135deg, #ec4899, #f472b6)' },
  { id: 'slate',   name: 'Slate',   color: '#64748b', gradient: 'linear-gradient(135deg, #64748b, #94a3b8)' },
];

const FONT_SIZE_OPTIONS: { value: FontSize; label: string; px: string }[] = [
  { value: 'sm', label: 'S', px: '13px' },
  { value: 'md', label: 'M', px: '14px' },
  { value: 'lg', label: 'L', px: '16px' },
];

const BUBBLE_STYLE_OPTIONS: { value: BubbleStyle; label: string; icon: React.ReactNode }[] = [
  {
    value: 'rounded',
    label: 'Rounded',
    icon: (
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
        <rect x="2" y="4" width="20" height="14" rx="7" ry="7" />
      </svg>
    ),
  },
  {
    value: 'square',
    label: 'Square',
    icon: (
      <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
        <rect x="2" y="4" width="20" height="14" rx="1" ry="1" />
      </svg>
    ),
  },
];

const POSITION_OPTIONS: { value: Position; label: string }[] = [
  { value: 'bottom-right', label: 'Bottom Right' },
  { value: 'bottom-left',  label: 'Bottom Left' },
  { value: 'top-right',    label: 'Top Right' },
  { value: 'top-left',     label: 'Top Left' },
];

// ── HSL color generation helpers ─────────────────────────────────────────

/** Parse a hex color to HSL components */
function hexToHSL(hex: string): { h: number; s: number; l: number } {
  let r = 0, g = 0, b = 0;
  const clean = hex.replace('#', '');

  if (clean.length === 3) {
    r = parseInt(clean[0] + clean[0], 16) / 255;
    g = parseInt(clean[1] + clean[1], 16) / 255;
    b = parseInt(clean[2] + clean[2], 16) / 255;
  } else if (clean.length >= 6) {
    r = parseInt(clean.substring(0, 2), 16) / 255;
    g = parseInt(clean.substring(2, 4), 16) / 255;
    b = parseInt(clean.substring(4, 6), 16) / 255;
  }

  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const l = (max + min) / 2;

  let h = 0;
  let s = 0;

  if (max !== min) {
    const d = max - min;
    s = l > 0.5 ? d / (2 - max - min) : d / (max + min);

    switch (max) {
      case r: h = ((g - b) / d + (g < b ? 6 : 0)) / 6; break;
      case g: h = ((b - r) / d + 2) / 6; break;
      case b: h = ((r - g) / d + 4) / 6; break;
    }
  }

  return {
    h: Math.round(h * 360),
    s: Math.round(s * 100),
    l: Math.round(l * 100),
  };
}

/** Generate a full palette of CSS variable values from a single accent hex */
function generatePalette(hex: string): Record<string, string> {
  const { h, s } = hexToHSL(hex);
  return {
    '--sathi-50':  `hsl(${h}, ${s}%, 96%)`,
    '--sathi-100': `hsl(${h}, ${s}%, 91%)`,
    '--sathi-200': `hsl(${h}, ${s}%, 84%)`,
    '--sathi-300': `hsl(${h}, ${s}%, 72%)`,
    '--sathi-400': `hsl(${h}, ${s}%, 60%)`,
    '--sathi-500': `hsl(${h}, ${s}%, 48%)`,
    '--sathi-600': hex,
    '--sathi-700': `hsl(${h}, ${s}%, 38%)`,
    '--sathi-800': `hsl(${h}, ${s}%, 28%)`,
    '--sathi-900': `hsl(${h}, ${s}%, 20%)`,
    '--sathi-950': `hsl(${h}, ${s}%, 12%)`,
  };
}

// ── Persistent state helpers ─────────────────────────────────────────────

function loadSettings(): ThemeSettings {
  try {
    const raw = localStorage.getItem(LS_KEY);
    if (raw) {
      const parsed = JSON.parse(raw);
      return { ...DEFAULT_SETTINGS, ...parsed };
    }
  } catch {
    // Corrupt storage — reset
    localStorage.removeItem(LS_KEY);
  }
  return { ...DEFAULT_SETTINGS };
}

function saveSettings(settings: ThemeSettings): void {
  try {
    localStorage.setItem(LS_KEY, JSON.stringify(settings));
  } catch {
    // Storage full or unavailable — silently ignore
  }
}

/** Save to WordPress REST API (admin-only; fails silently for guests) */
async function saveToRestApi(settings: Partial<ThemeSettings>): Promise<boolean> {
  try {
    const body: Record<string, string> = {};
    if (settings.accentColor) body.sathi_accent_color = settings.accentColor;
    if (settings.position) body.sathi_floating_position = settings.position;

    const res = await fetch(`${config.restUrl}/settings`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce,
      },
      body: JSON.stringify(body),
    });

    return res.ok;
  } catch {
    return false;
  }
}

// ── Apply theme to DOM ────────────────────────────────────────────────────

function applyTheme(settings: ThemeSettings): void {
  const root = document.querySelector<HTMLElement>('.sathi-floating-root');
  if (!root) return;

  // Apply color palette
  const palette = generatePalette(settings.accentColor);
  Object.entries(palette).forEach(([key, value]) => {
    root.style.setProperty(key, value);
  });

  // Font size
  const fontSizeMap: Record<FontSize, string> = {
    sm: '0.8125rem',
    md: '0.875rem',
    lg: '1rem',
  };
  root.style.setProperty('--sathi-font-size-base', fontSizeMap[settings.fontSize]);

  // Bubble border radius
  if (settings.bubbleStyle === 'square') {
    root.classList.add('sathi-bubble-square');
  } else {
    root.classList.remove('sathi-bubble-square');
  }

  // Dark mode
  root.classList.remove('sathi-dark', 'sathi-light');
  if (settings.darkMode === 'dark') {
    root.classList.add('sathi-dark');
  } else if (settings.darkMode === 'light') {
    root.classList.add('sathi-light');
  }
  // 'auto' — no class, let prefers-color-scheme handle it

  // Position
  root.setAttribute('data-sathi-position', settings.position);

  // Notify other components (e.g., header dark mode toggle) of theme change
  document.dispatchEvent(
    new CustomEvent('sathi:theme-changed', { detail: settings })
  );
}

// ═══════════════════════════════════════════════════════════════════════
// Component
// ═══════════════════════════════════════════════════════════════════════

interface ThemeCustomizerProps {
  /** Called when dark mode is toggled externally (e.g., from header button) */
  onDarkModeChange?: (mode: 'dark' | 'light' | 'auto') => void;
  /** Current persona color for initial accent */
  personaColor?: string;
}

const ThemeCustomizer: React.FC<ThemeCustomizerProps> = ({
  onDarkModeChange,
  personaColor,
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const [settings, setSettings] = useState<ThemeSettings>(() => loadSettings());
  const [saving, setSaving] = useState(false);
  const toastTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const toggleRef = useRef<HTMLButtonElement>(null);

  // Sync persona color on first load if no custom theme saved
  useEffect(() => {
    if (personaColor && personaColor !== '#6D5DFB' && !localStorage.getItem(LS_KEY)) {
      const newSettings = {
        ...DEFAULT_SETTINGS,
        accentColor: personaColor,
        colorPreset: 'custom' as ColorPresetId,
      };
      setSettings(newSettings);
      saveSettings(newSettings);
      applyTheme(newSettings);
    }
  }, [personaColor]);

  // Apply theme on mount and whenever settings change
  useEffect(() => {
    applyTheme(settings);
    saveSettings(settings);
  }, [settings]);

  // Notify parent about dark mode changes
  useEffect(() => {
    onDarkModeChange?.(settings.darkMode);
  }, [settings.darkMode, onDarkModeChange]);

  // ESC key closes panel
  useEffect(() => {
    if (!isOpen) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setIsOpen(false);
        toggleRef.current?.focus();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen]);

  // Focus trap inside panel
  useEffect(() => {
    if (!isOpen || !panelRef.current) return;

    const panel = panelRef.current;
    const focusableSelector =
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
    const focusable = panel.querySelectorAll<HTMLElement>(focusableSelector);
    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    const handleTab = (e: KeyboardEvent) => {
      if (e.key !== 'Tab') return;

      if (e.shiftKey) {
        if (document.activeElement === first) {
          e.preventDefault();
          last?.focus();
        }
      } else {
        if (document.activeElement === last) {
          e.preventDefault();
          first?.focus();
        }
      }
    };

    document.addEventListener('keydown', handleTab);
    // Focus first element on open
    requestAnimationFrame(() => first?.focus());

    return () => document.removeEventListener('keydown', handleTab);
  }, [isOpen]);

  // Show toast notification
  const showToast = useCallback((message: string) => {
    setToast(message);
    if (toastTimer.current) clearTimeout(toastTimer.current);
    toastTimer.current = setTimeout(() => setToast(null), 2500);
  }, []);

  // Cleanup toast timer
  useEffect(() => {
    return () => {
      if (toastTimer.current) clearTimeout(toastTimer.current);
    };
  }, []);

  // ── Handlers ──────────────────────────────────────────────────────────

  const updateColorPreset = useCallback((preset: ColorPreset) => {
    setSettings((prev) => ({
      ...prev,
      colorPreset: preset.id,
      accentColor: preset.color,
    }));
  }, []);

  const updateCustomColor = useCallback((hex: string) => {
    if (/^#[0-9a-fA-F]{6}$/.test(hex)) {
      setSettings((prev) => ({
        ...prev,
        colorPreset: 'custom',
        accentColor: hex,
      }));
    }
  }, []);

  const updateFontSize = useCallback((size: FontSize) => {
    setSettings((prev) => ({ ...prev, fontSize: size }));
  }, []);

  const updateBubbleStyle = useCallback((style: BubbleStyle) => {
    setSettings((prev) => ({ ...prev, bubbleStyle: style }));
  }, []);

  const updatePosition = useCallback((pos: Position) => {
    setSettings((prev) => ({ ...prev, position: pos }));
  }, []);

  const cycleDarkMode = useCallback(() => {
    setSettings((prev) => {
      const modes: Array<'auto' | 'dark' | 'light'> = ['auto', 'dark', 'light'];
      const idx = modes.indexOf(prev.darkMode);
      return { ...prev, darkMode: modes[(idx + 1) % 3] };
    });
  }, []);

  const resetToDefaults = useCallback(() => {
    setSettings({ ...DEFAULT_SETTINGS });
    showToast('Theme reset to defaults');
  }, [showToast]);

  const saveToServer = useCallback(async () => {
    setSaving(true);
    const ok = await saveToRestApi({
      accentColor: settings.accentColor,
      position: settings.position,
    });
    setSaving(false);
    showToast(ok ? 'Saved to server' : 'Server save failed — stored locally');
  }, [settings.accentColor, settings.position, showToast]);

  // ── Derived data ───────────────────────────────────────────────────────

  const activePreset = COLOR_PRESETS.find((p) => p.id === settings.colorPreset);

  const darkModeLabel = settings.darkMode === 'auto'
    ? 'Auto'
    : settings.darkMode === 'dark'
    ? 'Dark'
    : 'Light';

  const fontSizeIndex = FONT_SIZE_OPTIONS.findIndex((o) => o.value === settings.fontSize);

  // ── Render ─────────────────────────────────────────────────────────────

  return (
    <>
      {/* ── Gear icon toggle ─────────────────────────────────────── */}
      <button
        ref={toggleRef}
        className="sathi-theme-toggle-btn"
        onClick={() => setIsOpen((prev) => !prev)}
        aria-label={isOpen ? 'Close theme settings' : 'Open theme settings'}
        aria-expanded={isOpen}
        aria-haspopup="dialog"
        title="Customize theme"
      >
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <circle cx="12" cy="12" r="3" />
          <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z" />
        </svg>
      </button>

      {/* ── Backdrop ──────────────────────────────────────────────── */}
      {isOpen && (
        <div
          className="sathi-theme-backdrop"
          onClick={() => {
            setIsOpen(false);
            toggleRef.current?.focus();
          }}
          aria-hidden="true"
        />
      )}

      {/* ── Theme Panel ───────────────────────────────────────────── */}
      {isOpen && (
        <div
          ref={panelRef}
          className="sathi-theme-panel"
          role="dialog"
          aria-label="Theme customization"
          aria-modal="true"
        >
          {/* Header */}
          <div className="sathi-theme-panel-header">
            <div className="sathi-theme-panel-title">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <circle cx="12" cy="12" r="3" />
                <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z" />
              </svg>
              Customize Theme
            </div>
            <button
              className="sathi-theme-close-btn"
              onClick={() => {
                setIsOpen(false);
                toggleRef.current?.focus();
              }}
              aria-label="Close theme settings"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M18 6L6 18M6 6l12 12" />
              </svg>
            </button>
          </div>

          {/* Body */}
          <div className="sathi-theme-panel-body">
            {/* ── Dark Mode ─────────────────────────────────────── */}
            <div className="sathi-theme-section">
              <span className="sathi-theme-section-label">Appearance</span>
              <button
                className="sathi-style-option sathi-style-option--active"
                onClick={cycleDarkMode}
                aria-label={`Color mode: ${darkModeLabel}. Click to cycle.`}
                style={{ display: 'flex', justifyContent: 'space-between' }}
              >
                <span style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                  {settings.darkMode === 'dark' ? (
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />
                    </svg>
                  ) : settings.darkMode === 'light' ? (
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <circle cx="12" cy="12" r="5" />
                      <path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" />
                    </svg>
                  ) : (
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <rect x="2" y="2" width="20" height="20" rx="4" />
                      <path d="M2 8h20M2 16h20M8 2v20M16 2v20" />
                    </svg>
                  )}
                  Color Mode
                </span>
                <span style={{ fontSize: 'var(--sathi-font-size-xs)', color: 'var(--sathi-text-tertiary)' }}>
                  {darkModeLabel}
                </span>
              </button>
            </div>

            {/* ── Color Presets ──────────────────────────────────── */}
            <div className="sathi-theme-section">
              <span className="sathi-theme-section-label">Accent Color</span>
              <div className="sathi-color-presets">
                {COLOR_PRESETS.map((preset) => (
                  <button
                    key={preset.id}
                    className={`sathi-color-preset ${
                      settings.colorPreset === preset.id ? 'sathi-color-preset--active' : ''
                    }`}
                    style={{ background: preset.gradient }}
                    onClick={() => updateColorPreset(preset)}
                    aria-label={`${preset.name} color palette`}
                    aria-pressed={settings.colorPreset === preset.id}
                  >
                    {settings.colorPreset === preset.id && (
                      <span className="sathi-color-preset-check" aria-hidden="true">
                        ✓
                      </span>
                    )}
                  </button>
                ))}
              </div>

              {/* Custom color picker */}
              <div className="sathi-color-input-wrap">
                <input
                  type="color"
                  className="sathi-color-input"
                  value={settings.accentColor}
                  onChange={(e) => updateCustomColor(e.target.value)}
                  aria-label="Custom accent color"
                  tabIndex={0}
                />
                <input
                  type="text"
                  className="sathi-color-hex"
                  value={settings.accentColor}
                  onChange={(e) => updateCustomColor(e.target.value)}
                  onBlur={(e) => {
                    if (!/^#[0-9a-fA-F]{6}$/.test(e.target.value)) {
                      // Revert to last valid
                      setSettings((prev) => ({ ...prev }));
                    }
                  }}
                  aria-label="Hex color code"
                  maxLength={7}
                  spellCheck={false}
                />
              </div>
            </div>

            {/* ── Font Size ─────────────────────────────────────── */}
            <div className="sathi-theme-section">
              <span className="sathi-theme-section-label">Font Size</span>
              <div className="sathi-font-size-slider">
                <span className="sathi-font-size-label" style={{ fontSize: '0.75rem' }}>
                  {FONT_SIZE_OPTIONS[0].px}
                </span>
                <input
                  type="range"
                  min={0}
                  max={2}
                  step={1}
                  value={fontSizeIndex}
                  onChange={(e) => {
                    const idx = parseInt(e.target.value, 10);
                    updateFontSize(FONT_SIZE_OPTIONS[idx].value);
                  }}
                  aria-label={`Font size: ${FONT_SIZE_OPTIONS[fontSizeIndex].px}`}
                  aria-valuetext={FONT_SIZE_OPTIONS[fontSizeIndex].px}
                />
                <span className="sathi-font-size-label" style={{ fontSize: '1.0625rem' }}>
                  {FONT_SIZE_OPTIONS[2].px}
                </span>
              </div>
              <div style={{ textAlign: 'center', fontSize: 'var(--sathi-font-size-xs)', color: 'var(--sathi-text-tertiary)' }}>
                Current: {FONT_SIZE_OPTIONS[fontSizeIndex].px} — {FONT_SIZE_OPTIONS[fontSizeIndex].label === 'S' ? 'Compact' : FONT_SIZE_OPTIONS[fontSizeIndex].label === 'M' ? 'Default' : 'Large'}
              </div>
            </div>

            {/* ── Bubble Style ──────────────────────────────────── */}
            <div className="sathi-theme-section">
              <span className="sathi-theme-section-label">Bubble Style</span>
              <div className="sathi-style-options">
                {BUBBLE_STYLE_OPTIONS.map((opt) => (
                  <button
                    key={opt.value}
                    className={`sathi-style-option ${
                      settings.bubbleStyle === opt.value ? 'sathi-style-option--active' : ''
                    }`}
                    onClick={() => updateBubbleStyle(opt.value)}
                    aria-label={`${opt.label} bubble style`}
                    aria-pressed={settings.bubbleStyle === opt.value}
                  >
                    {opt.icon}
                    {opt.label}
                  </button>
                ))}
              </div>
            </div>

            {/* ── Position ───────────────────────────────────────── */}
            <div className="sathi-theme-section">
              <span className="sathi-theme-section-label">Widget Position</span>
              <div className="sathi-position-grid">
                {POSITION_OPTIONS.map((pos) => (
                  <button
                    key={pos.value}
                    className={`sathi-position-dot sathi-position-dot--${pos.value === 'bottom-right' ? 'br' : pos.value === 'bottom-left' ? 'bl' : pos.value === 'top-right' ? 'tr' : 'tl'} ${
                      settings.position === pos.value ? 'sathi-position-dot--active' : ''
                    }`}
                    onClick={() => updatePosition(pos.value)}
                    aria-label={`Position: ${pos.label}`}
                    aria-pressed={settings.position === pos.value}
                  />
                ))}
              </div>
            </div>

            {/* ── Save to Server (if admin) ─────────────────────── */}
            {config.nonce && (
              <button
                className="sathi-theme-reset-btn"
                onClick={saveToServer}
                disabled={saving}
                style={{
                  opacity: saving ? 0.6 : 1,
                  cursor: saving ? 'wait' : 'pointer',
                }}
              >
                {saving ? 'Saving…' : 'Save to WordPress'}
              </button>
            )}

            {/* ── Reset ──────────────────────────────────────────── */}
            <button
              className="sathi-theme-reset-btn"
              onClick={resetToDefaults}
            >
              Reset to Defaults
            </button>
          </div>
        </div>
      )}

      {/* ── Toast Notification ──────────────────────────────────── */}
      {toast && (
        <div className="sathi-toast" role="status" aria-live="polite">
          {toast}
        </div>
      )}
    </>
  );
};

export default ThemeCustomizer;
export type { ThemeSettings, ColorPresetId, BubbleStyle, FontSize, Position };
