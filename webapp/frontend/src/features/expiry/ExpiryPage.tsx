import { useState } from 'react';
import type { ReactNode } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { assetTypeLabel } from '../../lib/assetLabels';
import { assetStatusPresentation } from '../../lib/assetStatus';
import { daysUntilAmsterdamDate, formatDateOnly } from '../../lib/dateTime';
import { droneTypeLabel } from '../../lib/droneTypes';
import { useApiResource } from '../../lib/useApiResource';
import type { ExpiryOverview } from '../../types/api';
import styles from './ExpiryPage.module.css';

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
            <AssetTable assets={assets.critical} label="Kritieke assets" />
          </ExpiryGroup>
          <ExpiryGroup title="Binnen 30 dagen" count={assets.soon.length}>
            <AssetTable assets={assets.soon} label="Assets met onderhoud binnen 30 dagen" />
          </ExpiryGroup>
          <ExpiryGroup title="Later" count={assets.later.length}>
            <AssetTable assets={assets.later} label="Assets met later gepland onderhoud" />
          </ExpiryGroup>
        </ResourceState>
      </Panel>

      <Panel title="Certificaten die verlopen">
        <ResourceState loading={overview.loading} error={overview.error} empty={(overview.data?.certifications.length ?? 0) === 0}>
          <ExpiryGroup title="Kritiek" count={certifications.critical.length}>
            <CertificationTable certifications={certifications.critical} label="Kritieke certificaten" />
          </ExpiryGroup>
          <ExpiryGroup title="Binnen 30 dagen" count={certifications.soon.length}>
            <CertificationTable certifications={certifications.soon} label="Certificaten die binnen 30 dagen verlopen" />
          </ExpiryGroup>
          <ExpiryGroup title="Later" count={certifications.later.length}>
            <CertificationTable certifications={certifications.later} label="Certificaten die later verlopen" />
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

function AssetTable({ assets, label }: { assets: ExpiryOverview['assets']; label: string }) {
  return (
    <table className={`data-table ${styles.table} ${styles.assetTable}`} aria-label={label}>
      <thead><tr><th scope="col">Asset</th><th scope="col">Tag</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Onderhoud</th><th scope="col">Termijn</th></tr></thead>
      <tbody>
        {assets.map((asset) => {
          const status = assetStatusPresentation(asset);

          return (
            <tr className={status.maintenanceOverdue ? styles.overdueRow : undefined} key={asset.id}>
              <td className={styles.primaryCell} data-label="Asset">{asset.name}</td>
              <td className="mono" data-label="Tag">{asset.asset_tag}</td>
              <td data-label="Type">{asset.drone_type ? droneTypeLabel(asset.drone_type) : assetTypeLabel(asset.type)}</td>
              <td className={styles.statusCell} data-label="Status"><StatusPill value={status.label} tone={status.tone} /></td>
              <td data-label="Onderhoud">{formatDate(asset.maintenance_due_at)}</td>
              <td data-label="Termijn">{deadlineLabel(asset.maintenance_due_at)}</td>
            </tr>
          );
        })}
      </tbody>
    </table>
  );
}

function CertificationTable({ certifications, label }: { certifications: ExpiryOverview['certifications']; label: string }) {
  return (
    <table className={`data-table ${styles.table} ${styles.certificationTable}`} aria-label={label}>
      <thead><tr><th scope="col">Gebruiker</th><th scope="col">Certificaat</th><th scope="col">Status</th><th scope="col">Nummer</th><th scope="col">Verloopt</th><th scope="col">Termijn</th></tr></thead>
      <tbody>
        {certifications.map((certification) => (
          <tr key={certification.id}>
            <td className={styles.primaryCell} data-label="Gebruiker"><strong>{certification.user_name ?? '-'}</strong><br /><span>{certification.user_email ?? '-'}</span></td>
            <td data-label="Certificaat">{certification.certification_name ?? certification.certification_code ?? '-'}</td>
            <td className={styles.statusCell} data-label="Status"><StatusPill value={certification.status} tone={certification.status === 'active' ? 'good' : certification.status === 'expired' ? 'bad' : 'warn'} /></td>
            <td className="mono" data-label="Nummer">{certification.certificate_number ?? '-'}</td>
            <td data-label="Verloopt">{formatDate(certification.expires_at)}</td>
            <td data-label="Termijn">{deadlineLabel(certification.expires_at)}</td>
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
  return formatDateOnly(value);
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
  return daysUntilAmsterdamDate(value) ?? Number.POSITIVE_INFINITY;
}
