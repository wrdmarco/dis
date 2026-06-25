import { Download, ShieldCheck, Smartphone } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiBaseUrl } from '../../lib/apiClient';
import type { AppVersion, ApiResponse } from '../../types/api';

interface AndroidUpdatePolicy {
  update_required: boolean;
  latest: AppVersion | null;
}

export function AndroidDownloadPage() {
  const [latest, setLatest] = useState<AppVersion | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function loadLatestVersion() {
      setLoading(true);
      setError(null);
      try {
        const response = await fetch(`${apiBaseUrl}/updates/android/current?version_code=0`, {
          headers: { Accept: 'application/json' },
        });
        const payload = (await response.json()) as ApiResponse<AndroidUpdatePolicy>;

        if (!response.ok) {
          throw new Error('APK informatie kon niet worden geladen.');
        }

        if (!cancelled) {
          setLatest(payload.data.latest);
        }
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

  const canDownload = latest?.download_url;

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

        {!loading && !error && latest ? (
          <div className="download-card">
            <div>
              <span>Laatste versie</span>
              <strong>{latest.version_name}</strong>
            </div>
            <div>
              <span>Version code</span>
              <strong>{latest.version_code}</strong>
            </div>
            <div>
              <span>Status</span>
              <strong>{latest.status}</strong>
            </div>
            <div className="download-card__hash">
              <span>SHA-256</span>
              <code>{latest.artifact_sha256 ?? '-'}</code>
            </div>
          </div>
        ) : null}

        {!loading && !error && !latest ? (
          <p className="public-download-panel__status">Er is nog geen Android APK gepubliceerd.</p>
        ) : null}

        <div className="public-download-panel__actions">
          <a className={`primary-button ${canDownload ? '' : 'primary-button--disabled'}`} href={canDownload || undefined}>
            <Download aria-hidden size={18} />
            APK downloaden
          </a>
          <Link className="secondary-button" to="/login">
            Command Center
          </Link>
        </div>

        <div className="download-integrity">
          <ShieldCheck aria-hidden size={18} />
          <span>Controleer de SHA-256 hash na download wanneer deze beschikbaar is.</span>
        </div>
      </section>
    </main>
  );
}
