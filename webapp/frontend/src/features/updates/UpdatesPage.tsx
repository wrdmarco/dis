import { FormEvent, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { AppVersion } from '../../types/api';

export function UpdatesPage() {
  const { api } = useAuth();
  const versions = useApiResource<AppVersion[]>('/admin/updates/android');
  const [apk, setApk] = useState<File | null>(null);
  const [metadata, setMetadata] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);

    if (apk === null || metadata === null) {
      setError('Kies zowel de APK als het metadata JSON-bestand.');
      return;
    }

    setSaving(true);
    try {
      const form = new FormData();
      form.append('apk', apk);
      form.append('metadata', metadata);
      await api.postForm('/admin/updates/android/upload', form);
      setApk(null);
      setMetadata(null);
      event.currentTarget.reset();
      await versions.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Appversie kon niet worden geregistreerd.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="page-stack">
      <Panel title="Android APK registreren">
        <form className="form-grid" onSubmit={submit}>
          <label>
            APK bestand
            <input type="file" accept=".apk,application/vnd.android.package-archive" required onChange={(event) => setApk(event.target.files?.[0] ?? null)} />
          </label>
          <label>
            Metadata JSON
            <input type="file" accept="application/json,.json" required onChange={(event) => setMetadata(event.target.files?.[0] ?? null)} />
          </label>
          <div className="form-grid__wide metadata-example">
            <strong>Metadata voorbeeld</strong>
            <pre>{`{
  "version_name": "0.1.2",
  "version_code": 3,
  "status": "supported",
  "artifact_sha256": "optioneel_exacte_apk_sha256_hash",
  "release_notes": "Korte release notes"
}`}</pre>
          </div>
          <div className="actions-row form-grid__wide">
            <button className="primary-button" type="submit" disabled={saving}>
              {saving ? 'Registreren...' : 'APK registreren'}
            </button>
          </div>
        </form>
        {error && <p className="form-error">{error}</p>}
      </Panel>
      <Panel title="Android updates">
        <ResourceState loading={versions.loading} error={versions.error} empty={(versions.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Versie</th><th>Code</th><th>Status</th><th>SHA-256</th><th>APK</th></tr></thead>
            <tbody>
              {versions.data?.map((version) => (
                <tr key={version.id}>
                  <td>{version.version_name}</td>
                  <td>{version.version_code}</td>
                  <td><StatusPill value={version.status} tone={version.status === 'blocked' ? 'bad' : version.status === 'deprecated' ? 'warn' : 'good'} /></td>
                  <td className="mono">{version.artifact_sha256 ?? '-'}</td>
                  <td>{version.download_url ? <a href={version.download_url}>Download</a> : '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
    </div>
  );
}
