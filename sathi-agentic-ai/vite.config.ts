import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import tailwindcss from 'tailwindcss';
import autoprefixer from 'autoprefixer';

export default defineConfig({
  // Relative base so the built bundles resolve their chunk/asset imports
  // relative to their own URL inside wp-content/plugins/sathi-agentic-ai/assets/,
  // regardless of where WordPress is installed.
  base: './',
  plugins: [
    react(),
  ],
  build: {
    outDir: 'assets',
    manifest: true,
    rollupOptions: {
      input: {
        'chat-widget': path.resolve(__dirname, 'ui/widget/index.tsx'),
        'admin': path.resolve(__dirname, 'ui/admin/index.tsx'),
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: '[name]-[hash].js',
        manualChunks: (id) => {
          // Lazy-loaded syntax highlighter chunk
          if (id.includes('react-syntax-highlighter')) return 'vendor-syntax';
          if (id.includes('prismjs') || id.includes('refractor')) return 'vendor-syntax';
          // react-markdown
          if (id.includes('react-markdown') || id.includes('mdast') || id.includes('unified') || id.includes('remark') || id.includes('micromark')) return 'vendor-markdown';
          // React + Zustand core
          if (id.includes('node_modules/react-dom') || id.includes('node_modules/react/')) return 'vendor-react';
          if (id.includes('node_modules/zustand')) return 'vendor-react';
          if (id.includes('node_modules/react/')) return 'vendor-react';
        },
        assetFileNames: (assetInfo) => {
          const name = assetInfo.name ?? '';
          if (/\.css$/i.test(name)) return '[name].css';
          if (/\.(png|jpe?g|gif|svg|webp|ico)$/i.test(name)) return 'images/[name].[ext]';
          if (/\.(woff2?|eot|ttf|otf)$/i.test(name)) return 'fonts/[name].[ext]';
          return '[name].[ext]';
        },
      },
    },
    cssCodeSplit: true,
    sourcemap: false,
    minify: 'esbuild',
  },
  css: {
    postcss: {
      plugins: [
        tailwindcss,
        autoprefixer,
      ],
    },
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'ui'),
    },
  },
});
