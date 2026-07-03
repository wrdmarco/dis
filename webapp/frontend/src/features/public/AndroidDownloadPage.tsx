import { Download, ShieldCheck, Smartphone } from 'lucide-react';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { TotpQrCode } from '../../components/TotpQrCode';
import { apiBaseUrl } from '../../lib/apiClient';
import type { AppVersion, ApiResponse } from '../../types/api';

interface AndroidUpdatePolicy {
  update_required: boolean;
  latest: AppVersion | null;
}

type Channel = {
  key: string;
  title: string;
  applicationId?: string;
  latest: AppVersion | null;
};

export function AndroidDownloadPage() {
  const [channels, setChannels] = useState<Channel[]>([
    { key: 'operator', title: 'Operator app', latest: null },
    { key: 'admin', title: 'Admin app', applicationId: 'nl.wrdmarco.dis.admin', latest: null },
  ]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function loadLatestVersion() {
      setLoading(true);
      setError(null);
      try {
        const nextChannels = await Promise.all([
          loadChannel('operator', 'Operator app'),
          loadChannel('admin', 'Admin app', 'nl.wrdmarco.dis.admin'),
        ]);

        if (!cancelled) setChannels(nextChannels);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'APK informatie kon niet worden geladen.');
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    void loadLatestVersion();

    return () => {
      cancelled = true;
    };
  }, []);

  const setupUrl = typeof window === 'undefined' ? '' : window.location.origin;

  return (
    <main className="public-download-shell">
      <section className="public-download-panel" aria-labelledby="download-title">
        <div className="public-download-panel__mark">
          <Smartphone aria-hidden size={30} />
        </div>
        <span className="topbar__eyebrow">Nationaal Droneteam</span>
        <h1 id="download-title">D.I.S Android APK</h1>

        {loading ? <p className="public-download-panel__status">APK informatie laden...</p> : null}
        {error ? <p className="form-error">{error}</p> : null}

        {!loading && !error ? (
          <div className="download-channel-list">
            {channels.map((channel) => (
              <DownloadChannelCard key={channel.key} channel={channel} />
            ))}
          </div>
        ) : null}

        {!loading && !error && channels.every((channel) => channel.latest === null) ? (
          <p className="public-download-panel__status">Er is nog geen Android APK gepubliceerd.</p>
        ) : null}

        <div className="public-download-panel__actions">
          <Link className="secondary-button" href="/login">
            Command Center
          </Link>
        </div>

        <div className="download-setup-qr">
          <div>
            <strong>Setup URL</strong>
            <p>Scan deze QR-code bij de eerste start van de Android app.</p>
            <code>{setupUrl}</code>
          </div>
          <TotpQrCode value={setupUrl} alt="QR-code met DIS setup URL" helpText="Scan met de Android app om deze server te koppelen." />
        </div>

        <div className="download-integrity">
          <ShieldCheck aria-hidden size={18} />
          <span>Controleer de SHA-256 hash na download wanneer deze beschikbaar is.</span>
        </div>
      </section>
    </main>
  );
}

async function loadChannel(key: string, title: string, applicationId?: string): Promise<Channel> {
  const params = new URLSearchParams({ version_code: '0' });
  if (applicationId) params.set('application_id', applicationId);

  const response = await fetch(`${apiBaseUrl}/updates/android/current?${params.toString()}`, {
    headers: { Accept: 'application/json' },
  });
  const payload = (await response.json()) as ApiResponse<AndroidUpdatePolicy>;

  if (!response.ok) {
    throw new Error('APK informatie kon niet worden geladen.');
  }

  return { key, title, applicationId, latest: payload.data.latest };
}

function DownloadChannelCard({ channel }: { channel: Channel }) {
  const latest = channel.latest;
  const canDownload = latest?.download_url;

  return (
    <div className="download-card">
      <div>
        <span>App</span>
        <strong>{channel.title}</strong>
      </div>
      <div>
        <span>Laatste versie</span>
        <strong>{latest?.version_name ?? '-'}</strong>
      </div>
      <div>
        <span>Version code</span>
        <strong>{latest?.version_code ?? '-'}</strong>
      </div>
      <div>
        <span>Status</span>
        <strong>{latest?.status ?? 'niet gepubliceerd'}</strong>
      </div>
      <div className="download-card__hash">
        <span>SHA-256</span>
        <code>{latest?.artifact_sha256 ?? '-'}</code>
      </div>
      <a className={`primary-button ${canDownload ? '' : 'primary-button--disabled'}`} href={canDownload || undefined}>
        <Download aria-hidden size={18} />
        {channel.title} downloaden
      </a>
      {latest && !canDownload ? (
        <p className="public-download-panel__status">Deze versie is geregistreerd, maar het APK-bestand ontbreekt nog.</p>
      ) : null}
    </div>
  );
}
