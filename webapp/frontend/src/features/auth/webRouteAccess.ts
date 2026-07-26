export interface WebRouteAccess {
  permissions: readonly string[];
  anyPermission?: boolean;
}

export const webRouteAccess = {
  intakes: {
    permissions: ['incidents.manage'],
    anyPermission: false,
  },
  assets: {
    permissions: ['assets.view'],
    anyPermission: false,
  },
  certifications: {
    permissions: ['certifications.view'],
    anyPermission: false,
  },
  expiry: {
    permissions: ['expiry.view'],
    anyPermission: false,
  },
  forms: {
    permissions: ['forms.manage'],
    anyPermission: false,
  },
  admin: {
    permissions: [
      'settings.manage',
      'settings.push.tokens.manage',
      'system.health.view',
      'system.developer-access.manage',
    ],
    anyPermission: true,
  },
  knmi: {
    permissions: ['knmi.manage'],
    anyPermission: false,
  },
  branding: {
    permissions: ['branding.manage'],
    anyPermission: false,
  },
  audit: {
    permissions: ['audit.view', 'status.audit.view'],
    anyPermission: true,
  },
  backups: {
    permissions: ['backups.manage'],
    anyPermission: false,
  },
  wallboards: {
    permissions: ['wallboards.manage'],
    anyPermission: false,
  },
  routing: {
    permissions: ['system.routing.view', 'system.routing.manage'],
    anyPermission: true,
  },
  queues: {
    permissions: ['system.queues.view', 'system.queues.manage'],
    anyPermission: true,
  },
  system: {
    permissions: ['system.health.view'],
    anyPermission: false,
  },
} as const satisfies Record<string, WebRouteAccess>;

export function hasWebRouteAccess(
  access: WebRouteAccess,
  hasPermission: (permission: string) => boolean,
): boolean {
  if (access.permissions.length === 0) {
    return true;
  }

  return access.anyPermission === true
    ? access.permissions.some(hasPermission)
    : access.permissions.every(hasPermission);
}
