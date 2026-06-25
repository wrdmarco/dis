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
  const [versionName, setVersionName] = useState('');
  const [versionCode, setVersionCode] = useState('');
  const [status, setStatus] = useState('supported');
  const [sha, setSha] = useState('');
  const [apk, setApk] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    try {
      if (apk) {
        const form = new FormData();
        form.append('version_name', versionName);
        form.append('version_code', versionCode);
        form.append('status', status);
        form.append('apk', apk);
        await api.postForm('/admin/updates/android/upload', form);
      } else {
        await api.post('/admin/updates/android', {
          version_name: versionName,
          version_code: Number(versionCode),
          status,
          artifact_sha256: sha || null,
        });
      }
      setVersionName('');
      setVersionCode('');
      setSha('');
      setApk(null);
      await versions.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Appversie kon niet worden geregistreerd.');
    }
  };

  return (
    <div className="page-stack">
      <Panel title="Android versie registreren">
        <form className="inline-form" onSubmit={submit}>
          <input value={versionName} onChange={(event) => setVersionName(event.target.value)} placeholder="Versienaam" required />
          <input type="number" min="1" value={versionCode} onChange={(event) => setVersionCode(event.target.value)} placeholder="Version code" required />
          <select value={status} onChange={(event) => setStatus(event.target.value)}>
            <option value="supported">Supported</option>
            <option value="deprecated">Deprecated</option>
            <option value="blocked">Blocked</option>
          </select>
          <input value={sha} onChange={(event) => setSha(event.target.value)} placeholder="SHA-256 artifact" />
          <input type="file" accept=".apk,application/vnd.android.package-archive" onChange={(event) => setApk(event.target.files?.[0] ?? null)} />
          <button className="primary-button" type="submit">Registreren</button>
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
