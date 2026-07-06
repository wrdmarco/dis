import { Download, ShieldCheck, Smartphone, Store } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { TotpQrCode } from '../../components/TotpQrCode';
import { apiBaseUrl } from '../../lib/apiClient';
import type { AppVersion, ApiResponse, SoftwareDownloadChannelOptions, SoftwareDownloadOptions } from '../../types/api';
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
  const { api, user } = useAuth();
  const canUseOperatorApp = user?.roles?.some((role) => role.can_use_operator_app) === true;
  const canUseAdminApp = user?.roles?.some((role) => role.can_use_admin_app) === true;
  const allowedChannelDefinitions = useMemo(() => channelDefinitions.filter((channel) =>
    channel.access === 'operator' ? canUseOperatorApp : canUseAdminApp,
  ), [canUseAdminApp, canUseOperatorApp]);
  const [channels, setChannels] = useState<Channel[]>([]);
  const [downloadOptions, setDownloadOptions] = useState<Record<string, SoftwareDownloadChannelOptions>>({});
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
        const [nextChannels, options] = await Promise.all([
          Promise.all(allowedChannelDefinitions.map((channel) =>
            loadChannel(channel.platform, channel.key, channel.title, channel.access, channel.applicationId),
          )),
          api.get<SoftwareDownloadOptions>('/software/download-options'),
        ]);

        if (!cancelled) {
          setChannels(nextChannels);
          setDownloadOptions(options.data.channels);
        }
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
  }, [allowedChannelDefinitions, api]);

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
              <DownloadChannelCard key={channel.key} channel={channel} options={downloadOptions[channel.key]} />
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

function DownloadChannelCard({ channel, options }: { channel: Channel; options?: SoftwareDownloadChannelOptions }) {
  const latest = channel.latest;
  const source = options?.source ?? 'direct';
  const appStoreUrl = options?.app_store_url?.trim() ?? '';
  const directUrl = latest?.download_url ?? '';
  const downloadUrl = source === 'app_store' ? appStoreUrl : directUrl;
  const canDownload = downloadUrl !== '';
  const sourceLabel = source === 'app_store' ? storeLabel(channel.platform) : 'Directe download';
  const qrUrl = resolveBrowserUrl(downloadUrl);

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
        <span>Downloadbron</span>
        <strong>{sourceLabel}</strong>
      </div>
      {source === 'direct' ? (
        <div>
          <span>Status</span>
          <strong>{latest?.status ?? 'niet gepubliceerd'}</strong>
        </div>
      ) : null}
      <div className="download-card__hash">
        <span>SHA-256</span>
        <code>{source === 'direct' ? latest?.artifact_sha256 ?? '-' : 'Appstore-download'}</code>
      </div>
      <a className={`primary-button ${canDownload ? '' : 'primary-button--disabled'}`} href={canDownload ? downloadUrl : undefined}>
        {source === 'app_store' ? <Store aria-hidden size={18} /> : <Download aria-hidden size={18} />}
        {channel.title} openen
      </a>
      {qrUrl ? (
        <div className="download-card__qr">
          <div>
            <span>QR-code download</span>
            <strong>Scan om deze app te openen</strong>
            <code>{qrUrl}</code>
          </div>
          <TotpQrCode value={qrUrl} alt={`QR-code download ${channel.title}`} helpText="Scan om de downloadlink te openen." />
        </div>
      ) : null}
      {!canDownload ? <p className="public-download-panel__status">{missingDownloadText(source, latest)}</p> : null}
    </div>
  );
}

function storeLabel(platform: Channel['platform']): string {
  return platform === 'ios' ? 'Apple App Store/TestFlight' : 'Google Play';
}

function missingDownloadText(source: SoftwareDownloadChannelOptions['source'], latest: AppVersion | null): string {
  if (source === 'app_store') {
    return 'Deze app staat op appstore-download, maar de appstore-link is nog niet ingesteld.';
  }

  return latest
    ? 'Deze versie is geregistreerd, maar er is nog geen downloadlink.'
    : 'Er is nog geen mobiele app gepubliceerd.';
}

function resolveBrowserUrl(url: string): string {
  if (url === '') {
    return '';
  }

  if (typeof window === 'undefined') {
    return url;
  }

  try {
    return new URL(url, window.location.origin).toString();
  } catch {
    return url;
  }
}
