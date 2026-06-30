import React, { useEffect, useState, useCallback, useRef } from 'react';
import './PersonaStudio.css';

// ── Global Config ──────────────────────────────────────────────────────

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

// ── API Helper ─────────────────────────────────────────────────────────

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

// ── Types ──────────────────────────────────────────────────────────────

interface Persona {
  slug: string;
  name: string;
  role: string;
  description: string;
  tone: string;
  style: string;
  avatar: string;
  color: string;
  system_prompt?: string;
  is_default?: boolean;
  is_predefined?: number;
}

type PersonaFormData = Omit<Persona, 'slug' | 'is_default' | 'is_predefined'>;

const BLANK_FORM: PersonaFormData = {
  name: '',
  role: '',
  description: '',
  tone: '',
  style: '',
  avatar: '🤖',
  color: '#7c3aed',
  system_prompt: '',
};

// ── Emoji Data ─────────────────────────────────────────────────────────

interface EmojiCategory {
  label: string;
  icon: string;
  emojis: string[];
}

const EMOJI_CATEGORIES: EmojiCategory[] = [
  {
    label: 'Faces',
    icon: '😀',
    emojis: [
      '😀','😃','😄','😁','😅','😂','🤣','😊','😇','🙂',
      '😌','😍','🥰','😘','😋','😛','😜','🤪','😎','🤓',
      '🥸','🤩','🥳','😏','😒','😔','😕','🙁','😬','🤔',
      '🤗','🤭','🤫','😶','😐','😑','😴','🤤','😪','😷',
      '🤧','🤮','🤯','🥴','😵','😱','😰','😢','😭','😤',
      '😡','🤬','👿','💀','☠️','🤠','🤑','🤡','👹','👺',
    ],
  },
  {
    label: 'Gestures',
    icon: '👍',
    emojis: [
      '👍','👎','👌','✌️','🤞','🤟','🤘','🤙','👈','👉',
      '👆','👇','☝️','✋','🤚','🖐️','🖖','👋','🤏','✍️',
      '🙏','💪','🦵','🦶','👂','🦻','👃','🧠','🫀','👀',
      '👁️','👅','👄','💋','🫂','🤝','🙌','👏','🧑‍💻','👨‍💻',
      '👩‍💻','🧑‍🎨','👨‍🎨','👩‍🎨','🧑‍🏫','👨‍🏫','👩‍🏫','🧑‍🔬','👨‍🔬','👩‍🔬',
    ],
  },
  {
    label: 'Animals',
    icon: '🐶',
    emojis: [
      '🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯',
      '🦁','🐮','🐷','🐸','🐵','🐔','🐧','🐦','🐤','🦆',
      '🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🐛','🦋',
      '🐌','🐞','🐜','🦗','🕷️','🦂','🐢','🐍','🦎','🦖',
      '🦕','🐙','🦑','🦐','🦞','🐠','🐟','🐡','🦈','🐳',
      '🐋','🐬','🦭','🐊','🐆','🐅','🐘','🦏','🦛','🐪',
    ],
  },
  {
    label: 'Nature',
    icon: '🌸',
    emojis: [
      '🌵','🎄','🌲','🌳','🌴','🌱','🌿','☘️','🍀','🍄',
      '🌾','💐','🌷','🌹','🥀','🌺','🌸','🌼','🌻','🌞',
      '🌝','🌙','⭐','🌟','✨','⚡','🔥','🌈','☀️','☁️',
      '❄️','☃️','⛄','💧','💦','🌊','🪐','🌍','🌎','🌏',
      '💫','☄️','🌪️','🌋','🏔️','⛰️','🏕️','🏖️','🏜️','🏝️',
    ],
  },
  {
    label: 'Food',
    icon: '🍕',
    emojis: [
      '🍏','🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🫐',
      '🍒','🍑','🥭','🍍','🥥','🥝','🍅','🥑','🥦','🌽',
      '🥕','🧄','🧅','🥔','🍞','🥐','🥖','🧀','🥚','🍳',
      '🥩','🍗','🍖','🍔','🍟','🍕','🌭','🥪','🌮','🌯',
      '🍣','🍤','🍙','🍚','🍜','🍝','🍲','🍛','🧁','🍰',
      '🎂','🍩','🍪','🍫','🍿','🍦','🍨','🍭','🍬','☕',
    ],
  },
  {
    label: 'Objects',
    icon: '💡',
    emojis: [
      '💻','🖥️','⌨️','🖱️','🖨️','💾','📱','📲','☎️','📞',
      '📺','📻','🎙️','🎚️','🎛️','🔋','🔌','💡','🔦','🕯️',
      '🧯','🛠️','🔧','🔨','⚙️','🔗','📎','✂️','📏','📐',
      '💉','💊','🩹','🩺','🎓','👑','👒','🎩','💍','💎',
      '📖','📚','📓','📔','📒','📝','✏️','🖊️','🖍️','🎨',
      '🔑','🗝️','🔒','🔓','🏷️','📌','📍','🗑️','📦','🎁',
    ],
  },
  {
    label: 'Activities',
    icon: '⚽',
    emojis: [
      '⚽','🏀','🏈','⚾','🎾','🏐','🏉','🎱','🏓','🏸',
      '🏒','🥊','🥋','🎽','⛸️','🎿','⛷️','🏂','🏄','🏊',
      '🚴','🚵','🏋️','🤸','🤺','🤾','🏌️','🏇','🧘','🎯',
      '🪀','🪁','🎣','🤿','🎮','🎲','🎰','🎳','♟️','🧩',
      '🎸','🎹','🎺','🎻','🥁','🎤','🎧','🎼','🎬','🎭',
    ],
  },
  {
    label: 'Symbols',
    icon: '❤️',
    emojis: [
      '❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔',
      '❣️','💕','💞','💓','💗','💖','💘','💝','💟','🕉️',
      '☮️','✝️','☯️','♻️','✅','❌','⚠️','🚸','🔱','♿',
      '🅿️','Ⓜ️','ℹ️','🔤','🔡','🔠','1️⃣','2️⃣','3️⃣','🔟',
      '▶️','⏯️','⏹️','⏺️','🔀','🔁','🔂','🔄','➕','➖',
    ],
  },
];

// ── Color Presets ──────────────────────────────────────────────────────

const COLOR_PRESETS = [
  '#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16',
  '#22c55e', '#10b981', '#14b8a6', '#06b6d4', '#0ea5e9',
  '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7', '#d946ef',
  '#ec4899', '#f43f5e', '#78716c', '#1e293b', '#64748b',
];

// ── Utilities ──────────────────────────────────────────────────────────

/** Compute relative luminance (0-1) from a hex color. */
function hexLuminance(hex: string): number {
  const h = hex.replace('#', '');
  const r = parseInt(h.substring(0, 2), 16) / 255;
  const g = parseInt(h.substring(2, 4), 16) / 255;
  const b = parseInt(h.substring(4, 6), 16) / 255;
  return 0.299 * r + 0.587 * g + 0.114 * b;
}

/** Choose black or white text for a given background color. */
function contrastText(bgHex: string): string {
  return hexLuminance(bgHex) > 0.55 ? '#1f2937' : '#ffffff';
}

// ── Sub-Components ─────────────────────────────────────────────────────

/** Stat card used in the overview row. */
const StatCard: React.FC<{
  label: string;
  value: string;
  color?: string;
}> = ({ label, value, color }) => (
  <div
    className="sathi-ps-stat"
    style={color ? { borderLeftColor: color } : undefined}
  >
    <span className="sathi-ps-stat-value">{value}</span>
    <span className="sathi-ps-stat-label">{label}</span>
  </div>
);

/** Skeleton placeholder shown while loading. */
const SkeletonCard: React.FC = () => (
  <div className="sathi-ps-card sathi-ps-card--skeleton">
    <div className="sathi-ps-card-avatar skeleton" />
    <div className="sathi-ps-card-body">
      <div className="skeleton-line skeleton-line--name" />
      <div className="skeleton-line skeleton-line--role" />
      <div className="skeleton-line skeleton-line--tone" />
    </div>
  </div>
);

/** Individual persona card in the grid. */
const PersonaCard: React.FC<{
  persona: Persona;
  isSelected: boolean;
  isHighlighted: boolean;
  onClick: () => void;
  onDelete: () => void;
}> = ({ persona, isSelected, isHighlighted, onClick, onDelete }) => {
  const textColor = contrastText(persona.color);

  return (
    <div
      className={`sathi-ps-card ${isSelected ? 'sathi-ps-card--selected' : ''} ${isHighlighted ? 'sathi-ps-card--highlighted' : ''}`}
      style={isSelected ? { borderColor: persona.color, boxShadow: `0 0 0 3px ${persona.color}22` } : undefined}
    >
      {/* Clickable area — opens editor */}
      <button
        type="button"
        className="sathi-ps-card-main"
        onClick={onClick}
        aria-label={`Edit ${persona.name} persona`}
      >
        {/* Avatar */}
        <div
          className="sathi-ps-card-avatar"
          style={{ backgroundColor: persona.color, color: textColor }}
        >
          <span className="sathi-ps-card-avatar-emoji">{persona.avatar}</span>
        </div>

        {/* Info */}
        <div className="sathi-ps-card-body">
          <div className="sathi-ps-card-name">
            {persona.name}
            {persona.is_default && (
              <span
                className="sathi-ps-badge"
                style={{ backgroundColor: persona.color + '18', color: persona.color }}
              >
                Default
              </span>
            )}
            {!persona.is_default && persona.is_predefined === 0 && (
              <span className="sathi-ps-badge sathi-ps-badge--custom">Custom</span>
            )}
          </div>
          <div className="sathi-ps-card-role">{persona.role}</div>
          <div className="sathi-ps-card-tone">{persona.tone}</div>
        </div>

        {/* Color swatch */}
        <div className="sathi-ps-card-swatch" style={{ backgroundColor: persona.color }} />
      </button>

      {/* Action button */}
      <div className="sathi-ps-card-actions">
        <button
          type="button"
          className="sathi-ps-card-action-btn"
          title={persona.is_default ? 'Reset to default' : 'Delete persona'}
          onClick={(e) => { e.stopPropagation(); onDelete(); }}
        >
          {persona.is_default ? (
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M1 4v2h22V4M3 8l1 14h16l1-14M9 11v7M15 11v7" />
              <circle cx="9" cy="6" r="1.5" /><circle cx="15" cy="6" r="1.5" />
              <path d="M9 6h6v2H9z" />
            </svg>
          ) : (
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2" />
            </svg>
          )}
        </button>
      </div>
    </div>
  );
};

/** Inline emoji picker popover. */
const EmojiPicker: React.FC<{
  isOpen: boolean;
  onSelect: (emoji: string) => void;
  onClose: () => void;
  anchorRef: React.RefObject<HTMLElement | null>;
}> = ({ isOpen, onSelect, onClose, anchorRef }) => {
  const [activeTab, setActiveTab] = useState(0);
  const pickerRef = useRef<HTMLDivElement>(null);

  // Close on outside click
  useEffect(() => {
    if (!isOpen) return;
    const handler = (e: MouseEvent) => {
      if (
        pickerRef.current &&
        !pickerRef.current.contains(e.target as Node) &&
        anchorRef.current &&
        !anchorRef.current.contains(e.target as Node)
      ) {
        onClose();
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [isOpen, onClose, anchorRef]);

  // Close on escape
  useEffect(() => {
    if (!isOpen) return;
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const category = EMOJI_CATEGORIES[activeTab] || EMOJI_CATEGORIES[0];

  return (
    <div className="sathi-ps-emoji-picker" ref={pickerRef}>
      {/* Tabs */}
      <div className="sathi-ps-emoji-tabs">
        {EMOJI_CATEGORIES.map((cat, i) => (
          <button
            key={cat.label}
            type="button"
            className={`sathi-ps-emoji-tab ${i === activeTab ? 'sathi-ps-emoji-tab--active' : ''}`}
            onClick={() => setActiveTab(i)}
            title={cat.label}
          >
            {cat.icon}
          </button>
        ))}
      </div>

      {/* Grid */}
      <div className="sathi-ps-emoji-grid">
        {category.emojis.map((emoji) => (
          <button
            key={emoji}
            type="button"
            className="sathi-ps-emoji-item"
            onClick={() => onSelect(emoji)}
            title={emoji}
          >
            {emoji}
          </button>
        ))}
      </div>

      <div className="sathi-ps-emoji-label">{category.label}</div>
    </div>
  );
};

/** Color input with native picker plus preset swatches. */
const ColorPicker: React.FC<{
  value: string;
  onChange: (color: string) => void;
}> = ({ value, onChange }) => (
  <div className="sathi-ps-color-picker">
    {/* Native color input */}
    <div className="sathi-ps-color-input-wrap">
      <input
        type="color"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="sathi-ps-color-input"
      />
      <span className="sathi-ps-color-hex">{value}</span>
    </div>

    {/* Presets */}
    <div className="sathi-ps-color-presets">
      {COLOR_PRESETS.map((preset) => (
        <button
          key={preset}
          type="button"
          className={`sathi-ps-color-preset ${value === preset ? 'sathi-ps-color-preset--active' : ''}`}
          style={{ backgroundColor: preset }}
          onClick={() => onChange(preset)}
          title={preset}
          aria-label={`Color ${preset}`}
        />
      ))}
    </div>
  </div>
);

/** Chat bubble preview showing how a persona will look when responding. */
const PreviewBubble: React.FC<{ persona: PersonaFormData }> = ({ persona }) => {
  const avatarBg = persona.color || '#7c3aed';
  const avatarText = contrastText(avatarBg);

  // Build a sample greeting using the persona fields
  const sampleGreeting =
    persona.tone && persona.role
      ? `Hi there! I'm ${persona.name || 'your assistant'}, your ${persona.role.toLowerCase()}. I'm here to help in a ${persona.tone.toLowerCase()} way. ${persona.style ? persona.style.slice(0, 120) + '…' : 'How can I assist you today?'}`
      : persona.name
      ? `Hello! I'm ${persona.name}, how can I help you today?`
      : 'Fill in the persona details to see a preview of how responses will look.';

  return (
    <div className="sathi-ps-preview">
      <div className="sathi-ps-preview-header">
        <span className="sathi-ps-preview-label">Preview</span>
        <span className="sathi-ps-preview-hint">How this persona appears in chat</span>
      </div>

      {/* Avatar + name badge */}
      <div className="sathi-ps-preview-avatar-row">
        <div
          className="sathi-ps-preview-avatar"
          style={{ backgroundColor: avatarBg, color: avatarText }}
        >
          {persona.avatar || '🤖'}
        </div>
        <div>
          <div className="sathi-ps-preview-name">
            {persona.name || 'Unnamed Persona'}
          </div>
          {persona.role && (
            <div className="sathi-ps-preview-role">{persona.role}</div>
          )}
        </div>
      </div>

      {/* Chat bubble */}
      <div className="sathi-ps-preview-bubble" style={{ borderLeftColor: avatarBg }}>
        <div className="sathi-ps-preview-bubble-text">
          {sampleGreeting}
        </div>
      </div>

      {/* Tone / style summary */}
      {persona.tone && (
        <div className="sathi-ps-preview-meta">
          <span className="sathi-ps-preview-meta-item">
            <strong>Tone:</strong> {persona.tone}
          </span>
          {persona.style && (
            <span className="sathi-ps-preview-meta-item">
              <strong>Style:</strong> {persona.style}
            </span>
          )}
        </div>
      )}

      {/* System prompt preview */}
      {persona.system_prompt && (
        <div className="sathi-ps-preview-system">
          <div className="sathi-ps-preview-system-label">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" />
            </svg>
            Custom System Prompt Active
          </div>
          <div className="sathi-ps-preview-system-text">
            {persona.system_prompt.slice(0, 250)}
            {persona.system_prompt.length > 250 ? '…' : ''}
          </div>
        </div>
      )}
    </div>
  );
};

/** Confirmation dialog for delete / reset operations. */
const ConfirmDialog: React.FC<{
  title: string;
  message: string;
  confirmLabel: string;
  confirmDanger?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}> = ({ title, message, confirmLabel, confirmDanger, onConfirm, onCancel }) => (
  <div className="sathi-ps-overlay" onClick={onCancel}>
    <div
      className="sathi-ps-dialog"
      onClick={(e) => e.stopPropagation()}
      role="alertdialog"
      aria-modal="true"
    >
      <h3 className="sathi-ps-dialog-title">{title}</h3>
      <p className="sathi-ps-dialog-message">{message}</p>
      <div className="sathi-ps-dialog-actions">
        <button
          type="button"
          className="sathi-ps-btn sathi-ps-btn--ghost"
          onClick={onCancel}
        >
          Cancel
        </button>
        <button
          type="button"
          className={`sathi-ps-btn ${confirmDanger ? 'sathi-ps-btn--danger' : 'sathi-ps-btn--primary'}`}
          onClick={onConfirm}
        >
          {confirmLabel}
        </button>
      </div>
    </div>
  </div>
);

// ── PersonaStudio (Main Component) ────────────────────────────────────

const PersonaStudio: React.FC = () => {
  // Data
  const [personas, setPersonas] = useState<Persona[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Editing state
  const [editingSlug, setEditingSlug] = useState<string | null>(null); // null | slug | 'new'
  const [editData, setEditData] = useState<PersonaFormData>(BLANK_FORM);
  const [saving, setSaving] = useState(false);
  const [saveMessage, setSaveMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Delete state
  const [deleteConfirm, setDeleteConfirm] = useState<Persona | null>(null);

  // Emoji picker state
  const [emojiPickerOpen, setEmojiPickerOpen] = useState(false);
  const emojiAnchorRef = useRef<HTMLButtonElement | null>(null);

  // Preview
  const [showPreview, setShowPreview] = useState(false);

  // ── Data Fetching ──────────────────────────────────────────────────

  const loadPersonas = useCallback(async () => {
    try {
      setError(null);
      const data = await api('/personas');
      setPersonas(data.personas || []);
    } catch (err: any) {
      console.error('[PersonaStudio] Load error:', err);
      setError(err.message || 'Failed to load personas.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadPersonas();
  }, [loadPersonas]);

  // ── Editor Actions ─────────────────────────────────────────────────

  const startEdit = useCallback((slug: string) => {
    const persona = personas.find((p) => p.slug === slug);
    if (!persona) return;

    setEditingSlug(slug);
    setEditData({
      name: persona.name,
      role: persona.role,
      description: persona.description,
      tone: persona.tone,
      style: persona.style,
      avatar: persona.avatar,
      color: persona.color,
      system_prompt: persona.system_prompt || '',
    });
    setSaveMessage(null);
  }, [personas]);

  const startCreate = useCallback(() => {
    setEditingSlug('new');
    setEditData({ ...BLANK_FORM });
    setSaveMessage(null);
    setShowPreview(false);
  }, []);

  const cancelEdit = useCallback(() => {
    setEditingSlug(null);
    setEditData(BLANK_FORM);
    setSaveMessage(null);
    setEmojiPickerOpen(false);
    setShowPreview(false);
  }, []);

  const updateField = useCallback(
    <K extends keyof PersonaFormData>(field: K, value: PersonaFormData[K]) => {
      setEditData((prev) => ({ ...prev, [field]: value }));
      setSaveMessage(null);
    },
    []
  );

  const handleSave = useCallback(async () => {
    // Validate
    if (!editData.name.trim()) {
      setSaveMessage({ type: 'error', text: 'Persona name is required.' });
      return;
    }

    setSaving(true);
    setSaveMessage(null);

    try {
      if (editingSlug === 'new') {
        // Create new
        await api('/personas', {
          method: 'POST',
          body: JSON.stringify(editData),
        });
        setSaveMessage({ type: 'success', text: 'Persona created successfully!' });
      } else {
        // Update existing
        await api(`/personas/${editingSlug}`, {
          method: 'PUT',
          body: JSON.stringify(editData),
        });
        setSaveMessage({ type: 'success', text: 'Persona updated successfully!' });
      }

      // Reload and keep editor open (handy for refining)
      await loadPersonas();

      // If creating new, switch from 'new' to the new slug
      if (editingSlug === 'new') {
        // Find the new persona by name
        const freshPersonas = await api('/personas');
        const created = (freshPersonas.personas || []).find(
          (p: Persona) => p.name === editData.name && !p.is_default
        );
        if (created) {
          setEditingSlug(created.slug);
          setEditData((prev) => ({ ...prev }));
        }
      }
    } catch (err: any) {
      console.error('[PersonaStudio] Save error:', err);
      setSaveMessage({
        type: 'error',
        text: err.message || 'Failed to save persona.',
      });
    } finally {
      setSaving(false);
    }
  }, [editData, editingSlug, loadPersonas]);

  // ── Delete / Reset ─────────────────────────────────────────────────

  const requestDelete = useCallback(
    (slug: string) => {
      const persona = personas.find((p) => p.slug === slug);
      if (persona) setDeleteConfirm(persona);
    },
    [personas]
  );

  const confirmDelete = useCallback(async () => {
    if (!deleteConfirm) return;

    try {
      if (deleteConfirm.is_default) {
        // Reset to default: PUT with default values
        const defaultPersona = personas.find((p) => p.slug === deleteConfirm.slug && p.is_default);
        if (defaultPersona) {
          // Re-fetch defaults are stored in the backend; PUT empty resets
          await api(`/personas/${deleteConfirm.slug}`, {
            method: 'PUT',
            body: JSON.stringify({
              system_prompt: '',
              is_active: 0, // mark for reset
            }),
          });
          // Then re-fetch
          await loadPersonas();
        }
      } else {
        // Delete custom
        await api(`/personas/${deleteConfirm.slug}`, {
          method: 'DELETE',
        });
        await loadPersonas();
      }

      // If we were editing the deleted persona, close the editor
      if (editingSlug === deleteConfirm.slug) {
        cancelEdit();
      }
    } catch (err: any) {
      console.error('[PersonaStudio] Delete error:', err);
    } finally {
      setDeleteConfirm(null);
    }
  }, [deleteConfirm, personas, editingSlug, cancelEdit, loadPersonas]);

  // ── Keyboard Shortcuts ─────────────────────────────────────────────

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !emojiPickerOpen && !deleteConfirm) {
        if (editingSlug) cancelEdit();
      }
      // Ctrl+S to save
      if (
        e.key === 's' &&
        (e.ctrlKey || e.metaKey) &&
        editingSlug &&
        !emojiPickerOpen &&
        !deleteConfirm
      ) {
        e.preventDefault();
        handleSave();
      }
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, [editingSlug, emojiPickerOpen, deleteConfirm, cancelEdit, handleSave]);

  // ── Derived Stats ──────────────────────────────────────────────────

  const defaultCount = personas.filter((p) => p.is_default).length;
  const customCount = personas.filter((p) => !p.is_default).length;
  const isEditing = editingSlug !== null;

  // ── Render: Loading ─────────────────────────────────────────────────

  if (loading) {
    return (
      <div className="sathi-ps-root">
        <div className="sathi-ps-header">
          <div>
            <h1 className="sathi-ps-title">Persona Studio</h1>
            <p className="sathi-ps-subtitle">Create and manage AI agent personalities</p>
          </div>
        </div>
        <div className="sathi-ps-grid">
          {Array.from({ length: 4 }).map((_, i) => (
            <SkeletonCard key={i} />
          ))}
        </div>
      </div>
    );
  }

  // ── Render: Error ───────────────────────────────────────────────────

  if (error && personas.length === 0) {
    return (
      <div className="sathi-ps-root">
        <div className="sathi-ps-error">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <circle cx="12" cy="12" r="10" /><path d="M12 8v4M12 16h.01" />
          </svg>
          <p>Failed to load personas: {error}</p>
          <button
            type="button"
            className="sathi-ps-btn sathi-ps-btn--primary"
            onClick={() => { setLoading(true); loadPersonas(); }}
          >
            Retry
          </button>
        </div>
      </div>
    );
  }

  // ── Render: Main ────────────────────────────────────────────────────

  return (
    <div className="sathi-ps-root">
      {/* ── Header ─────────────────────────────────────────────────── */}
      <div className="sathi-ps-header">
        <div>
          <h1 className="sathi-ps-title">Persona Studio</h1>
          <p className="sathi-ps-subtitle">
            Create and manage AI agent personalities for customer support
          </p>
        </div>
        <div className="sathi-ps-header-actions">
          {/* Preview toggle (only when editing) */}
          {isEditing && (
            <button
              type="button"
              className={`sathi-ps-btn sathi-ps-btn--ghost ${showPreview ? 'sathi-ps-btn--active' : ''}`}
              onClick={() => setShowPreview((v) => !v)}
            >
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <circle cx="12" cy="12" r="3" />
                <path d="M12 5C7 5 2.73 8.11 1 12c1.73 3.89 6 7 11 7s9.27-3.11 11-7c-1.73-3.89-6-7-11-7z" />
              </svg>
              {showPreview ? 'Hide Preview' : 'Preview'}
            </button>
          )}
          {/* Create button (hidden when already creating) */}
          {editingSlug !== 'new' && (
            <button
              type="button"
              className="sathi-ps-btn sathi-ps-btn--primary"
              onClick={startCreate}
            >
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M12 5v14M5 12h14" />
              </svg>
              Create New Persona
            </button>
          )}
        </div>
      </div>

      {/* ── Stats ──────────────────────────────────────────────────── */}
      <div className="sathi-ps-stats-row">
        <StatCard label="Total Personas" value={String(personas.length)} color="#6366f1" />
        <StatCard label="Default Personas" value={String(defaultCount)} color="#10b981" />
        <StatCard label="Custom Personas" value={String(customCount)} color="#f59e0b" />
      </div>

      {/* ── Persona Card Grid ──────────────────────────────────────── */}
      <div className="sathi-ps-grid">
        {personas.map((persona) => (
          <PersonaCard
            key={persona.slug}
            persona={persona}
            isSelected={editingSlug === persona.slug}
            isHighlighted={isEditing && editingSlug !== persona.slug}
            onClick={() => startEdit(persona.slug)}
            onDelete={() => requestDelete(persona.slug)}
          />
        ))}

        {/* "Create new" ghost card — shown when grid is non-empty and not creating new */}
        {personas.length > 0 && editingSlug !== 'new' && (
          <button
            type="button"
            className="sathi-ps-card sathi-ps-card--create"
            onClick={startCreate}
          >
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
              <path d="M12 5v14M5 12h14" />
            </svg>
            <span>New Persona</span>
          </button>
        )}
      </div>

      {/* Empty state */}
      {personas.length === 0 && !loading && (
        <div className="sathi-ps-empty">
          <span className="sathi-ps-empty-icon">🎭</span>
          <h3>No personas found</h3>
          <p>Get started by creating your first custom persona.</p>
          <button
            type="button"
            className="sathi-ps-btn sathi-ps-btn--primary"
            onClick={startCreate}
          >
            Create Persona
          </button>
        </div>
      )}

      {/* ── Inline Editor ──────────────────────────────────────────── */}
      {isEditing && (
        <div
          className={`sathi-ps-editor ${isEditing ? 'sathi-ps-editor--open' : ''}`}
          role="region"
          aria-label={editingSlug === 'new' ? 'Create new persona' : `Edit ${editData.name}`}
        >
          <div className="sathi-ps-editor-inner">
            {/* Editor header */}
            <div className="sathi-ps-editor-header">
              <h2 className="sathi-ps-editor-title">
                {editingSlug === 'new' ? 'Create New Persona' : `Edit: ${editData.name}`}
              </h2>
              <button
                type="button"
                className="sathi-ps-editor-close"
                onClick={cancelEdit}
                aria-label="Close editor"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M18 6L6 18M6 6l12 12" />
                </svg>
              </button>
            </div>

            {/* Save message toast */}
            {saveMessage && (
              <div
                className={`sathi-ps-toast ${saveMessage.type === 'success' ? 'sathi-ps-toast--success' : 'sathi-ps-toast--error'}`}
                role="status"
              >
                {saveMessage.text}
                <button
                  type="button"
                  className="sathi-ps-toast-dismiss"
                  onClick={() => setSaveMessage(null)}
                  aria-label="Dismiss"
                >
                  &times;
                </button>
              </div>
            )}

            {/* Form fields — two-column layout */}
            <div className="sathi-ps-editor-form">
              {/* Left column: core fields */}
              <div className="sathi-ps-editor-col">
                {/* Name */}
                <label className="sathi-ps-field">
                  <span className="sathi-ps-field-label">Name *</span>
                  <input
                    type="text"
                    className="sathi-ps-input"
                    value={editData.name}
                    onChange={(e) => updateField('name', e.target.value)}
                    placeholder="e.g., Support Champion"
                    maxLength={60}
                    autoFocus
                  />
                </label>

                {/* Role */}
                <label className="sathi-ps-field">
                  <span className="sathi-ps-field-label">Role</span>
                  <input
                    type="text"
                    className="sathi-ps-input"
                    value={editData.role}
                    onChange={(e) => updateField('role', e.target.value)}
                    placeholder="e.g., Customer Support Specialist"
                    maxLength={80}
                  />
                </label>

                {/* Description */}
                <label className="sathi-ps-field">
                  <span className="sathi-ps-field-label">Description</span>
                  <textarea
                    className="sathi-ps-textarea"
                    value={editData.description}
                    onChange={(e) => updateField('description', e.target.value)}
                    placeholder="Brief description of this persona's purpose and personality…"
                    rows={3}
                    maxLength={300}
                  />
                </label>

                {/* Tone */}
                <label className="sathi-ps-field">
                  <span className="sathi-ps-field-label">Tone</span>
                  <input
                    type="text"
                    className="sathi-ps-input"
                    value={editData.tone}
                    onChange={(e) => updateField('tone', e.target.value)}
                    placeholder="e.g., friendly and professional"
                    maxLength={80}
                  />
                  <span className="sathi-ps-field-hint">
                    How the persona speaks: "warm and encouraging", "direct and crisp", etc.
                  </span>
                </label>

                {/* Style */}
                <label className="sathi-ps-field">
                  <span className="sathi-ps-field-label">Style</span>
                  <textarea
                    className="sathi-ps-textarea"
                    value={editData.style}
                    onChange={(e) => updateField('style', e.target.value)}
                    placeholder="Writing style instructions: use emoji, short paragraphs, structured answers, etc."
                    rows={3}
                    maxLength={400}
                  />
                  <span className="sathi-ps-field-hint">
                    Specific writing instructions for the AI (emoji usage, formatting, verbosity, etc.)
                  </span>
                </label>

                {/* System Prompt */}
                <label className="sathi-ps-field">
                  <span className="sathi-ps-field-label">
                    System Prompt
                    <span className="sathi-ps-field-badge">Advanced</span>
                  </span>
                  <textarea
                    className="sathi-ps-textarea sathi-ps-textarea--mono"
                    value={editData.system_prompt}
                    onChange={(e) => updateField('system_prompt', e.target.value)}
                    placeholder="Custom system prompt that overrides the auto-generated one. Use {{site_name}}, {{user_name}}, etc. for dynamic values."
                    rows={6}
                    maxLength={2000}
                  />
                  <span className="sathi-ps-field-hint">
                    Leave blank to auto-generate from persona fields. Use placeholders like {'{{'}site_name{'}}'} and {'{{'}user_name{'}}'}.
                  </span>
                </label>
              </div>

              {/* Right column: avatar + color + preview trigger */}
              <div className="sathi-ps-editor-col sathi-ps-editor-col--side">
                {/* Avatar */}
                <div className="sathi-ps-field">
                  <span className="sathi-ps-field-label">Avatar</span>
                  <div className="sathi-ps-avatar-picker">
                    <button
                      ref={emojiAnchorRef}
                      type="button"
                      className="sathi-ps-avatar-btn"
                      style={{
                        backgroundColor: editData.color || '#7c3aed',
                        color: contrastText(editData.color || '#7c3aed'),
                      }}
                      onClick={() => setEmojiPickerOpen((v) => !v)}
                    >
                      <span className="sathi-ps-avatar-btn-emoji">{editData.avatar}</span>
                      <span className="sathi-ps-avatar-btn-label">Change</span>
                    </button>
                    <EmojiPicker
                      isOpen={emojiPickerOpen}
                      onSelect={(emoji) => {
                        updateField('avatar', emoji);
                        setEmojiPickerOpen(false);
                      }}
                      onClose={() => setEmojiPickerOpen(false)}
                      anchorRef={emojiAnchorRef}
                    />
                  </div>
                </div>

                {/* Color */}
                <div className="sathi-ps-field">
                  <span className="sathi-ps-field-label">Accent Color</span>
                  <ColorPicker
                    value={editData.color}
                    onChange={(color) => updateField('color', color)}
                  />
                </div>

                {/* Quick preview of avatar + color */}
                <div className="sathi-ps-field">
                  <span className="sathi-ps-field-label">Card Preview</span>
                  <div className="sathi-ps-card-mini" style={{ borderLeftColor: editData.color || '#7c3aed' }}>
                    <div
                      className="sathi-ps-card-mini-avatar"
                      style={{
                        backgroundColor: editData.color || '#7c3aed',
                        color: contrastText(editData.color || '#7c3aed'),
                      }}
                    >
                      {editData.avatar || '🤖'}
                    </div>
                    <div>
                      <div className="sathi-ps-card-mini-name">{editData.name || 'Unnamed Persona'}</div>
                      <div className="sathi-ps-card-mini-role">{editData.role || 'No role set'}</div>
                      <div className="sathi-ps-card-mini-tone">{editData.tone || 'No tone set'}</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            {/* ── Preview Panel (toggleable) ──────────────────────── */}
            {showPreview && (
              <div className="sathi-ps-editor-preview">
                <PreviewBubble persona={editData} />
              </div>
            )}

            {/* Editor footer */}
            <div className="sathi-ps-editor-footer">
              <button
                type="button"
                className="sathi-ps-btn sathi-ps-btn--ghost"
                onClick={cancelEdit}
                disabled={saving}
              >
                Cancel
              </button>
              <button
                type="button"
                className="sathi-ps-btn sathi-ps-btn--primary"
                onClick={handleSave}
                disabled={saving || !editData.name.trim()}
              >
                {saving ? (
                  <>
                    <span className="sathi-ps-spinner" />
                    Saving…
                  </>
                ) : editingSlug === 'new' ? (
                  'Create Persona'
                ) : (
                  'Save Changes'
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── Delete / Reset Confirmation Dialog ─────────────────────── */}
      {deleteConfirm && (
        <ConfirmDialog
          title={
            deleteConfirm.is_default
              ? `Reset "${deleteConfirm.name}" to default?`
              : `Delete "${deleteConfirm.name}"?`
          }
          message={
            deleteConfirm.is_default
              ? 'This will discard any customizations and restore the original predefined persona settings. This action cannot be undone.'
              : 'This will permanently delete this custom persona. Any chats using this persona will fall back to the default. This action cannot be undone.'
          }
          confirmLabel={deleteConfirm.is_default ? 'Reset to Default' : 'Delete Permanently'}
          confirmDanger
          onConfirm={confirmDelete}
          onCancel={() => setDeleteConfirm(null)}
        />
      )}
    </div>
  );
};

export default PersonaStudio;
