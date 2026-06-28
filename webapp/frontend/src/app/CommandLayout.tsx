import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { Archive, BarChart3, Bell, BellRing, Boxes, CalendarClock, ClipboardCheck, FileClock, Gauge, KeyRound, LogOut, Network, Palette, RadioTower, Send, Shield, Smartphone, UserRound, Users, Workflow } from 'lucide-react';
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

const navGroups: NavGroup[] = [
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
      { to: '/status', label: 'Status', icon: Workflow, permissions: ['status.view'] },
      { to: '/status/audit', label: 'Status audit', icon: FileClock, permissions: ['status.audit.view'] },
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
      { to: '/system', label: 'Systeem', icon: Bell, permissions: ['system.health'] },
    ],
  },
];

const profileOnlyNavGroups: NavGroup[] = [
  {
    label: 'Account',
    items: [
      { to: '/profile', label: 'Profiel', icon: UserRound },
    ],
  },
];

interface BrandingState {
  name: string;
  short_name: string;
  tenant_name: string;
}

export function CommandLayout() {
  const { user, api, clearSession, canUseWebConsole, hasPermission } = useAuth();
  const navigate = useNavigate();
  const [branding, setBranding] = useState<BrandingState>({
    name: 'D.I.S Operationeel Beeld',
    short_name: 'DIS',
    tenant_name: 'Nationaal Droneteam',
  });

  useEffect(() => {
    api.get<BrandingState>('/branding')
      .then((response) => setBranding(response.data))
      .catch(() => undefined);
  }, [api]);

  const logout = async () => {
    await api.post('/auth/logout').catch(() => undefined);
    clearSession();
    navigate('/login', { replace: true });
  };
  const visibleNavGroups = canUseWebConsole()
    ? navGroups
      .map((group) => ({
        ...group,
        items: group.items.filter((item) => canShowNavItem(item, hasPermission)),
      }))
      .filter((group) => group.items.length > 0)
    : profileOnlyNavGroups;

  return (
    <div className="command-layout">
      <aside className="sidebar">
        <div className="brand">
          <span className="brand__mark">{branding.short_name}</span>
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
                    <NavLink key={item.to} to={item.to} end={item.end} className={({ isActive }) => `nav__item ${isActive ? 'nav__item--active' : ''}`}>
                      <Icon aria-hidden size={18} />
                      <span>{item.label}</span>
                    </NavLink>
                  );
                })}
              </div>
            </section>
          ))}
        </nav>
      </aside>
      <div className="workspace">
        <header className="topbar">
          <div>
            <span className="topbar__eyebrow">{branding.tenant_name}</span>
            <h1>{branding.name}</h1>
          </div>
          <div className="operator">
            <div>
              <strong>{user?.name ?? 'Operator'}</strong>
              <span>{user?.email}</span>
            </div>
            <button className="icon-button" type="button" onClick={() => navigate('/profile')} aria-label="Profiel">
              <UserRound size={18} />
            </button>
            <button className="icon-button" type="button" onClick={logout} aria-label="Uitloggen">
              <LogOut size={18} />
            </button>
          </div>
        </header>
        <main className="content">
          <Outlet />
        </main>
      </div>
    </div>
  );
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
