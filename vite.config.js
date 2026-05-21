import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { copyFileSync, existsSync, readdirSync, unlinkSync } from 'node:fs'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'
import { VitePWA } from 'vite-plugin-pwa'

const currentDir = dirname(fileURLToPath(import.meta.url))

/*
 * laravel-vite-plugin ships hashed assets to public/build/. vite-plugin-pwa,
 * inheriting that buildDir, emits sw.js to public/build/sw.js — which gives
 * the service worker a scope of /build/ and prevents it from controlling
 * the root SPA. This plugin copies sw.js + its workbox runtime to public/
 * after the build so the SW lands at /sw.js with full / scope.
 */
const promoteServiceWorkerToRoot = () => ({
  name: 'kinhold-promote-sw',
  apply: 'build',
  // Run after vite-plugin-pwa has emitted sw.js / workbox-*.js
  enforce: 'post',
  closeBundle: {
    order: 'post',
    sequential: true,
    handler() {
      const buildDir = resolve(currentDir, 'public/build')
      const publicDir = resolve(currentDir, 'public')
      if (!existsSync(buildDir)) return

      // Clean stale workbox-*.js / sw.js from a previous build at public root
      for (const entry of readdirSync(publicDir)) {
        if (entry === 'sw.js' || /^workbox-[A-Za-z0-9_-]+\.js$/.test(entry)) {
          try { unlinkSync(resolve(publicDir, entry)) } catch { /* noop */ }
        }
      }

      for (const entry of readdirSync(buildDir)) {
        if (entry === 'sw.js' || /^workbox-[A-Za-z0-9_-]+\.js$/.test(entry)) {
          copyFileSync(resolve(buildDir, entry), resolve(publicDir, entry))
        }
      }
    },
  },
})

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/app.js'],
      refresh: true,
    }),
    vue({
      template: {
        transformAssetUrls: {
          base: null,
          includeAbsolute: false,
        },
      },
    }),
    VitePWA({
      registerType: 'autoUpdate',
      // We import { registerSW } from 'virtual:pwa-register' explicitly in resources/js/app.js
      injectRegister: false,
      // public/manifest.json is the source of truth (already linked in app.blade.php)
      manifest: false,
      // The Laravel app is served at /, but laravel-vite-plugin sets Vite's base to /build/ for
      // hashed assets. Override the PWA base back to '/' so the SW registers at /sw.js with full
      // app scope. The promoteServiceWorkerToRoot() plugin below copies the emitted file from
      // public/build/sw.js → public/sw.js so the actual file lives where the SW URL points.
      base: '/',
      filename: 'sw.js',
      workbox: {
        // Custom push + notificationclick handlers live in /sw-push.js so we
        // can keep workbox's generateSW strategy for caching (no need to hand-
        // roll a full SW). importScripts prepends `self.importScripts('/sw-push.js')`
        // at the top of the generated file. Update sw-push.js directly — it is
        // committed and loaded as-is at runtime, not bundled.
        importScripts: ['/sw-push.js'],
        // After every deploy the SPA shell points at new hashed asset URLs.
        // Without skipWaiting + clientsClaim the old SW keeps serving the
        // stale shell until every tab closes, so returning users see blank
        // pages with module-load errors (#278). Take over immediately and
        // sweep precaches from prior builds on activation.
        skipWaiting: true,
        clientsClaim: true,
        cleanupOutdatedCaches: true,
        // App shell fallback — the SPA owns every non-API route. workbox
        // resolves this URL against the precache, so '/' MUST be in the
        // precache (see additionalManifestEntries below) or offline
        // navigation will return a 404 instead of the SPA shell.
        navigateFallback: '/',
        navigateFallbackDenylist: [
          /^\/api\//,
          /^\/auth\//,
          /^\/email\//,
          /^\/mcp/,
          /^\/login/,
          /^\/oauth/,
          /^\/r\//,
          /^\/sanctum/,
          /^\/storage\//,
        ],
        // Precache the entry app chunk + global CSS so the SPA boots offline.
        // Code-split route chunks are runtime-cached on first visit (below) —
        // precaching every page chunk would bloat the install footprint to
        // ~2.7MB (208 entries) for routes most users never open.
        globPatterns: ['assets/app-*.{js,css}'],
        globIgnores: ['**/*.map'],
        // SW lives at /sw.js, but the assets it precaches are served from
        // /build/assets/... (laravel-vite-plugin's hashed-asset directory).
        // Prepend /build/ to every precache URL so the cache fetches resolve.
        modifyURLPrefix: { 'assets/': '/build/assets/' },
        // Precache the SPA shell HTML so navigateFallback can serve '/' offline.
        // The revision is the build timestamp — every fresh build invalidates
        // the cached shell, ensuring users don't get a shell pointing at
        // hashed asset URLs that no longer exist.
        additionalManifestEntries: [
          { url: '/', revision: `kin-${Date.now()}` },
        ],
        runtimeCaching: [
          {
            // Authenticated API: never serve from cache. Caching `/api/v1/user`
            // and other session-bound responses meant a returning visitor could
            // see another user's session payload after sign-out (#278 follow-up:
            // auto-login as a previously-cached user, plus CSRF mismatches when
            // the cached index.html's CSRF meta no longer matched the live
            // session). Background-sync is overkill for our current use; offline
            // reads can come back later when we actually need them.
            urlPattern: ({ url }) => url.pathname.startsWith('/api/'),
            handler: 'NetworkOnly',
            options: {
              cacheName: 'kinhold-api',
            },
          },
          {
            urlPattern: ({ url }) => url.pathname.startsWith('/storage/'),
            handler: 'StaleWhileRevalidate',
            options: {
              cacheName: 'kinhold-uploads',
              expiration: { maxEntries: 80, maxAgeSeconds: 60 * 60 * 24 * 7 },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
          {
            urlPattern: ({ request }) =>
              ['style', 'script', 'worker', 'font', 'image'].includes(request.destination),
            handler: 'CacheFirst',
            options: {
              cacheName: 'kinhold-static',
              expiration: { maxEntries: 128, maxAgeSeconds: 60 * 60 * 24 * 30 },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
        ],
      },
      devOptions: {
        // SW disabled in dev to avoid stale-asset hell during HMR
        enabled: false,
      },
    }),
    promoteServiceWorkerToRoot(),
  ],
  resolve: {
    alias: {
      '@': resolve(currentDir, './resources/js'),
    },
  },
  build: {
    chunkSizeWarningLimit: 1400,
  },
})
