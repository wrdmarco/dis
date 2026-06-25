import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { Bell, Boxes, ClipboardCheck, Gauge, LogOut, RadioTower, Shield, Smartphone, Users, Workflow } from 'lucide-react';
import { useAuth } from '../features/auth/AuthContext';

const navItems = [
  { to: '/', label: 'Dashboard', icon: Gauge, end: true },
  { to: '/incidents', label: 'Incidenten', icon: RadioTower },
  { to: '/status', label: 'Status', icon: Workflow },
  { to: '/users', label: 'Gebruikers', icon: Users },
  { to: '/assets', label: 'Assets', icon: Boxes },
  { to: '/certifications', label: 'Certificaten', icon: ClipboardCheck },
  { to: '/updates', label: 'Updates', icon: Smartphone },
  { to: '/admin', label: 'Admin', icon: Shield },
  { to: '/system', label: 'Systeem', icon: Bell },
];

export function CommandLayout() {
  const { user, api, clearSession } = useAuth();
  const navigate = useNavigate();

  const logout = async () => {
    await api.post('/auth/logout').catch(() => undefined);
    clearSession();
    navigate('/login', { replace: true });
  };

  return (
    <div className="command-layout">
      <aside className="sidebar">
        <div className="brand">
          <span className="brand__mark">DIS</span>
          <span className="brand__text">Command Center</span>
        </div>
        <nav className="nav" aria-label="Hoofdnavigatie">
          {navItems.map((item) => {
            const Icon = item.icon;
            return (
              <NavLink key={item.to} to={item.to} end={item.end} className={({ isActive }) => `nav__item ${isActive ? 'nav__item--active' : ''}`}>
                <Icon aria-hidden size={18} />
                <span>{item.label}</span>
              </NavLink>
            );
          })}
        </nav>
      </aside>
      <div className="workspace">
        <header className="topbar">
          <div>
            <span className="topbar__eyebrow">Nationaal Droneteam</span>
            <h1>D.I.S Operationeel Beeld</h1>
          </div>
          <div className="operator">
            <div>
              <strong>{user?.name ?? 'Operator'}</strong>
              <span>{user?.email}</span>
            </div>
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

