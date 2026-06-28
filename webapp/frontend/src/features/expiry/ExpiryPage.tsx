import { useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { useApiResource } from '../../lib/useApiResource';
import type { ExpiryOverview } from '../../types/api';

export function ExpiryPage() {
  const [days, setDays] = useState(60);
  const overview = useApiResource<ExpiryOverview>(`/expiry-overview?days=${days}`);

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
          <table className="data-table">
            <thead><tr><th>Asset</th><th>Tag</th><th>Type</th><th>Status</th><th>Onderhoud</th><th>Termijn</th></tr></thead>
            <tbody>
              {overview.data?.assets.map((asset) => (
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
        </ResourceState>
      </Panel>

      <Panel title="Certificaten die verlopen">
        <ResourceState loading={overview.loading} error={overview.error} empty={(overview.data?.certifications.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Gebruiker</th><th>Certificaat</th><th>Status</th><th>Nummer</th><th>Verloopt</th><th>Termijn</th></tr></thead>
            <tbody>
              {overview.data?.certifications.map((certification) => (
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
        </ResourceState>
      </Panel>
    </div>
  );
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

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const deadline = new Date(value);
  deadline.setHours(0, 0, 0, 0);
  const days = Math.round((deadline.getTime() - today.getTime()) / 86_400_000);

  if (days < 0) {
    return `${Math.abs(days)} dag(en) verlopen`;
  }

  if (days === 0) {
    return 'Vandaag';
  }

  return `${days} dag(en)`;
}
