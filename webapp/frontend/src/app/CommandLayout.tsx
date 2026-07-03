import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { Archive, BarChart3, Bell, BellRing, Boxes, CalendarClock, ClipboardCheck, DatabaseBackup, Gauge, KeyRound, LogOut, Network, Palette, RadioTower, ScrollText, Send, Shield, Smartphone, UserRound, Users, Workflow } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useAuth } from '../features/auth/AuthContext';

interface NavItem {
  to: string;
  label: string;
  icon: LucideIcon;
  end?: boolean;
  permissions?: string[];
  anyPermission?: boolean;
}

interface NavGroup {
  label: string;
  items: NavItem[];
}

const PROFILE_PATH = '/profile';

const navGroups: NavGroup[] = [
  {
    label: 'Account',
    items: [
      { to: PROFILE_PATH, label: 'Profiel', icon: UserRound },
    ],
  },
  {
    label: 'Overzicht',
    items: [
      { to: '/dashboard', label: 'Dashboard', icon: Gauge, permissions: ['incidents.view', 'dispatch.view', 'status.view', 'assets.view'] },
    ],
  },
  {
    label: 'Operatie',
    items: [
      { to: '/incidents', label: 'Actieve meldingen', icon: RadioTower, end: true, permissions: ['incidents.view'] },
      { to: '/incidents/archive', label: 'Archief', icon: Archive, permissions: ['incidents.view'] },
      { to: '/operationele-status', label: 'Status', icon: Workflow, permissions: ['status.view'] },
      { to: '/proefalarmering', label: 'Proefalarmering', icon: BellRing, permissions: ['dispatch.manage'] },
      { to: '/push', label: 'Pushmeldingen', icon: Send, permissions: ['push.manage'] },
      { to: '/reports', label: 'Statistieken', icon: BarChart3, permissions: ['incidents.view', 'dispatch.view'] },
    ],
  },
  {
    label: 'Mensen & middelen',
    items: [
      { to: '/users', label: 'Gebruikers', icon: Users, permissions: ['users.view'] },
      { to: '/rollen', label: 'Rollen', icon: KeyRound, permissions: ['roles.manage'] },
      { to: '/teams', label: 'Teams', icon: Network, permissions: ['teams.manage'] },
    ],
  },
  {
    label: 'Gebruikersmiddelen',
    items: [
      { to: '/assets', label: 'Assets', icon: Boxes, permissions: ['assets.view'] },
      { to: '/certifications', label: 'Certificaten', icon: ClipboardCheck, permissions: ['certifications.view'] },
      { to: '/verloop', label: 'Verloop', icon: CalendarClock, permissions: ['assets.view', 'certifications.view'], anyPermission: true },
    ],
  },
  {
    label: 'Beheer',
    items: [
      { to: '/updates', label: 'App updates', icon: Smartphone, permissions: ['updates.manage'] },
      { to: '/admin', label: 'Admin', icon: Shield, permissions: ['settings.manage'] },
      { to: '/branding', label: 'Branding', icon: Palette, permissions: ['settings.manage'] },
      { to: '/audit', label: 'Audit', icon: ScrollText, permissions: ['audit.view', 'status.audit.view'], anyPermission: true },
      { to: '/backups', label: 'Backups', icon: DatabaseBackup, permissions: ['backups.manage'] },
      { to: '/systeem', label: 'Systeem', icon: Bell, permissions: ['system.health'] },
    ],
  },
];

const profileOnlyNavGroups: NavGroup[] = [
  {
    label: 'Account',
    items: [
      { to: PROFILE_PATH, label: 'Profiel', icon: UserRound },
    ],
  },
];

const routePreloaders: Record<string, () => Promise<unknown>> = {
  '/dashboard': () => import('../features/dashboard/DashboardPage'),
  '/incidents': () => import('../features/incidents/IncidentsPage'),
  '/incidents/archive': () => import('../features/incidents/IncidentsPage'),
  '/operationele-status': () => import('../features/status/StatusPage'),
  '/proefalarmering': () => import('../features/test-alerts/TestAlertPage'),
  '/push': () => import('../features/push/PushPage'),
  '/reports': () => import('../features/reports/ReportsPage'),
  '/users': () => import('../features/users/UsersPage'),
  '/rollen': () => import('../features/roles/RolesPage'),
  '/teams': () => import('../features/teams/TeamsPage'),
  '/assets': () => import('../features/assets/AssetsPage'),
  '/certifications': () => import('../features/certifications/CertificationsPage'),
  '/verloop': () => import('../features/expiry/ExpiryPage'),
  '/updates': () => import('../features/updates/UpdatesPage'),
  '/admin': () => import('../features/admin/AdminPage'),
  '/branding': () => import('../features/branding/BrandingPage'),
  '/audit': () => import('../features/audit/AuditLogPage'),
  '/backups': () => import('../features/backups/BackupPage'),
  '/systeem': () => import('../features/system/SystemPage'),
  [PROFILE_PATH]: () => import('../features/profile/ProfilePage'),
};

interface BrandingState {
  name: string;
  short_name: string;
  tenant_name: string;
  logo_data_url: string;
}

export function CommandLayout({ children }: { children: React.ReactNode }) {
  const { user, api, clearSession, canUseWebConsole, hasPermission } = useAuth();
  const router = useRouter();
  const pathname = usePathname();
  const [branding, setBranding] = useState<BrandingState>({
    name: 'D.I.S Operationeel Beeld',
    short_name: 'DIS',
    tenant_name: 'Nationaal Droneteam',
    logo_data_url: '',
  });

  useEffect(() => {
    api.get<BrandingState>('/branding')
      .then((response) => setBranding(response.data))
      .catch(() => undefined);
  }, [api]);

  const logout = async () => {
    await api.post('/auth/logout').catch(() => undefined);
    clearSession();
    router.replace('/login');
  };
  const visibleNavGroups = canUseWebConsole()
    ? navGroups
      .map((group) => ({
        ...group,
        items: group.items.filter((item) => canShowNavItem(item, hasPermission)),
      }))
      .filter((group) => group.items.length > 0)
    : profileOnlyNavGroups;
  const currentNavItem = currentNavForPath(visibleNavGroups, pathname);

  return (
    <div className="command-layout">
      <a className="skip-link" href="#main-content">Naar hoofdinhoud</a>
      <aside className="sidebar">
        <div className="brand">
          <span className="brand__mark">
            {branding.logo_data_url ? <img src={branding.logo_data_url} alt="" /> : branding.short_name}
          </span>
          <span className="brand__text">Command Center</span>
        </div>
        <nav className="nav" aria-label="Hoofdnavigatie">
          {visibleNavGroups.map((group) => (
            <section className="nav__group" key={group.label}>
              <h2 className="nav__label">{group.label}</h2>
              <div className="nav__items">
                {group.items.map((item) => {
                  const Icon = item.icon;
                  return (
                    <Link
                      key={item.to}
                      href={item.to}
                      className={`nav__item ${isActivePath(pathname, item) ? 'nav__item--active' : ''}`}
                      onFocus={() => void preloadRoute(item.to)}
                      onMouseEnter={() => void preloadRoute(item.to)}
                    >
                      <Icon aria-hidden size={18} />
                      <span>{item.label}</span>
                    </Link>
                  );
                })}
              </div>
            </section>
          ))}
        </nav>
      </aside>
      <div className="workspace">
        <header className="topbar">
          <div className="topbar__title">
            <span className="topbar__eyebrow">{currentNavItem?.groupLabel ?? branding.tenant_name}</span>
            <h1>{currentNavItem?.item.label ?? branding.name}</h1>
            <span className="topbar__app">{branding.tenant_name} - {branding.name}</span>
          </div>
          <div className="operator">
            <div>
              <strong>{user?.name ?? 'Operator'}</strong>
              <span>{user?.email}</span>
            </div>
            <Link href={PROFILE_PATH} className={`icon-button ${pathname === PROFILE_PATH ? 'icon-button--active' : ''}`} aria-label="Profiel">
              <UserRound size={18} />
            </Link>
            <button className="icon-button" type="button" onClick={logout} aria-label="Uitloggen">
              <LogOut size={18} />
            </button>
          </div>
        </header>
        <main className="content" id="main-content" tabIndex={-1}>
          {children}
        </main>
      </div>
    </div>
  );
}

function isActivePath(pathname: string, item: NavItem): boolean {
  return pathname === item.to || (!item.end && pathname.startsWith(`${item.to}/`));
}

function preloadRoute(path: string): Promise<unknown> | undefined {
  return routePreloaders[path]?.();
}

function currentNavForPath(groups: NavGroup[], pathname: string): { groupLabel: string; item: NavItem } | null {
  let match: { groupLabel: string; item: NavItem } | null = null;
  let matchLength = -1;

  for (const group of groups) {
    for (const item of group.items) {
      const isExact = pathname === item.to;
      const isNested = !item.end && pathname.startsWith(`${item.to}/`);
      if ((isExact || isNested) && item.to.length > matchLength) {
        match = { groupLabel: group.label, item };
        matchLength = item.to.length;
      }
    }
  }

  return match;
}

function canShowNavItem(item: NavItem, hasPermission: (permission: string) => boolean): boolean {
  if (!item.permissions || item.permissions.length === 0) {
    return true;
  }

  if (item.anyPermission) {
    return item.permissions.some(hasPermission);
  }

  return item.permissions.every(hasPermission);
}
