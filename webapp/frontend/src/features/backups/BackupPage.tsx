import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';

interface BackupSummary {
  id: string;
  target: BackupTarget;
  created_at: string;
  database?: string | null;
  host?: string | null;
  version?: string | null;
  git_commit?: string | null;
  includes: string[];
  size_bytes: number;
  has_manifest: boolean;
  has_checksums: boolean;
}

interface BackupIndex {
  root: string;
  roots: Record<BackupTarget, string>;
  settings: BackupSettings;
  confirmation_text: string;
  backups: BackupSummary[];
}

type BackupTarget = 'local' | 'samba';

interface BackupSettings {
  target: BackupTarget;
  local_path: string;
  samba_share: string;
  samba_mount: string;
  samba_username: string;
  samba_password_configured: boolean;
  samba_domain: string;
  samba_version: string;
  samba_mounted: boolean;
}

interface BackupSettingsForm {
  target: BackupTarget;
  localPath: string;
  sambaShare: string;
  sambaMount: string;
  sambaUsername: string;
  sambaPassword: string;
  sambaDomain: string;
  sambaVersion: string;
}

interface BackupActionResult {
  state: string;
  output?: string;
}

const SMB_VERSION_OPTIONS = ['3.1.1', '3.0', '2.1', '2.0', '1.0'] as const;

export function BackupPage() {
  const { api } = useAuth();
  const backups = useApiResource<BackupIndex>('/admin/backups');
  const initialSettings = useMemo(() => toSettingsForm(backups.data?.settings), [backups.data]);
  const [settingsForm, setSettingsForm] = useState<BackupSettingsForm>(initialSettings);
  const [createTarget, setCreateTarget] = useState<BackupTarget>(initialSettings.target);
  const [busy, setBusy] = useState<string | null>(null);
  const [restoreBackup, setRestoreBackup] = useState<BackupSummary | null>(null);
  const [confirmation, setConfirmation] = useState('');
  const [message, setMessage] = useState<string | null>(null);
  const [output, setOutput] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setSettingsForm(initialSettings);
    setCreateTarget(initialSettings.target);
  }, [initialSettings]);

  async function saveSettings(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await runAction('settings', async () => {
      await api.patch<BackupIndex>('/admin/backups/settings', {
        target: settingsForm.target,
        samba_share: settingsForm.sambaShare,
        samba_mount: settingsForm.sambaMount,
        samba_username: settingsForm.sambaUsername,
        samba_password: settingsForm.sambaPassword,
        samba_domain: settingsForm.sambaDomain,
        samba_version: settingsForm.sambaVersion,
      });

      return { state: 'saved' };
    });
  }

  async function runAction(label: string, action: () => Promise<BackupActionResult>) {
    setBusy(label);
    setMessage(null);
    setOutput(null);
    setError(null);

    try {
      const result = await action();
      setMessage(label === 'create' ? 'Backup is gemaakt.' : label === 'verify' ? 'Backup is geverifieerd.' : label === 'settings' ? 'Backupinstellingen zijn opgeslagen.' : 'Backup is teruggezet.');
      setOutput(result.output ?? null);
      await backups.reload();
      if (label === 'restore') {
        setRestoreBackup(null);
        setConfirmation('');
      }
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Actie mislukt.');
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="page-stack">
      <Panel title="Backup doel">
        <ResourceState loading={backups.loading} error={backups.error} empty={!backups.data}>
          <form className="form-grid" onSubmit={saveSettings}>
            <label>
              Doel
              <select value={settingsForm.target} onChange={(event) => setSettingsForm((current) => ({ ...current, target: event.target.value as BackupSettingsForm['target'] }))}>
                <option value="local">Lokaal</option>
                <option value="samba">Samba share</option>
              </select>
            </label>
            <div className="field-display">
              <span>Lokale map</span>
              <strong className="mono">{settingsForm.localPath}</strong>
            </div>
            <div className="form-grid__wide metadata-example">
              <strong>Status</strong>
              <pre>{`Actief doel: ${targetLabel(settingsForm.target)}\nLokale map: ${backups.data?.roots.local ?? settingsForm.localPath}`}</pre>
            </div>
            {settingsForm.target === 'samba' ? (
              <>
                <div className="form-grid__wide section-heading">
                  <h3>Samba instellingen</h3>
                </div>
                <label className="form-grid__wide">
                  Samba share
                  <input placeholder="//server/share" value={settingsForm.sambaShare} onChange={(event) => setSettingsForm((current) => ({ ...current, sambaShare: event.target.value }))} />
                </label>
                <label>
                  Mountpoint
                  <input value={settingsForm.sambaMount} readOnly />
                </label>
                <label>
                  SMB versie
                  <select value={settingsForm.sambaVersion} onChange={(event) => setSettingsForm((current) => ({ ...current, sambaVersion: event.target.value }))}>
                    {SMB_VERSION_OPTIONS.map((version) => (
                      <option key={version} value={version}>{version}</option>
                    ))}
                  </select>
                </label>
                <label>
                  Gebruikersnaam
                  <input value={settingsForm.sambaUsername} onChange={(event) => setSettingsForm((current) => ({ ...current, sambaUsername: event.target.value }))} />
                </label>
                <label>
                  Domein
                  <input value={settingsForm.sambaDomain} onChange={(event) => setSettingsForm((current) => ({ ...current, sambaDomain: event.target.value }))} />
                </label>
                <label>
                  Wachtwoord
                  <input type="password" placeholder={backups.data?.settings.samba_password_configured ? 'Ingesteld' : ''} value={settingsForm.sambaPassword} onChange={(event) => setSettingsForm((current) => ({ ...current, sambaPassword: event.target.value }))} />
                </label>
                <div className="form-grid__wide metadata-example">
                  <strong>Samba status</strong>
                  <pre>{`Samba map: ${backups.data?.roots.samba ?? settingsForm.sambaMount}\nSamba mount: ${backups.data?.settings.samba_mounted ? 'Gekoppeld' : 'Niet gekoppeld'}\nWachtwoord: ${backups.data?.settings.samba_password_configured ? 'Ingesteld' : 'Niet ingesteld'}`}</pre>
                </div>
              </>
            ) : null}
            <div className="actions-row form-grid__wide">
              <button className="primary-button" type="submit" disabled={busy !== null}>
                {busy === 'settings' ? 'Opslaan...' : 'Backup doel opslaan'}
              </button>
            </div>
          </form>
        </ResourceState>
      </Panel>

      <Panel
        title="Backup beheer"
        action={(
          <div className="actions-row">
            <select value={createTarget} onChange={(event) => setCreateTarget(event.target.value as BackupTarget)} disabled={busy !== null}>
              <option value="local">Lokaal</option>
              <option value="samba">Samba share</option>
            </select>
            <button className="primary-button" type="button" onClick={() => void runAction('create', async () => (await api.post<BackupActionResult>('/admin/backups', { target: createTarget })).data)} disabled={busy !== null}>
              {busy === 'create' ? 'Backup draait...' : 'Backup maken'}
            </button>
          </div>
        )}
      >
        <ResourceState loading={backups.loading} error={backups.error} empty={!backups.data}>
          <dl className="definition-grid">
            <dt>Standaardlocatie</dt><dd className="mono">{backups.data?.root ?? '-'}</dd>
            <dt>Lokale map</dt><dd className="mono">{backups.data?.roots.local ?? '-'}</dd>
            {backups.data?.settings.target === 'samba' ? (
              <>
                <dt>Samba map</dt><dd className="mono">{backups.data.roots.samba ?? '-'}</dd>
              </>
            ) : null}
            <dt>Aantal backups</dt><dd>{backups.data?.backups.length ?? 0}</dd>
          </dl>
          {message ? <p className="form-note">{message}</p> : null}
          {error ? <p className="form-error">{error}</p> : null}
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Backup</th>
                  <th>Doel</th>
                  <th>Database</th>
                  <th>Host</th>
                  <th>Versie</th>
                  <th>Grootte</th>
                  <th>Status</th>
                  <th>Actie</th>
                </tr>
              </thead>
              <tbody>
                {(backups.data?.backups ?? []).map((backup) => (
                  <tr key={backup.id}>
                    <td>
                      <strong>{backup.id}</strong>
                      <span className="muted-text">{backup.created_at}</span>
                    </td>
                    <td>{targetLabel(backup.target)}</td>
                    <td>{backup.database ?? '-'}</td>
                    <td>{backup.host ?? '-'}</td>
                    <td>{backup.version ?? '-'}</td>
                    <td>{formatBytes(backup.size_bytes)}</td>
                    <td>{backup.has_manifest && backup.has_checksums ? 'Compleet' : 'Onvolledig'}</td>
                    <td>
                      <div className="actions-row">
                        <button className="secondary-button" type="button" onClick={() => void runAction('verify', async () => (await api.post<BackupActionResult>(`/admin/backups/${backup.id}/verify`, { target: backup.target })).data)} disabled={busy !== null}>
                          Verifieren
                        </button>
                        <button className="danger-button" type="button" onClick={() => setRestoreBackup(backup)} disabled={busy !== null || !backup.has_checksums}>
                          Restore
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {output ? (
            <div className="metadata-example">
              <strong>Uitvoer</strong>
              <pre>{output}</pre>
            </div>
          ) : null}
        </ResourceState>
      </Panel>

      {restoreBackup !== null ? (
        <div className="modal-backdrop" role="presentation">
          <div className="modal" role="dialog" aria-modal="true" aria-labelledby="restore-title">
            <header className="modal__header">
              <div>
                <span className="modal__eyebrow">Restore</span>
                <h2 id="restore-title">Backup terugzetten</h2>
              </div>
              <button className="icon-button" type="button" onClick={() => setRestoreBackup(null)}>x</button>
            </header>
            <div className="form-grid">
              <p className="form-error form-grid__wide">
                Dit zet database en storage terug naar backup {restoreBackup.id}. Nieuwe gegevens na deze backup kunnen verdwijnen.
              </p>
              <dl className="definition-grid form-grid__wide">
                <dt>Doel</dt><dd>{targetLabel(restoreBackup.target)}</dd>
              </dl>
              <label className="form-grid__wide">
                Typ {backups.data?.confirmation_text}
                <input value={confirmation} onChange={(event) => setConfirmation(event.target.value)} />
              </label>
              <div className="actions-row form-grid__wide">
                <button className="secondary-button" type="button" onClick={() => setRestoreBackup(null)}>Annuleren</button>
                <button
                  className="danger-button"
                  type="button"
                  disabled={busy !== null || confirmation !== backups.data?.confirmation_text}
                  onClick={() => void runAction('restore', async () => (await api.post<BackupActionResult>(`/admin/backups/${restoreBackup.id}/restore`, { confirmation, target: restoreBackup.target })).data)}
                >
                  {busy === 'restore' ? 'Restore draait...' : 'Restore uitvoeren'}
                </button>
              </div>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}

function formatBytes(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes <= 0) {
    return '-';
  }

  const units = ['B', 'KB', 'MB', 'GB'];
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }

  return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

function targetLabel(target: BackupTarget): string {
  return target === 'samba' ? 'Samba share' : 'Lokaal';
}

function toSettingsForm(settings?: BackupSettings): BackupSettingsForm {
  return {
    target: settings?.target ?? 'local',
    localPath: settings?.local_path ?? '/opt/dis/backup',
    sambaShare: settings?.samba_share ?? '',
    sambaMount: settings?.samba_mount ?? '/mnt/dis-backup',
    sambaUsername: settings?.samba_username ?? '',
    sambaPassword: '',
    sambaDomain: settings?.samba_domain ?? '',
    sambaVersion: settings?.samba_version ?? '3.1.1',
  };
}
