import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.kidevelopment.projector',
  appName: 'Projector',
  // This app is server-driven, not a bundled SPA — the WebView loads the mobile
  // page tree (/app/...) directly from the live Laravel app, so `webDir` below is
  // effectively unused (it just has to exist for `cap sync`).
  webDir: 'www',
  server: {
    // This site is HTTP-only in Herd (APP_URL=http://projector.test, not secured) —
    // confirmed directly (https gets connection refused, http returns a real response).
    // The iOS Simulator shares the Mac's own DNS resolution, so projector.test resolves
    // there without extra setup. The Android emulator and physical devices do not share
    // that resolution — for those, swap this to a tunnel URL or the deployed
    // staging/production URL (which should be secured, making cleartext unnecessary there).
    url: 'http://projector.test/app',
    // Both iOS (App Transport Security) and Android (cleartext traffic policy) block
    // plain HTTP by default — required for this URL to load at all, not optional.
    cleartext: true,
  },
};

export default config;
