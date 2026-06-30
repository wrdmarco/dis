import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
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
  report_recipients: BackupReportRecipient[];
  confirmation_text: string;
  backups: BackupSummary[];
}

type BackupTarget = 'local' | 'samba';

interface BackupSettings {
  target: BackupTarget;
  local_path: string;
  samba_server: string;
  samba_share_name: string;
  samba_share: string;
  samba_mount: string;
  samba_username: string;
  samba_password_configured: boolean;
  samba_domain: string;
  samba_version: string;
  samba_mounted: boolean;
  auto_enabled: boolean;
  auto_frequency: BackupFrequency;
  auto_day_of_week: number;
  auto_time: string;
  retention_count: number;
  auto_last_run_at?: string | null;
}

interface BackupSettingsForm {
  target: BackupTarget;
  localPath: string;
  sambaServer: string;
  sambaShareName: string;
  sambaShare: string;
  sambaMount: string;
  sambaUsername: string;
  sambaPassword: string;
  sambaDomain: string;
  sambaVersion: string;
  autoEnabled: boolean;
  autoFrequency: BackupFrequency;
  autoDayOfWeek: number;
  autoTime: string;
  retentionCount: number;
  backupReportSuccessUserIds: string[];
  backupReportFailedUserIds: string[];
}

interface BackupReportRecipient {
  id: string;
  name: string;
  email: string;
  success: boolean;
  failed: boolean;
}

interface BackupActionResult {
  state: string;
  output?: string;
}

interface SambaShareOption {
  name: string;
  path: string;
  comment?: string | null;
}

interface SambaSharesResponse {
  shares: SambaShareOption[];
}

const SMB_VERSION_OPTIONS = ['3.1.1', '3.0', '2.1', '2.0', '1.0'] as const;
type BackupFrequency = 'daily' | 'weekly';

const WEEK_DAYS = [
  { value: 1, label: 'Maandag' },
  { value: 2, label: 'Dinsdag' },
  { value: 3, label: 'Woensdag' },
  { value: 4, label: 'Donderdag' },
  { value: 5, label: 'Vrijdag' },
  { value: 6, label: 'Zaterdag' },
  { value: 7, label: 'Zondag' },
] as const;

export function BackupPage() {
  const { api } = useAuth();
  const backups = useApiResource<BackupIndex>('/admin/backups');
  const initialSettings = useMemo(() => toSettingsForm(backups.data?.settings, backups.data?.report_recipients), [backups.data]);
  const [settingsForm, setSettingsForm] = useState<BackupSettingsForm>(initialSettings);
  const [createTarget, setCreateTarget] = useState<BackupTarget>(initialSettings.target);
  const [busy, setBusy] = useState<string | null>(null);
  const [restoreBackup, setRestoreBackup] = useState<BackupSummary | null>(null);
  const [confirmation, setConfirmation] = useState('');
  const [uploadConfirmation, setUploadConfirmation] = useState('');
  const [uploadFile, setUploadFile] = useState<File | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [output, setOutput] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sambaShares, setSambaShares] = useState<SambaShareOption[]>([]);
  const [sharesLoading, setSharesLoading] = useState(false);
  const [sharesError, setSharesError] = useState<string | null>(null);
  const [successRecipientSearch, setSuccessRecipientSearch] = useState('');
  const [failedRecipientSearch, setFailedRecipientSearch] = useState('');

  useEffect(() => {
    setSettingsForm(initialSettings);
    setCreateTarget(initialSettings.target);
    setSambaShares(initialSettings.sambaShareName ? [{ name: initialSettings.sambaShareName, path: initialSettings.sambaShare || `//${initialSettings.sambaServer}/${initialSettings.sambaShareName}` }] : []);
  }, [initialSettings]);

  async function saveSettings(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await runAction('settings', async () => {
      await api.patch<BackupIndex>('/admin/backups/settings', {
        target: settingsForm.target,
        samba_server: settingsForm.sambaServer,
        samba_share_name: settingsForm.sambaShareName,
        samba_mount: settingsForm.sambaMount,
        samba_username: settingsForm.sambaUsername,
        samba_password: settingsForm.sambaPassword,
        samba_domain: settingsForm.sambaDomain,
        samba_version: settingsForm.sambaVersion,
        auto_enabled: settingsForm.autoEnabled,
        auto_frequency: settingsForm.autoFrequency,
        auto_day_of_week: settingsForm.autoDayOfWeek,
        auto_time: settingsForm.autoTime,
        retention_count: settingsForm.retentionCount,
        backup_report_success_user_ids: settingsForm.backupReportSuccessUserIds,
        backup_report_failed_user_ids: settingsForm.backupReportFailedUserIds,
      });

      return { state: 'saved' };
    });
  }

  async function fetchSambaShares() {
    setSharesLoading(true);
    setSharesError(null);
    setError(null);

    try {
      const response = await api.post<SambaSharesResponse>('/admin/backups/samba-shares', {
        samba_server: settingsForm.sambaServer,
        samba_username: settingsForm.sambaUsername,
        samba_password: settingsForm.sambaPassword,
        samba_domain: settingsForm.sambaDomain,
        samba_version: settingsForm.sambaVersion,
      });
      setSambaShares(response.data.shares);
      if (response.data.shares.length > 0 && !response.data.shares.some((share) => share.name === settingsForm.sambaShareName)) {
        const firstShare = response.data.shares[0];
        setSettingsForm((current) => ({ ...current, sambaShareName: firstShare.name, sambaShare: firstShare.path }));
      }
      setMessage(response.data.shares.length > 0 ? 'Samba paden zijn opgehaald.' : 'Geen Samba paden gevonden.');
    } catch (err) {
      setSharesError(err instanceof ApiClientError ? err.message : 'Samba paden ophalen mislukt.');
    } finally {
      setSharesLoading(false);
    }
  }

  async function runAction(label: string, action: () => Promise<BackupActionResult>) {
    setBusy(label);
    setMessage(null);
    setOutput(null);
    setError(null);

    try {
      const result = await action();
      setMessage(label === 'create' ? 'Backup is gemaakt.' : label === 'verify' ? 'Backup is geverifieerd.' : label === 'settings' ? 'Backupinstellingen zijn opgeslagen.' : label === 'uploadRestore' ? 'Upload backup is teruggezet.' : 'Backup is teruggezet.');
      setOutput(result.output ?? null);
      await backups.reload();
      if (label === 'restore') {
        setRestoreBackup(null);
        setConfirmation('');
      }
      if (label === 'uploadRestore') {
        setUploadConfirmation('');
        setUploadFile(null);
      }
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Actie mislukt.');
    } finally {
      setBusy(null);
    }
  }

  async function uploadRestore(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (uploadFile === null) {
      setError('Selecteer eerst een ZIP-bestand.');
      return;
    }

    const form = new FormData();
    form.append('confirmation', uploadConfirmation);
    form.append('backup', uploadFile);

    await runAction('uploadRestore', async () => (await api.postForm<BackupActionResult>('/admin/backups/upload-restore', form)).data);
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
              <pre>{`Actief doel: ${targetLabel(settingsForm.target)}\nLokale map: ${backups.data?.roots.local ?? settingsForm.localPath}\nAutomatisch: ${settingsForm.autoEnabled ? 'Aan' : 'Uit'}\nAantal bewaren: ${settingsForm.retentionCount === 0 ? 'Onbeperkt' : settingsForm.retentionCount}`}</pre>
            </div>
            {settingsForm.target === 'samba' ? (
              <>
                <div className="form-grid__wide section-heading">
                  <h3>Samba instellingen</h3>
                </div>
                <label>
                  Servernaam/IP
                  <input
                    placeholder="192.168.1.10"
                    value={settingsForm.sambaServer}
                    onChange={(event) => setSettingsForm((current) => ({ ...current, sambaServer: event.target.value, sambaShareName: '', sambaShare: '' }))}
                  />
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
                <div className="actions-row form-grid__wide">
                  <button className="secondary-button" type="button" onClick={() => void fetchSambaShares()} disabled={sharesLoading || busy !== null || settingsForm.sambaServer.trim() === '' || settingsForm.sambaUsername.trim() === ''}>
                    {sharesLoading ? 'Paden ophalen...' : 'Paden ophalen'}
                  </button>
                </div>
                {sharesError ? <p className="form-error form-grid__wide">{sharesError}</p> : null}
                <label className="form-grid__wide">
                  Pad/share
                  <select
                    value={settingsForm.sambaShareName}
                    onChange={(event) => {
                      const selected = sambaShares.find((share) => share.name === event.target.value);
                      setSettingsForm((current) => ({ ...current, sambaShareName: event.target.value, sambaShare: selected?.path ?? (current.sambaServer ? `//${current.sambaServer}/${event.target.value}` : '') }));
                    }}
                  >
                    <option value="">Selecteer een pad</option>
                    {sambaShares.map((share) => (
                      <option key={share.name} value={share.name}>{share.path}{share.comment ? ` - ${share.comment}` : ''}</option>
                    ))}
                  </select>
                </label>
                <label>
                  Mountpoint
                  <input value={settingsForm.sambaMount} readOnly />
                </label>
                <div className="form-grid__wide metadata-example">
                  <strong>Samba status</strong>
                  <pre>{`Server/IP: ${settingsForm.sambaServer || '-'}\nPad/share: ${settingsForm.sambaShare || '-'}\nSamba mount: ${backups.data?.settings.samba_mounted ? 'Gekoppeld' : 'Niet gekoppeld'}\nWachtwoord: ${backups.data?.settings.samba_password_configured ? 'Ingesteld' : 'Niet ingesteld'}`}</pre>
                </div>
              </>
            ) : null}
            <div className="form-grid__wide section-heading">
              <h3>Automatische backups</h3>
            </div>
            <label className="check-label form-grid__wide">
              <input type="checkbox" checked={settingsForm.autoEnabled} onChange={(event) => setSettingsForm((current) => ({ ...current, autoEnabled: event.target.checked }))} />
              Automatisch backups maken
            </label>
            <label>
              Frequentie
              <select value={settingsForm.autoFrequency} onChange={(event) => setSettingsForm((current) => ({ ...current, autoFrequency: event.target.value as BackupFrequency }))}>
                <option value="daily">Dagelijks</option>
                <option value="weekly">Wekelijks</option>
              </select>
            </label>
            {settingsForm.autoFrequency === 'weekly' ? (
              <label>
                Dag
                <select value={settingsForm.autoDayOfWeek} onChange={(event) => setSettingsForm((current) => ({ ...current, autoDayOfWeek: Number(event.target.value) }))}>
                  {WEEK_DAYS.map((day) => (
                    <option key={day.value} value={day.value}>{day.label}</option>
                  ))}
                </select>
              </label>
            ) : null}
            <label>
              Tijd
              <input type="time" value={settingsForm.autoTime} onChange={(event) => setSettingsForm((current) => ({ ...current, autoTime: event.target.value }))} />
            </label>
            <label>
              Aantal bewaren
              <input type="number" min="0" max="365" value={settingsForm.retentionCount} onChange={(event) => setSettingsForm((current) => ({ ...current, retentionCount: Number(event.target.value) }))} />
            </label>
            <p className="form-note form-grid__wide">Gebruik 0 om backups onbeperkt te bewaren.</p>
            <div className="form-grid__wide section-heading">
              <h3>Rapport ontvangers</h3>
            </div>
            <RecipientSelector
              label="Succesrapport naar"
              placeholder="Typ naam of e-mail"
              datalistId="backup-success-recipients"
              recipients={backups.data?.report_recipients ?? []}
              selectedIds={settingsForm.backupReportSuccessUserIds}
              search={successRecipientSearch}
              onSearch={setSuccessRecipientSearch}
              onAdd={(userId) => setSettingsForm((current) => ({ ...current, backupReportSuccessUserIds: addId(current.backupReportSuccessUserIds, userId) }))}
              onRemove={(userId) => setSettingsForm((current) => ({ ...current, backupReportSuccessUserIds: current.backupReportSuccessUserIds.filter((id) => id !== userId) }))}
            />
            <RecipientSelector
              label="Foutrapport naar"
              placeholder="Typ naam of e-mail"
              datalistId="backup-failed-recipients"
              recipients={backups.data?.report_recipients ?? []}
              selectedIds={settingsForm.backupReportFailedUserIds}
              search={failedRecipientSearch}
              onSearch={setFailedRecipientSearch}
              onAdd={(userId) => setSettingsForm((current) => ({ ...current, backupReportFailedUserIds: addId(current.backupReportFailedUserIds, userId) }))}
              onRemove={(userId) => setSettingsForm((current) => ({ ...current, backupReportFailedUserIds: current.backupReportFailedUserIds.filter((id) => id !== userId) }))}
            />
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
            <dt>Automatische backup</dt><dd>{backups.data?.settings.auto_enabled ? autoBackupLabel(backups.data.settings) : 'Uit'}</dd>
            <dt>Aantal bewaren</dt><dd>{backups.data?.settings.retention_count === 0 ? 'Onbeperkt' : backups.data?.settings.retention_count ?? '-'}</dd>
            <dt>Laatst automatisch</dt><dd>{backups.data?.settings.auto_last_run_at ?? '-'}</dd>
            <dt>Aantal backups</dt><dd>{backups.data?.backups.length ?? 0}</dd>
          </dl>
          {message ? <p className="form-note">{message}</p> : null}
          {error ? <p className="form-error">{error}</p> : null}
          <form className="form-grid restore-upload" onSubmit={uploadRestore}>
            <div className="form-grid__wide section-heading">
              <h3>Backup uploaden en terugzetten</h3>
              <p>Upload een ZIP van een DIS backupmap. De backup wordt eerst geverifieerd en daarna pas teruggezet.</p>
            </div>
            <label>
              Backup ZIP
              <input
                type="file"
                accept=".zip,application/zip"
                onChange={(event) => setUploadFile(event.target.files?.[0] ?? null)}
              />
            </label>
            <label>
              Typ {backups.data?.confirmation_text}
              <input value={uploadConfirmation} onChange={(event) => setUploadConfirmation(event.target.value)} />
            </label>
            <div className="actions-row form-grid__wide">
              <button
                className="danger-button"
                type="submit"
                disabled={busy !== null || uploadFile === null || uploadConfirmation !== backups.data?.confirmation_text}
              >
                {busy === 'uploadRestore' ? 'Upload restore draait...' : 'Upload restore uitvoeren'}
              </button>
            </div>
          </form>
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
                      <span className="muted-text">{formatDateTime(backup.created_at)}</span>
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

interface RecipientSelectorProps {
  label: string;
  placeholder: string;
  datalistId: string;
  recipients: BackupReportRecipient[];
  selectedIds: string[];
  search: string;
  onSearch: (value: string) => void;
  onAdd: (userId: string) => void;
  onRemove: (userId: string) => void;
}

function RecipientSelector({ label, placeholder, datalistId, recipients, selectedIds, search, onSearch, onAdd, onRemove }: RecipientSelectorProps) {
  const selectedRecipients = selectedIds
    .map((id) => recipients.find((recipient) => recipient.id === id))
    .filter((recipient): recipient is BackupReportRecipient => recipient !== undefined);
  const availableRecipients = recipients.filter((recipient) => !selectedIds.includes(recipient.id));

  function addFromSearch() {
    const recipient = findRecipientBySearch(availableRecipients, search);
    if (recipient === null) {
      return;
    }

    onAdd(recipient.id);
    onSearch('');
  }

  return (
    <div className="form-grid__wide recipient-picker">
      <label>
        {label}
        <div className="recipient-picker__input-row">
          <input
            list={datalistId}
            value={search}
            placeholder={placeholder}
            onChange={(event) => onSearch(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault();
                addFromSearch();
              }
            }}
          />
          <button className="secondary-button" type="button" onClick={addFromSearch} disabled={findRecipientBySearch(availableRecipients, search) === null}>
            Toevoegen
          </button>
        </div>
      </label>
      <datalist id={datalistId}>
        {availableRecipients.map((recipient) => (
          <option key={recipient.id} value={recipientLabel(recipient)} />
        ))}
      </datalist>
      <div className="recipient-picker__chips">
        {selectedRecipients.length === 0 ? (
          <span className="muted-text">Geen ontvangers geselecteerd.</span>
        ) : selectedRecipients.map((recipient) => (
          <button key={recipient.id} className="recipient-chip" type="button" onClick={() => onRemove(recipient.id)} title="Ontvanger verwijderen">
            <span>{recipient.name}</span>
            <small>{recipient.email}</small>
            <strong aria-hidden="true">x</strong>
          </button>
        ))}
      </div>
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

function autoBackupLabel(settings: BackupSettings): string {
  if (settings.auto_frequency === 'weekly') {
    const day = WEEK_DAYS.find((option) => option.value === settings.auto_day_of_week)?.label ?? 'Maandag';
    return `Wekelijks op ${day} om ${settings.auto_time}`;
  }

  return `Dagelijks om ${settings.auto_time}`;
}

function toSettingsForm(settings?: BackupSettings, recipients: BackupReportRecipient[] = []): BackupSettingsForm {
  return {
    target: settings?.target ?? 'local',
    localPath: settings?.local_path ?? '/opt/dis-data/backup',
    sambaServer: settings?.samba_server ?? '',
    sambaShareName: settings?.samba_share_name ?? '',
    sambaShare: settings?.samba_share ?? '',
    sambaMount: settings?.samba_mount ?? '/mnt/dis-backup',
    sambaUsername: settings?.samba_username ?? '',
    sambaPassword: '',
    sambaDomain: settings?.samba_domain ?? '',
    sambaVersion: settings?.samba_version ?? '3.1.1',
    autoEnabled: settings?.auto_enabled ?? false,
    autoFrequency: settings?.auto_frequency ?? 'daily',
    autoDayOfWeek: settings?.auto_day_of_week ?? 1,
    autoTime: settings?.auto_time ?? '02:15',
    retentionCount: settings?.retention_count ?? 7,
    backupReportSuccessUserIds: recipients.filter((recipient) => recipient.success).map((recipient) => recipient.id),
    backupReportFailedUserIds: recipients.filter((recipient) => recipient.failed).map((recipient) => recipient.id),
  };
}

function addId(values: string[], id: string): string[] {
  return values.includes(id) ? values : [...values, id];
}

function recipientLabel(recipient: BackupReportRecipient): string {
  return `${recipient.name} <${recipient.email}>`;
}

function findRecipientBySearch(recipients: BackupReportRecipient[], search: string): BackupReportRecipient | null {
  const normalized = search.trim().toLowerCase();
  if (normalized === '') {
    return null;
  }

  return recipients.find((recipient) => {
    const label = recipientLabel(recipient).toLowerCase();
    return label === normalized || recipient.email.toLowerCase() === normalized || recipient.name.toLowerCase() === normalized;
  }) ?? null;
}
