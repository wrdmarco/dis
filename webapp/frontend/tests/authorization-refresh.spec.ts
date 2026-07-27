import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import { authorizationFingerprint } from '../src/features/auth/authorizationFingerprint';
import { hasWebPermission } from '../src/features/auth/AuthContext';
import type { Permission, Role, User } from '../src/types/api';

const permission = (id: string, name: string): Permission => ({
  id,
  name,
  category: 'test',
  display_name: name,
});

const role = (
  id: string,
  permissions: Permission[],
  operator = false,
  admin = true,
): Role => ({
  id,
  name: `role-${id}`,
  display_name: `Role ${id}`,
  can_use_operator_app: operator,
  can_use_admin_app: admin,
  permissions,
});

const user = (roles: Role[]): User => ({
  id: 'user-1',
  name: 'Authorization User',
  email: 'authorization@example.test',
  account_status: 'active',
  push_enabled: true,
  max_operator_devices: 2,
  two_factor_enabled: true,
  roles,
});

test('authorization fingerprint is stable for ordering and metadata-only changes', () => {
  const first = user([
    role('b', [permission('2', 'users.view'), permission('1', 'assets.view')]),
    role('a', [permission('3', 'status.view')], true),
  ]);
  const reordered = user([
    {
      ...role('a', [permission('3', 'status.view')], true),
      name: 'renamed-role',
      display_name: 'Renamed role',
      description: 'Metadata does not affect authorization.',
    },
    role('b', [permission('1', 'assets.view'), permission('2', 'users.view')]),
  ]);

  expect(authorizationFingerprint(reordered)).toBe(authorizationFingerprint(first));
});

test('authorization fingerprint changes for permissions and app access', () => {
  const original = user([role('a', [permission('1', 'assets.view')])]);
  const changedPermission = user([role('a', [permission('2', 'users.view')])]);
  const changedAppAccess = user([role('a', [permission('1', 'assets.view')], true)]);

  expect(authorizationFingerprint(changedPermission)).not.toBe(authorizationFingerprint(original));
  expect(authorizationFingerprint(changedAppAccess)).not.toBe(authorizationFingerprint(original));
});

test('web permissions only come from roles that may use the web console', () => {
  const mixedUser = user([
    role('operator', [permission('1', 'calendar.view')], true, false),
    role('web', [permission('2', 'product-requests.view')], false, true),
  ]);

  expect(hasWebPermission(mixedUser, 'calendar.view')).toBe(false);
  expect(hasWebPermission(mixedUser, 'product-requests.view')).toBe(true);
  expect(hasWebPermission(null, 'product-requests.view')).toBe(false);
});

test('active auth refresh consumes touch payload and listens for authorization changes', () => {
  const authContext = readFileSync('src/features/auth/AuthContext.tsx', 'utf8');
  const realtime = readFileSync('src/lib/realtime.ts', 'utf8');
  const bridge = readFileSync('src/features/realtime/RealtimeBridge.tsx', 'utf8');

  expect(authContext).toContain("api.post<User>('/auth/session/touch')");
  expect(authContext).toContain('applyAuthenticatedUser(response.data)');
  expect(authContext).toContain('onAuthorizationChanged:');
  expect(realtime).toContain("listen('.authorization.changed', options.onAuthorizationChanged)");
  expect(bridge).toContain('authorizationFingerprint(user)');
});
