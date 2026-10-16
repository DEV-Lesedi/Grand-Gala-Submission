import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.svg', 'icons.svg'],
      manifest: {
        id: '/',
        name: 'GG Connect — Grand Gala Gardens',
        short_name: 'GG Connect',
        description: 'Book stays, events and tickets at Grand Gala Gardens.',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        background_color: '#f7f5f0',
        theme_color: '#2d5a3d',
        icons: [
          { src: '/icons/pwa-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/pwa-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/icons/maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      // Dev server normally skips the service worker; this turns it on there too so it can be
      // tested without a production build.
      devOptions: {
        enabled: true,
        type: 'module',
      },
      workbox: {
        navigateFallback: '/index.html',
        // Offline use: the catalogue and saved spaces should still open
        // offline. NetworkFirst so a guest with signal always sees current prices, falling back
        // to the cache only when the request fails.
        runtimeCaching: [
          {
            urlPattern: ({ url }) => /^\/api\/spaces(\/\d+)?$/.test(url.pathname),
            handler: 'NetworkFirst',
            options: {
              cacheName: 'gg-spaces',
              networkTimeoutSeconds: 4,
              expiration: { maxEntries: 60, maxAgeSeconds: 60 * 60 * 24 }, // 1 day
              cacheableResponse: { statuses: [0, 200] },
            },
          },
          {
            urlPattern: ({ url }) => url.pathname === '/api/me/favourites',
            handler: 'NetworkFirst',
            options: {
              cacheName: 'gg-favourites',
              networkTimeoutSeconds: 4,
              expiration: { maxEntries: 20, maxAgeSeconds: 60 * 60 * 24 },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
          {
            urlPattern: ({ url }) => url.pathname.startsWith('/photos/') || url.pathname.startsWith('/icons/'),
            handler: 'CacheFirst',
            options: {
              cacheName: 'gg-images',
              expiration: { maxEntries: 120, maxAgeSeconds: 60 * 60 * 24 * 30 }, // 30 days
              cacheableResponse: { statuses: [0, 200] },
            },
          },
        ],
      },
    }),
  ],
})
