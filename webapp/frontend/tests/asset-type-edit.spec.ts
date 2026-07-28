import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';

test('keeps asset type editing available in management and clears stale drone form state', () => {
  const form = readFileSync(
    new URL('../src/features/assets/AssetFormPage.tsx', import.meta.url),
    'utf8',
  );

  expect(form).not.toContain('disabled={isEditing}');
  expect(form).toContain("type: event.target.value, droneTypeId: '', hasSpotlight: false, hasSpeaker: false");
  expect(form).toContain("drone_type_id: form.type === 'drone' ? form.droneTypeId || null : null");
});

test('only sends identity changes for assets created by the profile owner', () => {
  const profile = readFileSync(
    new URL('../src/features/profile/ProfilePage.tsx', import.meta.url),
    'utf8',
  );

  expect(profile).toContain('canEditIdentity: asset.active_assignment?.assigned_by === user?.id');
  expect(profile).toContain('...(assetForm.canEditIdentity ? {');
  expect(profile).toContain('disabled={!assetForm.canEditIdentity}');
  expect(profile).toContain("type: assetForm.type");
  expect(profile).toContain("drone_type_id: assetForm.type === 'drone' ? assetForm.droneTypeId || null : null");
});
