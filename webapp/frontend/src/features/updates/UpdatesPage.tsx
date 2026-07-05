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
  const androidVersions = useApiResource<AppVersion[]>('/admin/updates/android');
  const iosVersions = useApiResource<AppVersion[]>('/admin/updates/ios');
  const [releaseZip, setReleaseZip] = useState<File | null>(null);
  const [iosForm, setIosForm] = useState({
    application_id: 'nl.wrdmarco.dis.ios',
    version_name: '',
    version_code: '',
    status: 'supported',
    download_url: '',
    release_notes: '',
  });
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const formElement = event.currentTarget;
    setError(null);
    setSuccess(null);

    if (releaseZip === null) {
      setError('Kies een release ZIP-bestand.');
      return;
    }

    setSaving(true);
    try {
      const form = new FormData();
      form.append('release_zip', releaseZip);
      const response = await api.postForm<AppVersion>('/admin/updates/android/upload', form);
      setReleaseZip(null);
      formElement.reset();
      setSuccess(`Appversie ${response.data.version_name} is geregistreerd.`);

      try {
        await androidVersions.reload();
      } catch {
        setSuccess(`Appversie ${response.data.version_name} is geregistreerd. Vernieuw de pagina als de lijst nog niet is bijgewerkt.`);
      }
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Appversie kon niet worden geregistreerd.');
    } finally {
      setSaving(false);
    }
  };

  const submitIos = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    setSuccess(null);
    setSaving(true);

    try {
      const response = await api.post<AppVersion>('/admin/updates/ios', {
        application_id: iosForm.application_id,
        version_name: iosForm.version_name,
        version_code: Number(iosForm.version_code),
        status: iosForm.status,
        download_url: iosForm.download_url.trim() === '' ? null : iosForm.download_url,
        release_notes: iosForm.release_notes.trim() === '' ? null : iosForm.release_notes,
      });

      setSuccess(`iOS versie ${response.data.version_name} is geregistreerd.`);
      setIosForm((current) => ({ ...current, version_name: '', version_code: '', download_url: '', release_notes: '' }));

      try {
        await iosVersions.reload();
      } catch {
        setSuccess(`iOS versie ${response.data.version_name} is geregistreerd. Vernieuw de pagina als de lijst nog niet is bijgewerkt.`);
      }
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'iOS versie kon niet worden geregistreerd.');
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
            <pre>{`dis-nl.wrdmarco.dis-v0.1.50.zip
|-- dis-nl.wrdmarco.dis-v0.1.50.apk
|-- metadata.json`}</pre>
          </div>
          <div className="actions-row form-grid__wide">
            <button className="primary-button" type="submit" disabled={saving}>
              {saving ? 'Registreren...' : 'Release registreren'}
            </button>
          </div>
        </form>
        {error && <p className="form-error">{error}</p>}
        {success && <p className="success-text">{success}</p>}
      </Panel>
      <Panel title="iOS release importeren">
        <form className="form-grid" onSubmit={submitIos}>
          <label>
            Bundle id
            <input value={iosForm.application_id} onChange={(event) => setIosForm((current) => ({ ...current, application_id: event.target.value }))} />
          </label>
          <label>
            Versienaam
            <input required value={iosForm.version_name} onChange={(event) => setIosForm((current) => ({ ...current, version_name: event.target.value }))} placeholder="1.0.0" />
          </label>
          <label>
            Buildnummer
            <input required type="number" min="1" value={iosForm.version_code} onChange={(event) => setIosForm((current) => ({ ...current, version_code: event.target.value }))} />
          </label>
          <label>
            Status
            <select value={iosForm.status} onChange={(event) => setIosForm((current) => ({ ...current, status: event.target.value }))}>
              <option value="supported">Supported</option>
              <option value="deprecated">Deprecated</option>
              <option value="not_supported">Not supported</option>
              <option value="blocked">Blocked</option>
            </select>
          </label>
          <label className="form-grid__wide">
            App Store/TestFlight link
            <input type="url" value={iosForm.download_url} onChange={(event) => setIosForm((current) => ({ ...current, download_url: event.target.value }))} placeholder="https://testflight.apple.com/join/..." />
          </label>
          <label className="form-grid__wide">
            Release notes
            <textarea value={iosForm.release_notes} onChange={(event) => setIosForm((current) => ({ ...current, release_notes: event.target.value }))} rows={4} />
          </label>
          <div className="actions-row form-grid__wide">
            <button className="primary-button" type="submit" disabled={saving}>
              {saving ? 'Importeren...' : 'iOS release importeren'}
            </button>
          </div>
        </form>
      </Panel>
      <Panel title="Android updates">
        <ResourceState loading={androidVersions.loading} error={androidVersions.error} empty={(androidVersions.data?.length ?? 0) === 0}>
          <VersionsTable versions={androidVersions.data ?? []} artifactLabel="APK" />
        </ResourceState>
      </Panel>
      <Panel title="iOS updates">
        <ResourceState loading={iosVersions.loading} error={iosVersions.error} empty={(iosVersions.data?.length ?? 0) === 0}>
          <VersionsTable versions={iosVersions.data ?? []} artifactLabel="Link" />
        </ResourceState>
      </Panel>
    </div>
  );
}

function VersionsTable({ versions, artifactLabel }: { versions: AppVersion[]; artifactLabel: string }) {
  return (
    <table className="data-table">
      <thead><tr><th>App</th><th>Versie</th><th>Code</th><th>Status</th><th>SHA-256</th><th>{artifactLabel}</th></tr></thead>
      <tbody>
        {versions.map((version) => (
          <tr key={version.id}>
            <td>{version.application_id}</td>
            <td>{version.version_name}</td>
            <td>{version.version_code}</td>
            <td><StatusPill value={version.status === 'not_supported' || version.status === 'blocked' ? 'Not supported' : version.status} tone={version.status === 'not_supported' || version.status === 'blocked' ? 'bad' : version.status === 'deprecated' ? 'warn' : 'good'} /></td>
            <td className="mono">{version.artifact_sha256 ?? '-'}</td>
            <td>{version.download_url ? <a href={version.download_url}>Open</a> : '-'}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
