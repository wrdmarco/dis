import { useEffect, useMemo, useState } from 'react';
import { Activity, CheckCircle2, RefreshCw, TriangleAlert } from 'lucide-react';
import { apiBaseUrl } from '../../lib/apiClient';
import type { ApiResponse, PublicStatusResponse } from '../../types/api';

const frontendStartedAt = Date.now();

export function PublicStatusPage() {
  const [status, setStatus] = useState<PublicStatusResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [now, setNow] = useState(Date.now());

  const frontendUptimeSeconds = useMemo(() => Math.floor((now - frontendStartedAt) / 1000), [now]);

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  async function loadStatus() {
    setLoading(true);
    setError(null);
    try {
      const response = await fetch(`${apiBaseUrl}/public/status`, { headers: { Accept: 'application/json' } });
      const payload = await response.json() as ApiResponse<PublicStatusResponse>;
      if (!response.ok) {
        throw new Error(payload.error?.message ?? 'Status kon niet worden opgehaald.');
      }
      setStatus(payload.data);
    } catch (err) {
      setStatus(null);
      setError(err instanceof Error ? err.message : 'Status kon niet worden opgehaald.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void loadStatus();
  }, []);

  const overall = error ? 'failed' : status?.status ?? 'unknown';

  return (
    <main className="public-download-shell">
      <section className="public-download-panel public-status-panel" aria-labelledby="status-title">
        <div className="public-download-panel__mark">
          <Activity size={32} />
        </div>
        <h1 id="status-title">D.I.S status</h1>
        <p className="public-download-panel__status">Publieke beschikbaarheid van de webapp en kernservices.</p>

        <div className={`public-status-summary public-status-summary--${overall}`}>
          {overall === 'ok' ? <CheckCircle2 size={22} /> : <TriangleAlert size={22} />}
          <strong>{overallLabel(overall)}</strong>
          <span>{status?.generated_at ? `Laatste controle: ${formatDateTime(status.generated_at)}` : 'Nog geen controle uitgevoerd'}</span>
        </div>

        <div className="public-status-grid">
          <StatusCard
            name="Frontend"
            status="ok"
            detail="Webapp geladen"
            uptimeSeconds={frontendUptimeSeconds}
          />
          {Object.entries(status?.services ?? {}).map(([name, service]) => (
            <StatusCard
              key={name}
              name={serviceLabel(name)}
              status={service.status}
              detail={service.connection ?? service.store ?? service.driver ?? null}
              uptimeSeconds={service.uptime_seconds}
            />
          ))}
        </div>

        {loading ? <p className="public-download-panel__status">Status ophalen...</p> : null}
        {error ? <p className="resource-state resource-state--error">{error}</p> : null}

        <div className="public-download-panel__actions">
          <button className="secondary-button" type="button" onClick={() => void loadStatus()} disabled={loading}>
            <RefreshCw size={16} /> Vernieuwen
          </button>
          <a className="public-link" href="/login">Inloggen</a>
        </div>
      </section>
    </main>
  );
}

function StatusCard(props: { name: string; status: string; detail?: string | null; uptimeSeconds?: number | null }) {
  return (
    <article className={`public-status-card public-status-card--${props.status}`}>
      <span>{props.name}</span>
      <strong>{overallLabel(props.status)}</strong>
      <small>{props.detail ?? 'Beschikbaarheid gecontroleerd'}</small>
      <small>Uptime: {formatDuration(props.uptimeSeconds)}</small>
    </article>
  );
}

function overallLabel(status: string): string {
  switch (status) {
    case 'ok':
      return 'Beschikbaar';
    case 'degraded':
      return 'Beperkt beschikbaar';
    case 'failed':
      return 'Niet beschikbaar';
    default:
      return 'Onbekend';
  }
}

function serviceLabel(name: string): string {
  switch (name) {
    case 'backend':
      return 'Backend';
    case 'database':
      return 'Database';
    case 'cache':
      return 'Cache';
    case 'storage':
      return 'Opslag';
    case 'queue':
      return 'Queue';
    case 'websocket':
      return 'Websocket';
    default:
      return name;
  }
}

function formatDuration(seconds?: number | null): string {
  if (seconds === null || seconds === undefined) {
    return '-';
  }

  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  if (days > 0) {
    return `${days}d ${hours}u`;
  }
  if (hours > 0) {
    return `${hours}u ${minutes}m`;
  }

  return `${minutes}m`;
}

function formatDateTime(value: string): string {
  return new Intl.DateTimeFormat('nl-NL', {
    dateStyle: 'short',
    timeStyle: 'medium',
  }).format(new Date(value));
}
