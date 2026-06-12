import React, { useEffect, useState } from 'react';

/**
 * Crossfades between a mascot's expression frames (neutral → laughing → …) so
 * the avatar feels alive. With a single frame it just shows the image.
 * Respects prefers-reduced-motion (then it stays on the first frame).
 */
const AnimatedAvatar: React.FC<{
  frames?: string[];
  fallback?: string;
  size?: number;
  intervalMs?: number;
  style?: React.CSSProperties;
}> = ({ frames, fallback, size = 46, intervalMs = 3600, style }) => {
  const list = (frames && frames.length ? frames : (fallback ? [fallback] : []));
  const [i, setI] = useState(0);

  const reduce =
    typeof window !== 'undefined' &&
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  useEffect(() => {
    if (list.length < 2 || reduce) return;
    // Slightly randomized so multiple avatars don't blink in lockstep.
    const t = setInterval(() => setI((p) => (p + 1) % list.length), intervalMs + Math.random() * 800);
    return () => clearInterval(t);
  }, [list.length, intervalMs, reduce]);

  if (!list.length) return null;

  return (
    <span style={{ position: 'relative', display: 'inline-block', width: size, height: size, ...style }}>
      {list.map((src, idx) => (
        <img
          key={idx}
          src={src}
          alt=""
          style={{
            position: 'absolute',
            top: 0,
            left: 0,
            width: '100%',
            height: '100%',
            objectFit: 'contain',
            opacity: idx === i ? 1 : 0,
            transition: 'opacity .55s ease',
          }}
        />
      ))}
    </span>
  );
};

export default AnimatedAvatar;
