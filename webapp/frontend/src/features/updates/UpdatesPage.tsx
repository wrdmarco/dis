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
  const [releaseZip, setReleaseZip] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);

    if (releaseZip === null) {
      setError('Kies een release ZIP-bestand.');
      return;
    }

    setSaving(true);
    try {
      const form = new FormData();
      form.append('release_zip', releaseZip);
      await api.postForm('/admin/updates/android/upload', form);
      setReleaseZip(null);
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
      <Panel title="Android release registreren">
        <form className="form-grid" onSubmit={submit}>
          <label className="form-grid__wide">
            Release ZIP
            <input type="file" accept=".zip,application/zip" required onChange={(event) => setReleaseZip(event.target.files?.[0] ?? null)} />
          </label>
          <div className="form-grid__wide metadata-example">
            <strong>ZIP inhoud</strong>
            <pre>{`dis-nl.wrdmarco.dis-v0.1.2.zip
├── dis-nl.wrdmarco.dis-v0.1.2.apk
└── metadata.json`}</pre>
          </div>
          <div className="actions-row form-grid__wide">
            <button className="primary-button" type="submit" disabled={saving}>
              {saving ? 'Registreren...' : 'Release registreren'}
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
