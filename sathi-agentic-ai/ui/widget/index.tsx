import './widget.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';

// Wait for DOM ready, then mount the widget
function mount() {
  const el = document.getElementById('sathi-chat-root');
  if (!el) return;

  const root = createRoot(el);
  root.render(React.createElement(App));

  // Also mount any embedded [sathi_chat] shortcode instances
  document.querySelectorAll<HTMLElement>('.sathi-chat-embedded').forEach((mount) => {
    const persona = mount.dataset.persona || 'sathi-guru';
    const embeddedRoot = createRoot(mount);
    embeddedRoot.render(
      React.createElement(App, { embedded: true, defaultPersona: persona })
    );
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount);
} else {
  mount();
}
