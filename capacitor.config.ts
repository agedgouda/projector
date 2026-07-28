import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.kidevelopment.projector',
  appName: 'Projector',
  // This app is server-driven, not a bundled SPA — the WebView loads the mobile
  // page tree (/app/...) directly from the live Laravel app, so `webDir` below is
  // effectively unused (it just has to exist for `cap sync`).
  webDir: 'www',
  server: {
    // Pointed at production for on-device testing ahead of the real deploy — swap back to
    // 'http://projector.test/app' + cleartext: true for local Herd testing in the
    // Simulator (a physical device can't resolve projector.test the way the Simulator can).
    url: 'https://projecthq.app/app',
    cleartext: false,
  },
};

export default config;
