import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';

const adminPage = readFileSync(
  new URL('../src/features/admin/AdminPage.tsx', import.meta.url),
  'utf8',
);
const qrComponent = readFileSync(
  new URL('../src/components/TotpQrCode.tsx', import.meta.url),
  'utf8',
);
const apiTypes = readFileSync(new URL('../src/types/api.ts', import.meta.url), 'utf8');
const managementManual = readFileSync(
  new URL('../src/features/help/manuals/managementManual.ts', import.meta.url),
  'utf8',
);

test('shows a downloadable rotating pairing QR for each native store account', () => {
  const storeSection = adminPage.slice(
    adminPage.indexOf("{activeTab === 'store'"),
    adminPage.indexOf("{activeTab === 'pilotReport'"),
  );

  expect(storeSection).toContain('account.review_setup?.available');
  expect(storeSection).toContain('account.review_setup.qr_payload');
  expect(storeSection).toContain('account.review_setup.code');
  expect(storeSection).toContain("'App Store Connect'");
  expect(storeSection).toContain("'Google Play Console'");
  expect(storeSection).toContain('dis-app-store-review-ios.png');
  expect(storeSection).toContain('dis-google-play-review-android.png');
  expect(storeSection).toContain('dezelfde beveiligde koppeling als een gewone gebruiker');
  expect(storeSection).toContain('verloopt niet zolang hij ongebruikt is');
  expect(storeSection).toContain('binnen vijf seconden automatisch een nieuwe QR');
  expect(storeSection).not.toContain('/admin/store-review/android-pairing');
});

test('keeps the review setup contract additive and provides an actual PNG download', () => {
  expect(apiTypes).toContain('review_setup?: StoreReviewSetup;');
  expect(apiTypes).toContain('code?: string | null;');
  expect(apiTypes).toContain('expires_at?: string | null;');
  expect(apiTypes).toContain('issued_at?: string | null;');
  expect(apiTypes).toContain('configuration_error?: string | null;');
  expect(qrComponent).toContain('downloadFileName?: string;');
  expect(qrComponent).toContain('download={downloadFileName}');
  expect(qrComponent).toContain("downloadLabel = 'QR-code downloaden'");
  expect(qrComponent).toContain('setDataUrl(null);');
});

test('documents single-use non-expiring review pairing and automatic rotation', () => {
  const manualSection = managementManual.slice(
    managementManual.indexOf("id: 'admin-store-review'"),
    managementManual.indexOf("id: 'admin-revoke-device'"),
  );

  expect(manualSection).toContain('Download de juiste review-QR');
  expect(manualSection).toContain('de QR scannen');
  expect(manualSection).toContain('geen wachtwoord of toegangstoken');
  expect(manualSection).toContain('verloopt niet zolang hij ongebruikt is');
  expect(manualSection).toContain('automatisch een nieuwe QR');
  expect(manualSection).toContain('minimaal 24 tekens');
  expect(manualSection).not.toContain('zes uur');
});

test('polls Store status while the Store tab is visible so a consumed QR is replaced', () => {
  expect(adminPage).toContain("activeTab !== 'store'");
  expect(adminPage).toContain('reloadStoreReviewStatusSilently');
  expect(adminPage).toContain("document.visibilityState === 'visible'");
  expect(adminPage).toContain('}, 5000);');
});
