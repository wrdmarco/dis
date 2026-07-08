import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { AppVersion, SoftwareDownloadSource, SystemSetting } from '../../types/api';

interface DownloadChannelAdminConfig {
  key: 'operator_android' | 'admin_android' | 'operator_ios';
  title: string;
  platformLabel: string;
  storeLabel: string;
}

interface DownloadSourceForm {
  source: SoftwareDownloadSource;
  appStoreUrl: string;
}

type DownloadSourceForms = Record<DownloadChannelAdminConfig['key'], DownloadSourceForm>;

const downloadChannelAdminConfigs: DownloadChannelAdminConfig[] = [
  { key: 'operator_android', title: 'Operator Android', platformLabel: 'APK', storeLabel: 'Google Play' },
  { key: 'admin_android', title: 'Admin Android', platformLabel: 'APK', storeLabel: 'Google Play' },
  { key: 'operator_ios', title: 'Operator iPhone', platformLabel: 'IPA/TestFlight', storeLabel: 'Apple App Store' },
];

export function UpdatesPage() {
  const { api, hasPermission } = useAuth();
  const canManageSettings = hasPermission('settings.manage');
  const androidVersions = useApiResource<AppVersion[]>('/admin/updates/android');
  const iosVersions = useApiResource<AppVersion[]>('/admin/updates/ios');
  const settings = useApiResource<SystemSetting[]>('/admin/settings', canManageSettings);
  const downloadSourceSettings = useMemo(() => toDownloadSourceForms(settings.data ?? []), [settings.data]);
  const [releaseZip, setReleaseZip] = useState<File | null>(null);
  const [downloadSourceForms, setDownloadSourceForms] = useState<DownloadSourceForms>(() => defaultDownloadSourceForms());
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
  const [downloadSourceSaving, setDownloadSourceSaving] = useState(false);
  const [downloadSourceMessage, setDownloadSourceMessage] = useState<string | null>(null);

  useEffect(() => {
    setDownloadSourceForms(downloadSourceSettings);
  }, [downloadSourceSettings]);

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

  async function saveDownloadSourceSettings() {
    if (!canManageSettings) {
      return;
    }

    setDownloadSourceSaving(true);
    setError(null);
    setDownloadSourceMessage(null);

    try {
      const payload: Record<string, unknown> = {};
      for (const config of downloadChannelAdminConfigs) {
        const value = downloadSourceForms[config.key];
        payload[`software.download.${config.key}.source`] = value.source;
        payload[`software.download.${config.key}.app_store_url`] = value.appStoreUrl.trim();
      }

      await api.patch('/admin/settings', { settings: payload });
      await settings.reload();
      setDownloadSourceMessage('Downloadbronnen zijn opgeslagen.');
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Downloadbronnen konden niet worden opgeslagen.');
    } finally {
      setDownloadSourceSaving(false);
    }
  }

  return (
    <div className="page-stack">
      {canManageSettings ? (
        <Panel title="Downloadpagina voorbereiden">
          <ResourceState loading={settings.loading} error={settings.error} empty={false}>
            <div className="download-source-admin">
              {downloadChannelAdminConfigs.map((config) => {
                const value = downloadSourceForms[config.key];

                return (
                  <section className="download-source-admin__card" key={config.key}>
                    <div>
                      <span>{config.platformLabel}</span>
                      <strong>{config.title}</strong>
                    </div>
                    <label>
                      Downloadbron
                      <select
                        value={value.source}
                        onChange={(event) => setDownloadSourceForms((current) => ({
                          ...current,
                          [config.key]: { ...current[config.key], source: event.target.value as SoftwareDownloadSource },
                        }))}
                      >
                        <option value="direct">Huidige directe download</option>
                        <option value="app_store">{config.storeLabel}</option>
                      </select>
                    </label>
                    <label>
                      {config.storeLabel} link
                      <input
                        type="url"
                        value={value.appStoreUrl}
                        onChange={(event) => setDownloadSourceForms((current) => ({
                          ...current,
                          [config.key]: { ...current[config.key], appStoreUrl: event.target.value },
                        }))}
                        placeholder={config.key.endsWith('ios') ? 'https://apps.apple.com/...' : 'https://play.google.com/store/apps/details?id=...'}
                      />
                    </label>
                    <p className="muted-text">
                      Kies appstore om de APK/IPA-download op de softwarepagina uit te zetten voor deze app.
                    </p>
                  </section>
                );
              })}
            </div>
            <div className="actions-row">
              <button className="primary-button" type="button" onClick={() => void saveDownloadSourceSettings()} disabled={downloadSourceSaving}>
                {downloadSourceSaving ? 'Opslaan...' : 'Downloadbronnen opslaan'}
              </button>
            </div>
            {downloadSourceMessage ? <p className="success-text">{downloadSourceMessage}</p> : null}
          </ResourceState>
        </Panel>
      ) : null}
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
      <thead><tr><th scope="col">App</th><th scope="col">Versie</th><th scope="col">Code</th><th scope="col">Status</th><th scope="col">SHA-256</th><th scope="col">{artifactLabel}</th></tr></thead>
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

function defaultDownloadSourceForms(): DownloadSourceForms {
  return {
    operator_android: { source: 'direct', appStoreUrl: '' },
    admin_android: { source: 'direct', appStoreUrl: '' },
    operator_ios: { source: 'direct', appStoreUrl: '' },
  };
}

function toDownloadSourceForms(settings: SystemSetting[]): DownloadSourceForms {
  const byKey = new Map(settings.map((setting) => [setting.key, setting.value]));
  const forms = defaultDownloadSourceForms();

  for (const config of downloadChannelAdminConfigs) {
    const source = byKey.get(`software.download.${config.key}.source`);
    const appStoreUrl = byKey.get(`software.download.${config.key}.app_store_url`);
    forms[config.key] = {
      source: source === 'app_store' ? 'app_store' : 'direct',
      appStoreUrl: typeof appStoreUrl === 'string' ? appStoreUrl : '',
    };
  }

  return forms;
}
