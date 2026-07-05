import { Download, ShieldCheck, Smartphone } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { TotpQrCode } from '../../components/TotpQrCode';
import { apiBaseUrl } from '../../lib/apiClient';
import type { AppVersion, ApiResponse } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

interface AndroidUpdatePolicy {
  update_required: boolean;
  latest: AppVersion | null;
}

type Channel = {
  key: string;
  title: string;
  access: 'operator' | 'admin';
  platform: 'android' | 'ios';
  applicationId?: string;
  latest: AppVersion | null;
};

export function AndroidDownloadPage() {
  const { user } = useAuth();
  const canUseOperatorApp = user?.roles?.some((role) => role.can_use_operator_app) === true;
  const canUseAdminApp = user?.roles?.some((role) => role.can_use_admin_app) === true;
  const allowedChannelDefinitions = useMemo(() => channelDefinitions.filter((channel) =>
    channel.access === 'operator' ? canUseOperatorApp : canUseAdminApp,
  ), [canUseAdminApp, canUseOperatorApp]);
  const [channels, setChannels] = useState<Channel[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function loadLatestVersion() {
      if (allowedChannelDefinitions.length === 0) {
        setChannels([]);
        setLoading(false);
        setError(null);
        return;
      }

      setLoading(true);
      setError(null);
      try {
        const nextChannels = await Promise.all(allowedChannelDefinitions.map((channel) =>
          loadChannel(channel.platform, channel.key, channel.title, channel.access, channel.applicationId),
        ));

        if (!cancelled) setChannels(nextChannels);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'App informatie kon niet worden geladen.');
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
  }, [allowedChannelDefinitions]);

  const setupUrl = typeof window === 'undefined' ? '' : window.location.origin;

  return (
    <div className="public-download-shell">
      <section className="public-download-panel" aria-labelledby="download-title">
        <div className="public-download-panel__mark">
          <Smartphone aria-hidden size={30} />
        </div>
        <span className="topbar__eyebrow">Nationaal Droneteam</span>
        <h1 id="download-title">D.I.S mobiele apps</h1>

        {loading ? <p className="public-download-panel__status">App informatie laden...</p> : null}
        {error ? <p className="form-error">{error}</p> : null}

        {!loading && !error ? (
          <div className="download-channel-list">
            {channels.map((channel) => (
              <DownloadChannelCard key={channel.key} channel={channel} />
            ))}
          </div>
        ) : null}

        {!loading && !error && allowedChannelDefinitions.length === 0 ? (
          <p className="public-download-panel__status">Er is geen software beschikbaar voor jouw account.</p>
        ) : null}

        {!loading && !error && allowedChannelDefinitions.length > 0 && channels.every((channel) => channel.latest === null) ? (
          <p className="public-download-panel__status">Er is nog geen mobiele app gepubliceerd.</p>
        ) : null}

        <div className="public-download-panel__actions">
          <Link className="secondary-button" href="/login">
            Command Center
          </Link>
        </div>

        <div className="download-setup-qr">
          <div>
            <strong>Setup URL</strong>
            <p>Scan deze QR-code bij de eerste start van de mobiele app.</p>
            <code>{setupUrl}</code>
          </div>
          <TotpQrCode value={setupUrl} alt="QR-code met DIS setup URL" helpText="Scan met de mobiele app om deze server te koppelen." />
        </div>

        <div className="download-integrity">
          <ShieldCheck aria-hidden size={18} />
          <span>Controleer de SHA-256 hash na download wanneer deze beschikbaar is.</span>
        </div>
      </section>
    </div>
  );
}

const channelDefinitions: Array<Omit<Channel, 'latest'>> = [
  { key: 'operator-android', title: 'Operator Android', access: 'operator', platform: 'android' },
  { key: 'admin-android', title: 'Admin Android', access: 'admin', platform: 'android', applicationId: 'nl.wrdmarco.dis.admin' },
  { key: 'operator-ios', title: 'Operator iPhone', access: 'operator', platform: 'ios', applicationId: 'nl.wrdmarco.dis.ios' },
];

async function loadChannel(platform: 'android' | 'ios', key: string, title: string, access: 'operator' | 'admin', applicationId?: string): Promise<Channel> {
  const params = new URLSearchParams({ version_code: '0' });
  if (applicationId) params.set('application_id', applicationId);

  const response = await fetch(`${apiBaseUrl}/updates/${platform}/current?${params.toString()}`, {
    headers: { Accept: 'application/json' },
  });
  const payload = (await response.json()) as ApiResponse<AndroidUpdatePolicy>;

  if (!response.ok) {
    throw new Error('App informatie kon niet worden geladen.');
  }

  return { key, title, access, platform, applicationId, latest: payload.data.latest };
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
        <span>Buildnummer</span>
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
        {channel.title} openen
      </a>
      {latest && !canDownload ? (
        <p className="public-download-panel__status">Deze versie is geregistreerd, maar er is nog geen downloadlink.</p>
      ) : null}
    </div>
  );
}
