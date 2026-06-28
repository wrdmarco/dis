import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { Archive, BarChart3, Bell, BellRing, Boxes, CalendarClock, ClipboardCheck, Gauge, KeyRound, LogOut, Network, Palette, RadioTower, Send, Shield, Smartphone, UserRound, Users, Workflow } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useAuth } from '../features/auth/AuthContext';

const navGroups = [
  {
    label: 'Overzicht',
    items: [
      { to: '/', label: 'Dashboard', icon: Gauge, end: true },
    ],
  },
  {
    label: 'Operatie',
    items: [
      { to: '/incidents', label: 'Actieve meldingen', icon: RadioTower, end: true },
      { to: '/incidents/archive', label: 'Archief', icon: Archive },
      { to: '/status', label: 'Status', icon: Workflow },
      { to: '/proefalarmering', label: 'Proefalarmering', icon: BellRing },
    ],
  },
  {
    label: 'Mensen & middelen',
    items: [
      { to: '/users', label: 'Gebruikers', icon: Users },
      { to: '/rollen', label: 'Rollen', icon: KeyRound },
      { to: '/teams', label: 'Teams', icon: Network },
    ],
  },
  {
    label: 'Assets & certificaten',
    items: [
      { to: '/assets', label: 'Assets', icon: Boxes },
      { to: '/certifications', label: 'Certificaten', icon: ClipboardCheck },
      { to: '/verloop', label: 'Verloop', icon: CalendarClock },
    ],
  },
  {
    label: 'Communicatie',
    items: [
      { to: '/push', label: 'Pushmeldingen', icon: Send },
      { to: '/reports', label: 'Statistieken', icon: BarChart3 },
    ],
  },
  {
    label: 'Beheer',
    items: [
      { to: '/updates', label: 'App updates', icon: Smartphone },
      { to: '/branding', label: 'Branding', icon: Palette },
      { to: '/admin', label: 'Admin', icon: Shield },
      { to: '/system', label: 'Systeem', icon: Bell },
    ],
  },
];

interface BrandingState {
  name: string;
  short_name: string;
  tenant_name: string;
}

export function CommandLayout() {
  const { user, api, clearSession } = useAuth();
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

  return (
    <div className="command-layout">
      <aside className="sidebar">
        <div className="brand">
          <span className="brand__mark">{branding.short_name}</span>
          <span className="brand__text">Command Center</span>
        </div>
        <nav className="nav" aria-label="Hoofdnavigatie">
          {navGroups.map((group) => (
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
