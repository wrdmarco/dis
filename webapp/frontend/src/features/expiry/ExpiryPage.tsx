import { useState } from 'react';
import type { ReactNode } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { useApiResource } from '../../lib/useApiResource';
import type { ExpiryOverview } from '../../types/api';

export function ExpiryPage() {
  const [days, setDays] = useState(60);
  const overview = useApiResource<ExpiryOverview>(`/expiry-overview?days=${days}`);
  const assets = groupByDeadline(overview.data?.assets ?? [], (asset) => asset.maintenance_due_at);
  const certifications = groupByDeadline(overview.data?.certifications ?? [], (certification) => certification.expires_at);

  return (
    <div className="page-stack">
      <Panel
        title="Verloop"
        action={(
          <select value={days} onChange={(event) => setDays(Number(event.target.value))} aria-label="Periode">
            <option value={30}>30 dagen</option>
            <option value={60}>60 dagen</option>
            <option value={90}>90 dagen</option>
            <option value={180}>180 dagen</option>
          </select>
        )}
      >
        <ResourceState loading={overview.loading} error={overview.error} empty={!overview.data}>
          <div className="summary-grid">
            <div className="summary-card">
              <span>Assets</span>
              <strong>{overview.data?.assets.length ?? 0}</strong>
            </div>
            <div className="summary-card">
              <span>Certificaten</span>
              <strong>{overview.data?.certifications.length ?? 0}</strong>
            </div>
            <div className="summary-card">
              <span>Tot en met</span>
              <strong>{formatDate(overview.data?.until)}</strong>
            </div>
          </div>
        </ResourceState>
      </Panel>

      <Panel title="Assets met onderhoudsdatum">
        <ResourceState loading={overview.loading} error={overview.error} empty={(overview.data?.assets.length ?? 0) === 0}>
          <ExpiryGroup title="Kritiek" count={assets.critical.length}>
            <AssetTable assets={assets.critical} />
          </ExpiryGroup>
          <ExpiryGroup title="Binnen 30 dagen" count={assets.soon.length}>
            <AssetTable assets={assets.soon} />
          </ExpiryGroup>
          <ExpiryGroup title="Later" count={assets.later.length}>
            <AssetTable assets={assets.later} />
          </ExpiryGroup>
        </ResourceState>
      </Panel>

      <Panel title="Certificaten die verlopen">
        <ResourceState loading={overview.loading} error={overview.error} empty={(overview.data?.certifications.length ?? 0) === 0}>
          <ExpiryGroup title="Kritiek" count={certifications.critical.length}>
            <CertificationTable certifications={certifications.critical} />
          </ExpiryGroup>
          <ExpiryGroup title="Binnen 30 dagen" count={certifications.soon.length}>
            <CertificationTable certifications={certifications.soon} />
          </ExpiryGroup>
          <ExpiryGroup title="Later" count={certifications.later.length}>
            <CertificationTable certifications={certifications.later} />
          </ExpiryGroup>
        </ResourceState>
      </Panel>
    </div>
  );
}

interface ExpiryGroupProps {
  title: string;
  count: number;
  children: ReactNode;
}

function ExpiryGroup({ title, count, children }: ExpiryGroupProps) {
  if (count === 0) {
    return null;
  }

  return (
    <section className="expiry-group">
      <header className="expiry-group__header">
        <strong>{title}</strong>
        <span>{count}</span>
      </header>
      {children}
    </section>
  );
}

function AssetTable({ assets }: { assets: ExpiryOverview['assets'] }) {
  return (
    <table className="data-table">
      <thead><tr><th>Asset</th><th>Tag</th><th>Type</th><th>Status</th><th>Onderhoud</th><th>Termijn</th></tr></thead>
      <tbody>
        {assets.map((asset) => (
          <tr key={asset.id}>
            <td>{asset.name}</td>
            <td className="mono">{asset.asset_tag}</td>
            <td>{asset.drone_type?.model ?? asset.type}</td>
            <td><StatusPill value={asset.status} tone={asset.status === 'ready' ? 'good' : asset.status === 'maintenance' ? 'warn' : 'neutral'} /></td>
            <td>{formatDate(asset.maintenance_due_at)}</td>
            <td>{deadlineLabel(asset.maintenance_due_at)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function CertificationTable({ certifications }: { certifications: ExpiryOverview['certifications'] }) {
  return (
    <table className="data-table">
      <thead><tr><th>Gebruiker</th><th>Certificaat</th><th>Status</th><th>Nummer</th><th>Verloopt</th><th>Termijn</th></tr></thead>
      <tbody>
        {certifications.map((certification) => (
          <tr key={certification.id}>
            <td><strong>{certification.user_name ?? '-'}</strong><br /><span>{certification.user_email ?? '-'}</span></td>
            <td>{certification.certification_name ?? certification.certification_code ?? '-'}</td>
            <td><StatusPill value={certification.status} tone={certification.status === 'active' ? 'good' : certification.status === 'expired' ? 'bad' : 'warn'} /></td>
            <td>{certification.certificate_number ?? '-'}</td>
            <td>{formatDate(certification.expires_at)}</td>
            <td>{deadlineLabel(certification.expires_at)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function groupByDeadline<T>(items: T[], getDate: (item: T) => string | null | undefined): { critical: T[]; soon: T[]; later: T[] } {
  return {
    critical: items.filter((item) => daysUntil(getDate(item)) <= 7),
    soon: items.filter((item) => {
      const days = daysUntil(getDate(item));
      return days > 7 && days <= 30;
    }),
    later: items.filter((item) => daysUntil(getDate(item)) > 30),
  };
}

function formatDate(value?: string | null): string {
  if (value === undefined || value === null || value === '') {
    return '-';
  }

  return new Intl.DateTimeFormat('nl-NL', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(value));
}

function deadlineLabel(value?: string | null): string {
  if (value === undefined || value === null || value === '') {
    return '-';
  }

  const days = daysUntil(value);

  if (days < 0) {
    return `${Math.abs(days)} dag(en) verlopen`;
  }

  if (days === 0) {
    return 'Vandaag';
  }

  return `${days} dag(en)`;
}

function daysUntil(value?: string | null): number {
  if (value === undefined || value === null || value === '') {
    return Number.POSITIVE_INFINITY;
  }

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const deadline = new Date(value);
  deadline.setHours(0, 0, 0, 0);

  return Math.round((deadline.getTime() - today.getTime()) / 86_400_000);
}
