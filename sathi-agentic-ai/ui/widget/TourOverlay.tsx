import React, { useEffect, useState, useCallback, useRef } from 'react';
import { useChatStore, config, ClientAction } from './store';

// ── Types ─────────────────────────────────────────────────────────────────

interface TourStep {
  action: ClientAction | null;
  narration: string;
  delay_ms: number;
  is_suggestion?: boolean;
}

interface Suggestion {
  action_type: string;
  params: Record<string, string>;
  narration: string;
  label: string;
}

interface TourData {
  tour_id: string;
  title: string;
  description: string;
  steps: TourStep[];
  suggestions: Suggestion[];
}

/** Tour shape stored in sessionStorage for cross-page persistence. */
interface StoredTour {
  tour_id: string;
  title: string;
  description: string;
  steps: TourStep[];
  current_step: number;
  suggestions: Suggestion[];
}

interface TourOverlayProps {
  /** Trigger a tour immediately via intent string (alternative to custom event). */
  intent?: string;
  /** Current page URL — defaults to window.location.href. */
  currentUrl?: string;
}

interface ElementRect {
  top: number;
  left: number;
  width: number;
  height: number;
}

const SESSION_KEY = 'sathi_pending_tour';
const TOUR_Z_INDEX = 100000;

// ── Helpers ───────────────────────────────────────────────────────────────

/**
 * Resolve the first visible element matching a comma-separated selector list.
 * Returns the best match (first visible, or first found).
 */
function resolveElement(selector: string): HTMLElement | null {
  if (!selector) return null;

  const selectors = selector.split(',').map((s) => s.trim()).filter(Boolean);
  for (const sel of selectors) {
    try {
      const el = document.querySelector(sel) as HTMLElement | null;
      if (el && isElementVisible(el)) return el;
    } catch {
      // Invalid selector — skip
    }
  }

  // Fallback: return first found element even if not visible
  for (const sel of selectors) {
    try {
      const el = document.querySelector(sel) as HTMLElement | null;
      if (el) return el;
    } catch {
      // skip
    }
  }

  return null;
}

/** Heuristic: is the element in the viewport and not display:none? */
function isElementVisible(el: HTMLElement): boolean {
  const rect = el.getBoundingClientRect();
  const style = window.getComputedStyle(el);
  if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') {
    return false;
  }
  return (
    rect.width > 0 &&
    rect.height > 0 &&
    rect.bottom > 0 &&
    rect.right > 0 &&
    rect.top < window.innerHeight &&
    rect.left < window.innerWidth
  );
}

/** Convert an action's selector/url params to a human-readable label. */
function actionTargetLabel(action: ClientAction): string {
  switch (action.type) {
    case 'navigate':
      try {
        const u = new URL(action.params.url);
        return u.pathname || action.params.url;
      } catch {
        return action.params.url || '';
      }
    case 'scroll_to':
    case 'highlight':
    case 'focus_input':
      return action.params.selector || '';
    case 'show_tooltip':
      return action.params.element || '';
    default:
      return '';
  }
}

// ── Styles (injected once) ───────────────────────────────────────────────

const tourStyles = `
.sathi-tour-backdrop {
  position: fixed;
  inset: 0;
  z-index: ${TOUR_Z_INDEX};
  background: rgba(0, 0, 0, 0);
  pointer-events: none;
  transition: background 0.3s ease;
}
.sathi-tour-backdrop.active {
  background: rgba(0, 0, 0, 0.55);
}

.sathi-tour-highlight-box {
  position: fixed;
  z-index: ${TOUR_Z_INDEX + 1};
  border-radius: 8px;
  pointer-events: none;
  transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.7), 0 0 0 9999px rgba(0, 0, 0, 0.55);
}

.sathi-tour-tooltip-card {
  position: fixed;
  z-index: ${TOUR_Z_INDEX + 2};
  pointer-events: auto;
  background: #ffffff;
  border-radius: 14px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.25), 0 0 0 1px rgba(0,0,0,0.06);
  padding: 20px 24px;
  max-width: 360px;
  min-width: 260px;
  animation: sathi-tooltip-in 0.25s ease-out;
  font-family: Inter, system-ui, -apple-system, sans-serif;
}
@keyframes sathi-tooltip-in {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}

.sathi-tour-step-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: #6366f1;
  margin-bottom: 8px;
  letter-spacing: 0.3px;
  text-transform: uppercase;
}

.sathi-tour-narration {
  font-size: 14px;
  line-height: 1.6;
  color: #1e293b;
  margin-bottom: 18px;
}
.sathi-tour-narration strong {
  color: #0f172a;
  font-weight: 600;
}

.sathi-tour-btn-group {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.sathi-tour-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 8px 16px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  border: none;
  cursor: pointer;
  transition: all 0.15s ease;
  font-family: inherit;
  line-height: 1.4;
}
.sathi-tour-btn:focus-visible {
  outline: 2px solid #6366f1;
  outline-offset: 2px;
}
.sathi-tour-btn-primary {
  background: #6366f1;
  color: #fff;
}
.sathi-tour-btn-primary:hover {
  background: #4f46e5;
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(99,102,241,0.35);
}
.sathi-tour-btn-secondary {
  background: #f1f5f9;
  color: #475569;
}
.sathi-tour-btn-secondary:hover {
  background: #e2e8f0;
}
.sathi-tour-btn-skip {
  background: transparent;
  color: #94a3b8;
  font-weight: 500;
  padding: 8px 12px;
}
.sathi-tour-btn-skip:hover {
  color: #64748b;
  background: #f8fafc;
}

.sathi-tour-progress-dots {
  display: flex;
  gap: 5px;
}
.sathi-tour-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #cbd5e1;
  transition: all 0.2s ease;
}
.sathi-tour-dot.active {
  background: #6366f1;
  transform: scale(1.25);
}
.sathi-tour-dot.done {
  background: #a5b4fc;
}

/* highlight pulse animation when step changes */
.sathi-tour-highlight-box.pulse {
  animation: sathi-highlight-pulse 0.6s ease;
}
@keyframes sathi-highlight-pulse {
  0%   { box-shadow: 0 0 0 4px rgba(99,102,241,0.7), 0 0 0 9999px rgba(0,0,0,0.55); }
  50%  { box-shadow: 0 0 0 10px rgba(99,102,241,0.4), 0 0 0 9999px rgba(0,0,0,0.55); }
  100% { box-shadow: 0 0 0 4px rgba(99,102,241,0.7), 0 0 0 9999px rgba(0,0,0,0.55); }
}

/* Reduced motion */
@media (prefers-reduced-motion: reduce) {
  .sathi-tour-highlight-box,
  .sathi-tour-tooltip-card {
    transition: none;
  }
  .sathi-tour-highlight-box.pulse {
    animation: none;
  }
}
`;

// ── Component ─────────────────────────────────────────────────────────────

const TourOverlay: React.FC<TourOverlayProps> = ({ intent, currentUrl }) => {
  const executeActions = useChatStore((s) => s.executeActions);

  // ── State ────────────────────────────────────────────────────────────
  const [tour, setTour] = useState<TourData | null>(null);
  const [stepIndex, setStepIndex] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [highlightRect, setHighlightRect] = useState<ElementRect | null>(null);
  const [tooltipPosition, setTooltipPosition] = useState<{ top: number; left: number } | null>(null);
  const [pulse, setPulse] = useState(false);

  const tooltipRef = useRef<HTMLDivElement>(null);
  const stepsLength = tour?.steps?.length ?? 0;
  const currentStep: TourStep | null = tour?.steps?.[stepIndex] ?? null;
  const isFirst = stepIndex === 0;
  const isLast = stepIndex === stepsLength - 1;

  // ── Fetch tour data ──────────────────────────────────────────────────
  const fetchTour = useCallback(
    async (intentStr: string) => {
      setLoading(true);
      setError(null);
      setStepIndex(0);

      try {
        const url = new URL(`${config.restUrl}/route-map/tour`);
        url.searchParams.set('intent', intentStr);
        url.searchParams.set('current_url', currentUrl ?? window.location.href);

        const res = await fetch(url.toString(), {
          headers: { 'X-WP-Nonce': config.nonce },
        });

        if (!res.ok) {
          throw new Error(`HTTP ${res.status}`);
        }

        const data: TourData = await res.json();
        if (!data.steps || data.steps.length === 0) {
          setError('No tour steps were found for that intent.');
          return;
        }

        setTour(data);
      } catch (err: any) {
        console.error('[Saathi] Tour fetch error:', err);
        setError(err.message || 'Failed to load the tour.');
      } finally {
        setLoading(false);
      }
    },
    [currentUrl]
  );

  // ── Session storage persistence ──────────────────────────────────────
  const saveTourToSession = useCallback(() => {
    if (!tour) return;
    const stored: StoredTour = {
      tour_id: tour.tour_id,
      title: tour.title,
      description: tour.description,
      steps: tour.steps,
      current_step: stepIndex,
      suggestions: tour.suggestions,
    };
    try {
      sessionStorage.setItem(SESSION_KEY, JSON.stringify(stored));
    } catch {
      // sessionStorage unavailable — ignore
    }
  }, [tour, stepIndex]);

  const loadTourFromSession = useCallback((): boolean => {
    try {
      const raw = sessionStorage.getItem(SESSION_KEY);
      if (!raw) return false;

      const stored: StoredTour = JSON.parse(raw);
      if (!stored.steps || stored.steps.length === 0) return false;

      setTour({
        tour_id: stored.tour_id,
        title: stored.title,
        description: stored.description,
        steps: stored.steps,
        suggestions: stored.suggestions,
      });
      setStepIndex(stored.current_step);
      sessionStorage.removeItem(SESSION_KEY);
      return true;
    } catch {
      return false;
    }
  }, []);

  // ── Execute current step's action ────────────────────────────────────
  const executeCurrentAction = useCallback(() => {
    if (!currentStep?.action) return;

    const action = currentStep.action;

    // navigate is handled separately — we store state before navigation
    if (action.type === 'navigate') {
      saveTourToSession();
      // Advance step before navigating so the next page resumes correctly
      const nextIdx = stepIndex + 1;
      const updatedSteps = [...(tour?.steps ?? [])];
      try {
        const stored: StoredTour = {
          tour_id: tour?.tour_id ?? '',
          title: tour?.title ?? '',
          description: tour?.description ?? '',
          steps: updatedSteps,
          current_step: nextIdx < updatedSteps.length ? nextIdx : 0,
          suggestions: tour?.suggestions ?? [],
        };
        sessionStorage.setItem(SESSION_KEY, JSON.stringify(stored));
      } catch {
        // ignore
      }

      // Defer navigation slightly so the user sees the "navigating" message
      setTimeout(() => {
        window.location.href = action.params.url;
      }, 600);
      return;
    }

    // All other actions: execute via store
    executeActions([action]);
  }, [currentStep, executeActions, saveTourToSession, stepIndex, tour]);

  // ── Navigation functions ─────────────────────────────────────────────
  const goNext = useCallback(() => {
    if (isLast) {
      dismissTour();
      return;
    }

    // Execute current step action before moving on
    executeCurrentAction();

    setStepIndex((prev) => prev + 1);
    setPulse(true);
    setTimeout(() => setPulse(false), 600);
  }, [isLast, executeCurrentAction]);

  const goPrev = useCallback(() => {
    if (isFirst) return;
    setStepIndex((prev) => prev - 1);
    setPulse(true);
    setTimeout(() => setPulse(false), 600);
  }, [isFirst]);

  const skipTour = useCallback(() => {
    dismissTour();
  }, []);

  const dismissTour = useCallback(() => {
    setTour(null);
    setStepIndex(0);
    setHighlightRect(null);
    setTooltipPosition(null);
    try {
      sessionStorage.removeItem(SESSION_KEY);
    } catch {
      // ignore
    }
  }, []);

  // ── Keyboard handling ────────────────────────────────────────────────
  useEffect(() => {
    if (!tour) return;

    const handleKey = (e: KeyboardEvent) => {
      switch (e.key) {
        case 'Escape':
          dismissTour();
          break;
        case 'ArrowRight':
        case 'ArrowDown':
          e.preventDefault();
          goNext();
          break;
        case 'ArrowLeft':
        case 'ArrowUp':
          e.preventDefault();
          goPrev();
          break;
      }
    };

    window.addEventListener('keydown', handleKey);
    return () => window.removeEventListener('keydown', handleKey);
  }, [tour, goNext, goPrev, dismissTour]);

  // ── Trigger via intent prop ──────────────────────────────────────────
  useEffect(() => {
    if (intent && !tour) {
      fetchTour(intent);
    }
  }, [intent]); // eslint-disable-line react-hooks/exhaustive-deps

  // ── Listen for custom event "sathi:tour:start" ────────────────────────
  useEffect(() => {
    const handler = (e: Event) => {
      const detail = (e as CustomEvent<{ intent: string }>).detail;
      if (detail?.intent) {
        fetchTour(detail.intent);
      }
    };
    document.addEventListener('sathi:tour:start', handler);
    return () => document.removeEventListener('sathi:tour:start', handler);
  }, [fetchTour]);

  // ── Resume pending tour on mount (after cross-page navigation) ────────
  useEffect(() => {
    loadTourFromSession();
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  // ── Recalculate highlight / tooltip position when step changes ──────
  useEffect(() => {
    if (!currentStep?.action) {
      setHighlightRect(null);
      setTooltipPosition(null);
      return;
    }

    const action = currentStep.action;
    const selector =
      action.type === 'navigate'
        ? null
        : action.type === 'show_tooltip'
          ? action.params.element
          : action.params.selector;

    if (!selector) {
      setHighlightRect(null);
      // Still position tooltip if narration exists
      if (currentStep.narration) {
        setTooltipPosition({
          top: window.innerHeight / 2 - 80,
          left: window.innerWidth / 2 - 180,
        });
      } else {
        setTooltipPosition(null);
      }
      return;
    }

    const el = resolveElement(selector);
    if (!el) {
      setHighlightRect(null);
      setTooltipPosition({
        top: window.innerHeight / 2 - 80,
        left: window.innerWidth / 2 - 180,
      });
      return;
    }

    const rect = el.getBoundingClientRect();
    const padding = 6;

    setHighlightRect({
      top: rect.top - padding + window.scrollY,
      left: rect.left - padding,
      width: rect.width + padding * 2,
      height: rect.height + padding * 2,
    });

    // Position tooltip: prefer below, then above, then right
    // But we measure in viewport coords, so use the rect directly
    requestAnimationFrame(() => {
      const tooltipW = tooltipRef.current?.offsetWidth ?? 340;
      const tooltipH = tooltipRef.current?.offsetHeight ?? 160;
      const gap = 16;

      let top: number;
      let left: number;

      // Below the element
      if (rect.bottom + gap + tooltipH < window.innerHeight - 20) {
        top = rect.bottom + gap;
      }
      // Above the element
      else if (rect.top - gap - tooltipH > 20) {
        top = rect.top - gap - tooltipH;
      }
      // Centered vertically
      else {
        top = Math.max(20, window.innerHeight / 2 - tooltipH / 2);
      }

      // Horizontally: centered on the element, clamped to viewport
      left = Math.max(
        16,
        Math.min(rect.left + rect.width / 2 - tooltipW / 2, window.innerWidth - tooltipW - 16)
      );

      setTooltipPosition({ top, left });
    });
  }, [currentStep, stepIndex]);

  // ── Auto-advance for navigate steps ─────────────────────────────────
  useEffect(() => {
    if (!currentStep) return;
    if (currentStep.action?.type === 'navigate' && tour) {
      // Execute navigation after a brief delay so user can read the tooltip
      const timer = setTimeout(() => {
        executeCurrentAction();
      }, currentStep.delay_ms || 800);
      return () => clearTimeout(timer);
    }
  }, [currentStep, tour, executeCurrentAction]);

  // ── Inject CSS once ──────────────────────────────────────────────────
  useEffect(() => {
    const styleId = 'sathi-tour-styles';
    if (document.getElementById(styleId)) return;
    const styleEl = document.createElement('style');
    styleEl.id = styleId;
    styleEl.textContent = tourStyles;
    document.head.appendChild(styleEl);
    return () => {
      // Only remove if no tour is active
      if (!tour) {
        const el = document.getElementById(styleId);
        el?.remove();
      }
    };
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  // ── Don't render if there's no tour ──────────────────────────────────
  if (!tour && !loading && !error) return null;

  const accentColor = config.persona?.color || '#6366f1';

  // ── Loading state ────────────────────────────────────────────────────
  if (loading) {
    return (
      <div className="sathi-tour-backdrop active" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', pointerEvents: 'auto' }}>
        <div style={{ background: '#fff', borderRadius: 14, padding: '32px 40px', textAlign: 'center', boxShadow: '0 20px 60px rgba(0,0,0,0.2)' }}>
          <div style={{ width: 36, height: 36, border: '3px solid #e2e8f0', borderTopColor: accentColor, borderRadius: '50%', animation: 'sathi-spin 0.7s linear infinite', margin: '0 auto 16px' }} />
          <p style={{ margin: 0, fontSize: 14, color: '#64748b', fontFamily: 'Inter, system-ui, sans-serif' }}>
            Building your tour...
          </p>
          <style>{`@keyframes sathi-spin { to { transform: rotate(360deg); } }`}</style>
        </div>
      </div>
    );
  }

  // ── Error state ──────────────────────────────────────────────────────
  if (error) {
    return (
      <div className="sathi-tour-backdrop active" style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', pointerEvents: 'auto' }}>
        <div style={{ background: '#fff', borderRadius: 14, padding: '28px 32px', textAlign: 'center', boxShadow: '0 20px 60px rgba(0,0,0,0.2)', maxWidth: 320 }}>
          <p style={{ margin: '0 0 16px', fontSize: 14, color: '#ef4444', fontFamily: 'Inter, system-ui, sans-serif' }}>{error}</p>
          <button
            className="sathi-tour-btn sathi-tour-btn-secondary"
            onClick={() => {
              setError(null);
              setTour(null);
            }}
          >
            Dismiss
          </button>
        </div>
      </div>
    );
  }

  // ── Navigation step: show special UI ─────────────────────────────────
  const isNavigating = currentStep?.action?.type === 'navigate';
  const navigateUrl = isNavigating ? currentStep!.action!.params.url : '';

  // ── Tooltip content ──────────────────────────────────────────────────
  const tooltipContent = (
    <>
      {/* Step badge */}
      <div className="sathi-tour-step-badge">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <circle cx="12" cy="12" r="10" />
          <path d="M12 6v6l4 2" />
        </svg>
        Step {stepIndex + 1} of {stepsLength}
      </div>

      {/* Narration */}
      <div
        className="sathi-tour-narration"
        dangerouslySetInnerHTML={{ __html: currentStep?.narration || '' }}
      />

      {/* Navigation-specific message */}
      {isNavigating && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 16, padding: '10px 12px', background: '#eef2ff', borderRadius: 8 }}>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={accentColor} strokeWidth="2" style={{ flexShrink: 0 }}>
            <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6" />
            <polyline points="15 3 21 3 21 9" />
            <line x1="10" y1="14" x2="21" y2="3" />
          </svg>
          <span style={{ fontSize: 12, color: '#4338ca', lineHeight: 1.4 }}>
            Taking you to <strong>{actionTargetLabel(currentStep!.action!)}</strong>...
          </span>
        </div>
      )}

      {/* Suggestion flag */}
      {currentStep?.is_suggestion && (
        <div style={{ fontSize: 11, color: '#6366f1', fontWeight: 600, marginBottom: 12, textTransform: 'uppercase', letterSpacing: '0.5px' }}>
          Suggested next step
        </div>
      )}

      {/* Progress dots */}
      <div className="sathi-tour-progress-dots" style={{ marginBottom: 16 }}>
        {Array.from({ length: stepsLength }).map((_, i) => (
          <div
            key={i}
            className={`sathi-tour-dot ${i === stepIndex ? 'active' : i < stepIndex ? 'done' : ''}`}
          />
        ))}
      </div>

      {/* Buttons */}
      <div className="sathi-tour-btn-group">
        <button className="sathi-tour-btn sathi-tour-btn-skip" onClick={skipTour}>
          Skip tour
        </button>
        <div style={{ display: 'flex', gap: 8 }}>
          {!isFirst && !isNavigating && (
            <button className="sathi-tour-btn sathi-tour-btn-secondary" onClick={goPrev}>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M19 12H5M12 19l-7-7 7-7" />
              </svg>
              Previous
            </button>
          )}
          <button
            className="sathi-tour-btn sathi-tour-btn-primary"
            onClick={isNavigating ? skipTour : goNext}
            style={{ background: isNavigating ? '#94a3b8' : accentColor }}
          >
            {isNavigating ? (
              'Stay here'
            ) : isLast ? (
              <>
                Finish
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M20 6L9 17l-5-5" />
                </svg>
              </>
            ) : (
              <>
                Next
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M5 12h14M12 5l7 7-7 7" />
                </svg>
              </>
            )}
          </button>
        </div>
      </div>
    </>
  );

  return (
    <>
      {/* Backdrop */}
      <div className={`sathi-tour-backdrop ${highlightRect ? 'active' : ''}`} onClick={skipTour} />

      {/* Highlight box (cutout effect) */}
      {highlightRect && (
        <div
          className={`sathi-tour-highlight-box ${pulse ? 'pulse' : ''}`}
          style={{
            top: highlightRect.top,
            left: highlightRect.left,
            width: highlightRect.width,
            height: highlightRect.height,
          }}
        />
      )}

      {/* Tooltip card */}
      {tooltipPosition && (
        <div
          ref={tooltipRef}
          className="sathi-tour-tooltip-card"
          style={{
            top: tooltipPosition.top,
            left: tooltipPosition.left,
          }}
        >
          {tooltipContent}
        </div>
      )}
    </>
  );
};

export default TourOverlay;
export type { TourData, TourStep, Suggestion };
