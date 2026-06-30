import './admin.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import AdminPanel from './AdminPanel';
import MemoryPage from './MemoryPage';
import PersonaStudio from './PersonaStudio';

function mount() {
  const dashboardEl = document.getElementById('sathi-admin-dashboard');
  if (dashboardEl) {
    const root = createRoot(dashboardEl);
    root.render(React.createElement(AdminPanel));
    return;
  }

  const settingsEl = document.getElementById('sathi-admin-settings');
  if (settingsEl) {
    const root = createRoot(settingsEl);
    root.render(React.createElement(AdminPanel));
    return;
  }

  const memoryEl = document.getElementById('sathi-admin-memory');
  if (memoryEl) {
    const root = createRoot(memoryEl);
    root.render(React.createElement(MemoryPage));
    return;
  }

  const personasEl = document.getElementById('sathi-admin-personas');
  if (personasEl) {
    const root = createRoot(personasEl);
    root.render(React.createElement(PersonaStudio));
    return;
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount);
} else {
  mount();
}
