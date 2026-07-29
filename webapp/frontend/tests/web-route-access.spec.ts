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
      'system.logs.view',
    ],
    anyPermission: true,
  },
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

test('keeps all thirteen management navigation items and direct routes on one RBAC matrix', () => {
  const navigation = readFileSync(new URL('../src/app/CommandLayout.tsx', import.meta.url), 'utf8');
  const routeShell = readFileSync(new URL('../src/next/RouteShell.tsx', import.meta.url), 'utf8');

  expect(managementRoutes).toHaveLength(13);

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

test('keeps user-facing weather, forecast, calendar and request routes behind explicit permissions', () => {
  const navigation = readFileSync(new URL('../src/app/CommandLayout.tsx', import.meta.url), 'utf8');
  const routeShell = readFileSync(new URL('../src/next/RouteShell.tsx', import.meta.url), 'utf8');
  const routes = [
    { key: 'productRequests', path: '/verzoeken', label: 'Verzoeken', permission: 'product-requests.view' },
    { key: 'weather', path: '/weather', label: 'Weer', permission: 'operational-weather.view' },
    { key: 'uavForecast', path: '/uav-forecast', label: 'UAV Forecast', permission: 'uav-forecast.view' },
    { key: 'calendar', path: '/calendar', label: 'Agenda', permission: 'calendar.view' },
  ] as const;

  for (const item of routes) {
    const access = webRouteAccess[item.key];
    expect(access.permissions).toEqual([item.permission]);
    expect(access.anyPermission).toBe(false);
    expect(hasWebRouteAccess(access, (permission) => permission === item.permission)).toBe(true);
    expect(hasWebRouteAccess(access, () => false)).toBe(false);
    expect(navigation).toContain(`to: '${item.path}', label: '${item.label}'`);
    expect(navigation).toContain(`...webRouteAccess.${item.key}`);
    expect(routeShell).toContain(`{ to: '${item.path}', ...webRouteAccess.${item.key} }`);

    const route = readFileSync(new URL(`../app${item.path}/page.tsx`, import.meta.url), 'utf8');
    expect(route).toContain(`<ProtectedShell {...webRouteAccess.${item.key}}>`);
  }
});

test('calendar mutations use their dedicated manage permission throughout the frontend', () => {
  const calendar = readFileSync(new URL('../src/features/calendar/CalendarPage.tsx', import.meta.url), 'utf8');
  const help = readFileSync(new URL('../src/features/help/HelpPage.tsx', import.meta.url), 'utf8');
  const accountManual = readFileSync(new URL('../src/features/help/manuals/accountManual.ts', import.meta.url), 'utf8');
  const roleForm = readFileSync(new URL('../src/features/roles/RoleForm.tsx', import.meta.url), 'utf8');

  expect(calendar).toContain("hasPermission('calendar.manage')");
  expect(calendar).not.toContain("hasPermission('settings.manage')");
  expect(calendar).toContain("useApiResource<CalendarAudienceGroup[]>(");
  expect(calendar).toContain("'/calendar-events/group-options'");
  expect(calendar).not.toContain("'/calendar-events/team-options'");
  expect(calendar).not.toContain("'/teams'");
  expect(calendar).toMatch(/api\.patch<CalendarEvent>\(\s*`\/calendar-events\/\$\{editingEvent\.id\}`/);
  expect(calendar).toContain('role="dialog"');
  expect(calendar).toContain('Wijzigingen opslaan');
  expect(help).toContain("permissions: ['calendar.view']");
  expect(help).toContain("permissions: ['calendar.view', 'calendar.manage']");
  expect(help).toContain("permissions: ['calendar.view', 'calendar.register']");
  expect(help).toContain("permissions: ['calendar.view', 'calendar.groups.manage']");
  expect(help).toContain('pas afspraken aan');
  expect(help).not.toContain('geen aparte wijzigknop');
  expect(accountManual).toContain("permissions: ['calendar.view', 'calendar.manage']");
  expect(accountManual).toContain("id: 'calendar-edit-event'");
  expect(accountManual).toContain('Kies Wijzigingen opslaan');
  expect(accountManual).not.toContain('geen aparte wijzigknop');
  expect(accountManual).not.toContain("permissions: ['settings.manage']");
  expect(roleForm).toContain("case 'calendar_management':");
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
