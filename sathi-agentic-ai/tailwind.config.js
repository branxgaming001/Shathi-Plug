/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './ui/**/*.{ts,tsx}',
    './templates/**/*.php',
  ],
  theme: {
    extend: {
      colors: {
        // Sathi brand — Violet (primary). Token name kept as rai-blue so existing
        // classes adopt the new palette without renaming across the UI.
        'rai-blue': {
          50:  '#EDEAFE',
          100: '#DDD6FE',
          200: '#C9BEFF',
          300: '#A99CFB',
          400: '#8E7DFB',
          500: '#7C6FFB',
          600: '#6D5DFB',
          700: '#5646E0',
          800: '#4636C0',
          900: '#2E2470',
        },
        // Sathi brand — Spark Coral (action/accent)
        'rai-gold': {
          50:  '#FFEDE9',
          100: '#FFD9D2',
          400: '#FF8B7E',
          500: '#FF6B5E',
          600: '#E85546',
        },
        'rai-black': '#1E1B3A',
        // Explicit Sathi tokens (also available by their own names)
        'sathi-violet': '#6D5DFB',
        'sathi-coral': '#FF6B5E',
        'sathi-mint': '#19C37D',
        'sathi-sunny': '#FFC542',
        // Legacy alias kept so older classes don't break; mapped to RAI blue.
        sathi: {
          50:  'var(--sathi-50, #E4E7ED)',
          100: 'var(--sathi-100, #BFC8D6)',
          200: 'var(--sathi-200, #BFC8D6)',
          300: 'var(--sathi-300, #7B8DA9)',
          400: 'var(--sathi-400, #7B8DA9)',
          500: 'var(--sathi-500, #2C5191)',
          600: 'var(--sathi-600, #1B3A6B)',
          700: 'var(--sathi-700, #122647)',
          800: 'var(--sathi-800, #122647)',
          900: 'var(--sathi-900, #0A1629)',
          950: 'var(--sathi-950, #0A1629)',
        },
      },
      fontFamily: {
        sans: ['Plus Jakarta Sans', 'Inter', 'system-ui', '-apple-system', 'sans-serif'],
        display: ['Baloo 2', 'system-ui', 'sans-serif'],
        sora: ['Plus Jakarta Sans', 'system-ui', 'sans-serif'],
        mono: ['JetBrains Mono', 'Fira Code', 'monospace'],
      },
      spacing: {
        // Fibonacci scale (RAI layout system)
        'fib-1': '8px', 'fib-2': '13px', 'fib-3': '21px', 'fib-4': '34px', 'fib-5': '55px',
      },
      borderRadius: {
        'bubble': '1.25rem',
        'bubble-sm': '0.75rem',
      },
      animation: {
        'fade-in': 'fadeIn 0.2s ease-out',
        'slide-up': 'slideUp 0.3s ease-out',
        'slide-down': 'slideDown 0.3s ease-out',
        'pulse-dot': 'pulseDot 1.4s infinite ease-in-out',
        'bounce-gentle': 'bounceGentle 0.5s ease-out',
      },
      keyframes: {
        fadeIn: {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' },
        },
        slideUp: {
          '0%': { opacity: '0', transform: 'translateY(10px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        slideDown: {
          '0%': { opacity: '0', transform: 'translateY(-10px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        pulseDot: {
          '0%, 80%, 100%': { opacity: '0.2', transform: 'scale(0.8)' },
          '40%': { opacity: '1', transform: 'scale(1)' },
        },
        bounceGentle: {
          '0%, 100%': { transform: 'scale(1)' },
          '50%': { transform: 'scale(1.05)' },
        },
      },
      boxShadow: {
        'chat': '0 4px 24px rgba(0, 0, 0, 0.12), 0 1px 4px rgba(0, 0, 0, 0.08)',
        'chat-sm': '0 2px 8px rgba(0, 0, 0, 0.08)',
        'float': '0 8px 32px rgba(124, 58, 237, 0.15), 0 2px 8px rgba(0, 0, 0, 0.1)',
      },
      screens: {
        'xs': '380px',
      },
    },
  },
  plugins: [],
};
