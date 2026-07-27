import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  hasWebRouteAccess,
  webRouteAccess,
  type WebRouteAccess,
} from '../src/features/auth/webRouteAccess';

const managementRoutes = [
  { key: 'assets', path: '/assets', label: 'Assets', permissions: ['assets.view'], anyPermission: false },
  { key: 'certifications', path: '/certifications', label: 'Certificaten', permissions: ['certifications.view'], anyPermission: false },
  { key: 'expiry', path: '/expiry', label: 'Verloop', permissions: ['expiry.view'], anyPermission: false },
  { key: 'forms', path: '/forms', label: 'Formulieren', permissions: ['forms.manage'], anyPermission: false },
  {
    key: 'priorityDecisions',
    path: '/prioriteitsbesluiten',
    label: 'Prioriteitsbesluiten',
    permissions: ['forms.manage'],
    anyPermission: false,
  },
  {
    key: 'admin',
    path: '/admin',
    label: 'Admin',
    permissions: [
      'settings.manage',
      'settings.push.tokens.manage',
      'system.health.view',
      'system.developer-access.manage',
    ],
    anyPermission: true,
  },
  { key: 'knmi', path: '/knmi', label: 'KNMI', permissions: ['knmi.manage'], anyPermission: false },
  { key: 'branding', path: '/branding', label: 'Branding', permissions: ['branding.manage'], anyPermission: false },
  { key: 'audit', path: '/audit', label: 'Audit', permissions: ['audit.view', 'status.audit.view'], anyPermission: true },
  { key: 'backups', path: '/backups', label: 'Backups', permissions: ['backups.manage'], anyPermission: false },
  { key: 'wallboards', path: '/wallboards', label: 'Wallboards', permissions: ['wallboards.manage'], anyPermission: false },
  {
    key: 'routing',
    path: '/routing',
    label: 'Routering',
    permissions: ['system.routing.view', 'system.routing.manage'],
    anyPermission: true,
  },
  {
    key: 'queues',
    path: '/queues',
    label: 'Wachtrijen',
    permissions: ['system.queues.view', 'system.queues.manage'],
    anyPermission: true,
  },
  { key: 'system', path: '/system', label: 'Systeem', permissions: ['system.health.view'], anyPermission: false },
] as const;

test('keeps all fourteen management navigation items and direct routes on one RBAC matrix', () => {
  const navigation = readFileSync(new URL('../src/app/CommandLayout.tsx', import.meta.url), 'utf8');
  const routeShell = readFileSync(new URL('../src/next/RouteShell.tsx', import.meta.url), 'utf8');

  expect(managementRoutes).toHaveLength(14);

  for (const item of managementRoutes) {
    const access = webRouteAccess[item.key];
    const route = readFileSync(
      new URL(`../app${item.path}/page.tsx`, import.meta.url),
      'utf8',
    );

    expect(access.permissions).toEqual(item.permissions);
    expect(access.anyPermission).toBe(item.anyPermission);
    expect(route).toContain(`<ProtectedShell {...webRouteAccess.${item.key}}>`);
    expect(navigation).toContain(`to: '${item.path}', label: '${item.label}'`);
    expect(navigation).toContain(`...webRouteAccess.${item.key}`);
    expect(routeShell).toContain(`{ to: '${item.path}', ...webRouteAccess.${item.key} }`);
  }
});

test('treats deployment management as web view access across navigation and deployment routes', () => {
  const navigation = readFileSync(new URL('../src/app/CommandLayout.tsx', import.meta.url), 'utf8');
  const routeShell = readFileSync(new URL('../src/next/RouteShell.tsx', import.meta.url), 'utf8');
  const deploymentRoutes = [
    '../app/inzetten/page.tsx',
    '../app/inzetten/archive/page.tsx',
    '../app/inzetten/[deploymentId]/page.tsx',
  ];

  expect(webRouteAccess.deployments.permissions).toEqual(['deployments.view', 'deployments.manage']);
  expect(webRouteAccess.deployments.anyPermission).toBe(true);
  expect(hasWebRouteAccess(webRouteAccess.deployments, (permission) => permission === 'deployments.view')).toBe(true);
  expect(hasWebRouteAccess(webRouteAccess.deployments, (permission) => permission === 'deployments.manage')).toBe(true);
  expect(hasWebRouteAccess(webRouteAccess.deployments, () => false)).toBe(false);

  for (const routePath of deploymentRoutes) {
    const route = readFileSync(new URL(routePath, import.meta.url), 'utf8');
    expect(route).toContain('<ProtectedShell {...webRouteAccess.deployments}>');
  }
  const editRoute = readFileSync(new URL('../app/inzetten/[deploymentId]/edit/page.tsx', import.meta.url), 'utf8');
  expect(editRoute).toContain("<ProtectedShell permissions={['deployments.manage']}>");
  expect(editRoute).not.toContain("'deployments.view'");

  expect(navigation).toContain("to: '/aanvragen', label: 'Aanvragen'");
  expect(navigation).toContain("to: '/inzetten', label: 'Inzetten'");
  expect(navigation).toContain("to: '/inzetten', label: 'Inzetten', icon: RadioTower, end: true, ...webRouteAccess.deployments");
  expect(navigation).toContain("to: '/inzetten/archive', label: 'Archief', icon: Archive, ...webRouteAccess.deployments");
  expect(routeShell).toContain("{ to: '/inzetten', ...webRouteAccess.deployments }");
});

test('keeps dispatch data and actions behind their dedicated permissions on the deployment detail', () => {
  const detail = readFileSync(new URL('../src/features/deployments/DeploymentDetailPage.tsx', import.meta.url), 'utf8');

  expect(detail).toContain("const canViewDispatches = hasPermission('deployments.dispatch.view')");
  expect(detail).toContain("const canManageDispatches = hasPermission('deployments.dispatch.manage')");
  expect(detail).toContain('Boolean(deploymentId) && canViewDispatches');
  expect(detail).toContain('showDraftPanel && canViewDispatches && canManageDispatches');
  expect(detail).toContain('showDispatchPanel && canViewDispatches && canManageDispatches');
  expect(detail).toContain('{canViewDispatches ? (');
  expect(detail).not.toContain('showDraftPanel && canManageDeployments');
  expect(detail).not.toContain('showDispatchPanel && canManageDeployments');
});

test('evaluates alternative and cumulative permission rules fail closed', () => {
  const permitted = new Set(['system.routing.view']);
  const hasPermission = (permission: string) => permitted.has(permission);

  expect(hasWebRouteAccess(webRouteAccess.routing, hasPermission)).toBe(true);
  expect(hasWebRouteAccess(webRouteAccess.queues, hasPermission)).toBe(false);
  expect(hasWebRouteAccess(webRouteAccess.assets, hasPermission)).toBe(false);
  expect(hasWebRouteAccess(
    { permissions: ['first', 'second'], anyPermission: false } satisfies WebRouteAccess,
    hasPermission,
  )).toBe(false);

  permitted.add('first');
  expect(hasWebRouteAccess(
    { permissions: ['first', 'second'], anyPermission: false } satisfies WebRouteAccess,
    hasPermission,
  )).toBe(false);

  permitted.add('second');
  expect(hasWebRouteAccess(
    { permissions: ['first', 'second'], anyPermission: false } satisfies WebRouteAccess,
    hasPermission,
  )).toBe(true);
});

test('keeps branding-owned settings out of the general admin settings form', () => {
  const adminPage = readFileSync(new URL('../src/features/admin/AdminPage.tsx', import.meta.url), 'utf8');
  const brandingPage = readFileSync(new URL('../src/features/branding/BrandingPage.tsx', import.meta.url), 'utf8');

  for (const setting of ['mobile.tenant_name', 'security.mfa_issuer_name', 'mail.from_name']) {
    expect(adminPage).not.toContain(`'${setting}'`);
    expect(brandingPage).toContain(`'${setting}'`);
  }
});
