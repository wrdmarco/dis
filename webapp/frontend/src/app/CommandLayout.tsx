import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { Archive, BarChart3, Bell, BellRing, BookUser, Boxes, CalendarClock, CalendarDays, ClipboardCheck, DatabaseBackup, FileText, Gauge, KeyRound, LogOut, Map as MapIcon, Menu, Moon, Network, Palette, RadioTower, ScrollText, Send, Shield, Sun, UserRound, Users, Workflow, X } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { ApiClientError } from '../lib/apiClient';
import type { ApiClient } from '../lib/apiClient';
import { countryOptions, regionOptionsForCountry } from '../lib/profileLocation';
import { useAuth } from '../features/auth/AuthContext';
import type { User } from '../types/api';

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
      { to: '/dashboard', label: 'Dashboard', icon: Gauge, permissions: ['incidents.view', 'incidents.dispatch.view', 'status.view', 'assets.view'] },
    ],
  },
  {
    label: 'Operatie',
    items: [
      { to: '/incidents', label: 'Actieve meldingen', icon: RadioTower, end: true, permissions: ['incidents.view'] },
      { to: '/operational-map', label: 'Kaart', icon: MapIcon, permissions: ['operational-map.view'] },
      { to: '/incidents/archive', label: 'Archief', icon: Archive, permissions: ['incidents.view'] },
      { to: '/operational-status', label: 'Status', icon: Workflow, permissions: ['status.view'] },
      { to: '/calendar', label: 'Agenda', icon: CalendarDays },
      { to: '/test-alert', label: 'Proefalarmering', icon: BellRing, permissions: ['incidents.dispatch.manage'] },
      { to: '/push', label: 'Pushmeldingen', icon: Send, permissions: ['settings.push.manual.send'] },
      { to: '/reports', label: 'Rapporten', icon: BarChart3, permissions: ['incidents.view', 'incidents.dispatch.view'] },
    ],
  },
  {
    label: 'Mensen & middelen',
    items: [
      { to: '/users', label: 'Gebruikers', icon: Users, permissions: ['users.view'] },
      { to: '/address-book', label: 'Adresboek', icon: BookUser, permissions: ['address-book.view'] },
      { to: '/roles', label: 'Rollen', icon: KeyRound, permissions: ['roles.manage'] },
      { to: '/teams', label: 'Teams', icon: Network, permissions: ['teams.manage'] },
    ],
  },
  {
    label: 'Gebruikersmiddelen',
    items: [
      { to: '/assets', label: 'Assets', icon: Boxes, permissions: ['assets.view'] },
      { to: '/certifications', label: 'Certificaten', icon: ClipboardCheck, permissions: ['certifications.view'] },
      { to: '/expiry', label: 'Verloop', icon: CalendarClock, permissions: ['assets.view', 'certifications.view'], anyPermission: true },
    ],
  },
  {
    label: 'Beheer',
    items: [
      { to: '/forms', label: 'Formulieren', icon: FileText, permissions: ['settings.manage'] },
      { to: '/admin', label: 'Admin', icon: Shield, permissions: ['settings.manage', 'settings.push.tokens.manage', 'system.health.view', 'system.developer-access.manage'], anyPermission: true },
      { to: '/branding', label: 'Branding', icon: Palette, permissions: ['settings.manage'] },
      { to: '/audit', label: 'Audit', icon: ScrollText, permissions: ['audit.view', 'status.audit.view'], anyPermission: true },
      { to: '/backups', label: 'Backups', icon: DatabaseBackup, permissions: ['backups.manage'] },
      { to: '/system', label: 'Systeem', icon: Bell, permissions: ['system.health.view'] },
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
  '/operational-map': () => import('../features/incidents/IncidentMapPage'),
  '/incidents/archive': () => import('../features/incidents/IncidentsPage'),
  '/operational-status': () => import('../features/status/StatusPage'),
  '/test-alert': () => import('../features/test-alerts/TestAlertPage'),
  '/push': () => import('../features/push/PushPage'),
  '/reports': () => import('../features/reports/ReportsPage'),
  '/users': () => import('../features/users/UsersPage'),
  '/address-book': () => import('../features/address-book/AddressBookPage'),
  '/roles': () => import('../features/roles/RolesPage'),
  '/teams': () => import('../features/teams/TeamsPage'),
  '/assets': () => import('../features/assets/AssetsPage'),
  '/certifications': () => import('../features/certifications/CertificationsPage'),
  '/expiry': () => import('../features/expiry/ExpiryPage'),
  '/forms': () => import('../features/admin/AdminPage'),
  '/calendar': () => import('../features/calendar/CalendarPage'),
  '/admin': () => import('../features/admin/AdminPage'),
  '/branding': () => import('../features/branding/BrandingPage'),
  '/audit': () => import('../features/audit/AuditLogPage'),
  '/backups': () => import('../features/backups/BackupPage'),
  '/system': () => import('../features/system/SystemPage'),
  [PROFILE_PATH]: () => import('../features/profile/ProfilePage'),
};

interface BrandingState {
  name: string;
  short_name: string;
  tenant_name: string;
  logo_data_url: string;
}

export function CommandLayout({ children }: { children: React.ReactNode }) {
  const { user, api, clearSession, canUseWebConsole, hasPermission, theme, setThemePreference, refreshMe } = useAuth();
  const router = useRouter();
  const pathname = usePathname();
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const [branding, setBranding] = useState<BrandingState>({
    name: 'DIS',
    short_name: 'DIS',
    tenant_name: 'Nationaal Droneteam',
    logo_data_url: '',
  });

  useEffect(() => {
    api.get<BrandingState>('/branding')
      .then((response) => setBranding(response.data))
      .catch(() => undefined);
  }, [api]);

  useEffect(() => {
    document.documentElement.dataset.theme = theme;
    document.documentElement.style.colorScheme = theme;
  }, [theme]);

  useEffect(() => {
    setMobileNavOpen(false);
  }, [pathname]);

  useEffect(() => {
    if (!mobileNavOpen) {
      return undefined;
    }

    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setMobileNavOpen(false);
      }
    };

    window.addEventListener('keydown', closeOnEscape);

    return () => window.removeEventListener('keydown', closeOnEscape);
  }, [mobileNavOpen]);

  const logout = async () => {
    await api.post('/auth/logout').catch(() => undefined);
    clearSession();
    router.replace('/login');
  };
  const visibleNavGroups = useMemo(() => canUseWebConsole()
    ? navGroups
      .map((group) => ({
        ...group,
        items: group.items.filter((item) => canShowNavItem(item, hasPermission)),
      }))
      .filter((group) => group.items.length > 0)
    : profileOnlyNavGroups
      .map((group) => ({
        ...group,
        items: group.items.filter((item) => canShowNavItem(item, hasPermission)),
      }))
      .filter((group) => group.items.length > 0),
  [canUseWebConsole, hasPermission]);
  const currentNavItem = useMemo(() => currentNavForPath(visibleNavGroups, pathname), [pathname, visibleNavGroups]);
  const profileCompletionRequired = user?.profile_completion_required === true;

  useEffect(() => {
    document.title = documentTitleForBranding(branding, currentNavItem?.item.label);
  }, [branding, currentNavItem?.item.label]);

  return (
    <div className="command-layout">
      <a className="skip-link" href="#main-content">Naar hoofdinhoud</a>
      <aside className={`sidebar ${mobileNavOpen ? 'sidebar--open' : ''}`} id="mobile-navigation">
        <div className="brand">
          <span className="brand__mark">
            {branding.logo_data_url ? <img src={branding.logo_data_url} alt="" /> : branding.short_name}
          </span>
          <span className="brand__text">Command Center</span>
          <button className="icon-button sidebar__close" type="button" onClick={() => setMobileNavOpen(false)} aria-label="Menu sluiten">
            <X size={18} />
          </button>
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
      {mobileNavOpen ? (
        <button className="mobile-nav-backdrop mobile-nav-backdrop--open" type="button" onClick={() => setMobileNavOpen(false)} aria-label="Menu sluiten" />
      ) : null}
      <div className="workspace">
        <header className="topbar">
          <button
            className="icon-button topbar__menu"
            type="button"
            onClick={() => setMobileNavOpen(true)}
            aria-label="Menu openen"
            aria-controls="mobile-navigation"
            aria-expanded={mobileNavOpen}
          >
            <Menu size={18} />
          </button>
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
            <button
              className="icon-button"
              type="button"
              onClick={() => void setThemePreference(theme === 'dark' ? 'light' : 'dark').catch(() => undefined)}
              aria-label={theme === 'dark' ? 'Lichte modus aanzetten' : 'Donkere modus aanzetten'}
              title={theme === 'dark' ? 'Lichte modus' : 'Donkere modus'}
            >
              {theme === 'dark' ? <Sun size={18} /> : <Moon size={18} />}
            </button>
            <button className="icon-button" type="button" onClick={logout} aria-label="Uitloggen">
              <LogOut size={18} />
            </button>
          </div>
        </header>
        <main className="content" id="main-content" tabIndex={-1}>
          {children}
        </main>
      </div>
      {profileCompletionRequired && user !== null ? (
        <ProfileCompletionModal user={user} api={api} refreshMe={refreshMe} />
      ) : null}
    </div>
  );
}

interface ProfileCompletionFormState {
  firstName: string;
  lastName: string;
  phoneNumber: string;
  homeCity: string;
  homeRegion: string;
  homeCountry: string;
}

function ProfileCompletionModal({
  user,
  api,
  refreshMe,
}: {
  user: User;
  api: ApiClient;
  refreshMe: () => Promise<User | null>;
}) {
  const [form, setForm] = useState<ProfileCompletionFormState>(() => profileFormFromUser(user));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const regionOptions = regionOptionsForCountry(form.homeCountry);

  useEffect(() => {
    setForm(profileFormFromUser(user));
    setError(null);
  }, [user]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError(null);

    try {
      await api.patch<User>('/auth/me', {
        first_name: form.firstName.trim(),
        last_name: form.lastName.trim(),
        phone_number: form.phoneNumber.trim(),
        home_city: form.homeCity.trim(),
        home_region: form.homeRegion.trim() === '' ? null : form.homeRegion.trim(),
        home_country: form.homeCountry,
      });
      await refreshMe();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Gegevens konden niet worden opgeslagen.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="modal-backdrop" role="presentation">
      <section className="modal modal--narrow" role="dialog" aria-modal="true" aria-labelledby="profile-completion-title">
        <header className="modal__header">
          <div>
            <span className="modal__eyebrow">Profiel aanvullen</span>
            <h2 id="profile-completion-title">Vul je gegevens aan</h2>
          </div>
        </header>
        <p className="muted-text">Voor de operationele bereikbaarheid zijn voornaam, achternaam, internationaal telefoonnummer en woonlocatie nodig.</p>
        <form className="form-grid" onSubmit={submit}>
          <label>
            Voornaam
            <input value={form.firstName} maxLength={80} required onChange={(event) => setForm((current) => ({ ...current, firstName: event.target.value }))} />
          </label>
          <label>
            Achternaam
            <input value={form.lastName} maxLength={120} required onChange={(event) => setForm((current) => ({ ...current, lastName: event.target.value }))} />
          </label>
          <label>
            Telefoonnummer
            <input value={form.phoneNumber} inputMode="tel" autoComplete="tel" placeholder="+31612345678" required onChange={(event) => setForm((current) => ({ ...current, phoneNumber: event.target.value }))} />
            <small>Een Nederlands nummer zoals 0612345678 wordt opgeslagen als +31612345678.</small>
          </label>
          <label>
            Woonplaats
            <input value={form.homeCity} maxLength={120} required onChange={(event) => setForm((current) => ({ ...current, homeCity: event.target.value }))} />
          </label>
          <label>
            Land
            <select
              value={form.homeCountry}
              required
              onChange={(event) => setForm((current) => {
                const nextCountry = event.target.value;
                const nextRegions = regionOptionsForCountry(nextCountry);
                return {
                  ...current,
                  homeCountry: nextCountry,
                  homeRegion: nextRegions.includes(current.homeRegion) ? current.homeRegion : '',
                };
              })}
            >
              {countryOptions.map((country) => (
                <option key={country.value} value={country.value}>{country.label}</option>
              ))}
            </select>
          </label>
          <label>
            Provincie / regio
            <select
              value={form.homeRegion}
              required={regionOptions.length > 0}
              disabled={regionOptions.length === 0}
              onChange={(event) => setForm((current) => ({ ...current, homeRegion: event.target.value }))}
            >
              <option value="">Kies provincie/regio</option>
              {regionOptions.map((region) => (
                <option key={region} value={region}>{region}</option>
              ))}
            </select>
          </label>
          {error ? <p className="form-error form-grid__wide">{error}</p> : null}
          <div className="actions-row form-grid__wide">
            <button className="primary-button" type="submit" disabled={saving}>
              {saving ? 'Opslaan...' : 'Opslaan en doorgaan'}
            </button>
          </div>
        </form>
      </section>
    </div>
  );
}

function profileFormFromUser(user: User): ProfileCompletionFormState {
  return {
    firstName: user.first_name ?? firstNameFromDisplayName(user.name),
    lastName: user.last_name ?? lastNameFromDisplayName(user.name),
    phoneNumber: user.phone_number ?? '',
    homeCity: user.home_city ?? '',
    homeRegion: user.home_region ?? '',
    homeCountry: user.home_country ?? 'NL',
  };
}

function firstNameFromDisplayName(name: string): string {
  return name.trim().split(/\s+/, 1)[0] ?? '';
}

function lastNameFromDisplayName(name: string): string {
  const parts = name.trim().split(/\s+/);
  return parts.length > 1 ? parts.slice(1).join(' ') : '';
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

function documentTitleForBranding(branding: BrandingState, pageTitle?: string): string {
  const appName = nonEmpty(branding.name) ?? nonEmpty(branding.short_name) ?? 'DIS';
  const tenantName = nonEmpty(branding.tenant_name);
  const baseTitle = tenantName !== null && !includesNormalized(appName, tenantName)
    ? `${tenantName} - ${appName}`
    : appName;

  return pageTitle === undefined ? baseTitle : `${pageTitle} | ${baseTitle}`;
}

function includesNormalized(value: string, search: string): boolean {
  return value.trim().toLocaleLowerCase('nl-NL').includes(search.trim().toLocaleLowerCase('nl-NL'));
}

function nonEmpty(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}
