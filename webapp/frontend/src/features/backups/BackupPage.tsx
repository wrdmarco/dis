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
  encrypted: boolean;
  size_bytes: number;
  has_manifest: boolean;
  has_checksums: boolean;
}

interface BackupIndex {
  root: string;
  roots?: Partial<Record<BackupTarget, string>> | null;
  settings: BackupSettings;
  report_recipients?: BackupReportRecipient[] | null;
  confirmation_text?: string | null;
  backups?: BackupSummary[] | null;
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
  request_id?: string;
}

type BackupActionLabel = 'settings' | 'create' | 'verify' | 'restore' | 'uploadRestore' | 'prune' | 'refresh';

interface SambaShareOption {
  name: string;
  path: string;
  comment?: string | null;
}

interface SambaSharesResponse {
  shares: SambaShareOption[];
}

const SMB_VERSION_OPTIONS = ['3.1.1'] as const;
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

const DEFAULT_BACKUP_ROOTS: Record<BackupTarget, string> = {
  local: '/opt/dis-data/backup',
  samba: '/mnt/dis-backup',
};

const RESTORE_CONFIRMATION_TEXT = 'HERSTEL BACKUP';

export function BackupPage() {
  const { api } = useAuth();
  const backups = useApiResource<BackupIndex>('/admin/backups');
  const backupList = useMemo(() => Array.isArray(backups.data?.backups) ? backups.data.backups : [], [backups.data]);
  const recipients = useMemo(() => Array.isArray(backups.data?.report_recipients) ? backups.data.report_recipients : [], [backups.data]);
  const roots = useMemo<Record<BackupTarget, string>>(() => ({
    local: backups.data?.roots?.local ?? DEFAULT_BACKUP_ROOTS.local,
    samba: backups.data?.roots?.samba ?? DEFAULT_BACKUP_ROOTS.samba,
  }), [backups.data?.roots]);
  const backupCounts = useMemo<Record<BackupTarget, number>>(() => backupList.reduce<Record<BackupTarget, number>>(
    (counts, backup) => {
      counts[backup.target] += 1;
      return counts;
    },
    { local: 0, samba: 0 },
  ), [backupList]);
  const confirmationText = backups.data?.confirmation_text ?? RESTORE_CONFIRMATION_TEXT;
  const currentSettings = backups.data?.settings ?? null;
  const initialSettings = useMemo(() => toSettingsForm(currentSettings, recipients), [currentSettings, recipients]);
  const automaticTarget = currentSettings?.target ?? initialSettings.target;
  const otherTarget: BackupTarget = automaticTarget === 'local' ? 'samba' : 'local';
  const retentionLimit = currentSettings?.retention_count ?? null;
  const [settingsForm, setSettingsForm] = useState<BackupSettingsForm>(initialSettings);
  const settingsDirty = !backupSettingsFormsEqual(settingsForm, initialSettings);
  const [createTarget, setCreateTarget] = useState<BackupTarget>(initialSettings.target);
  const [busy, setBusy] = useState<BackupActionLabel | null>(null);
  const [pruningTarget, setPruningTarget] = useState<BackupTarget | null>(null);
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
  const sambaConfigured = currentSettings !== null
    && currentSettings.samba_server.trim() !== ''
    && currentSettings.samba_share_name.trim() !== ''
    && currentSettings.samba_username.trim() !== ''
    && currentSettings.samba_password_configured;

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
      const shares = Array.isArray(response.data.shares) ? response.data.shares : [];
      setSambaShares(shares);
      if (shares.length > 0 && !shares.some((share) => share.name === settingsForm.sambaShareName)) {
        const firstShare = shares[0];
        setSettingsForm((current) => ({ ...current, sambaShareName: firstShare.name, sambaShare: firstShare.path }));
      }
      setMessage(shares.length > 0 ? 'Samba paden zijn opgehaald.' : 'Geen Samba paden gevonden.');
    } catch (err) {
      setSharesError(err instanceof ApiClientError ? err.message : 'Samba paden ophalen mislukt.');
    } finally {
      setSharesLoading(false);
    }
  }

  async function runAction(label: BackupActionLabel, action: () => Promise<BackupActionResult>, target?: BackupTarget) {
    setBusy(label);
    setMessage(null);
    setOutput(null);
    setError(null);

    try {
      const result = await action();
      if ((label === 'restore' || label === 'uploadRestore') && result.state === 'queued') {
        const reference = result.request_id ? ` Referentie: ${result.request_id}.` : '';
        setMessage(`Restore is veilig ingepland. DIS gaat nu in onderhoud en trekt alle sessies in; meld na voltooiing opnieuw aan.${reference}`);
        setRestoreBackup(null);
        setConfirmation('');
        setUploadConfirmation('');
        setUploadFile(null);
        return;
      }
      setMessage(actionSuccessMessage(label, target));
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

  async function pruneBackups(target: BackupTarget) {
    if (settingsDirty || retentionLimit === null || retentionLimit === 0) {
      return;
    }

    const configured = target === 'local' || sambaConfigured;
    if (!configured) {
      return;
    }

    const inventoryAvailable = target === 'local' || currentSettings?.samba_mounted === true;
    const backupCount = backupCounts[target];
    const excess = inventoryAvailable ? Math.max(0, backupCount - retentionLimit) : null;
    if (excess === 0) {
      return;
    }

    const inventoryText = inventoryAvailable
      ? `De huidige telling voor ${targetLabel(target)} is ${backupCount} ${backupCount === 1 ? 'backup' : 'backups'} bij een bewaarlimiet van ${retentionLimit}.`
      : `De huidige telling voor ${targetLabel(target)} is onbekend omdat de Samba-share niet is gekoppeld. De server probeert de share eerst te koppelen.`;
    const confirmed = window.confirm(
      `${inventoryText} De server controleert de actuele inhoud en bepaalt daarna hoeveel oudste backups boven de bewaarlimiet worden verwijderd uit ${roots[target]}. Dit kan niet ongedaan worden gemaakt. Doorgaan?`,
    );

    if (!confirmed) {
      return;
    }

    setPruningTarget(target);
    try {
      await runAction('prune', async () => (await api.post<BackupActionResult>('/admin/backups/prune', { target })).data, target);
    } finally {
      setPruningTarget(null);
    }
  }

  async function refreshOverview() {
    if (settingsDirty && !window.confirm('Je hebt onopgeslagen backupinstellingen. Bij vernieuwen vervallen deze wijzigingen. Wil je het overzicht toch vernieuwen?')) {
      return;
    }

    setBusy('refresh');
    setMessage(null);
    setOutput(null);
    setError(null);

    try {
      const response = await api.get<BackupIndex>('/admin/backups');
      backups.mutate(response.data);
      setMessage('Backupoverzicht is vernieuwd.');
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Backupoverzicht vernieuwen mislukt.');
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="page-stack">
      <Panel title="Backupinstellingen">
        <ResourceState loading={backups.loading} error={backups.error} empty={!backups.data}>
          <form className="form-grid" onSubmit={saveSettings}>
            <label>
              Standaard voor automatische backups
              <select value={settingsForm.target} onChange={(event) => setSettingsForm((current) => ({ ...current, target: event.target.value as BackupSettingsForm['target'] }))}>
                <option value="local">Lokaal</option>
                <option value="samba">Samba share</option>
              </select>
            </label>
            <div className="field-display">
              <span>{settingsForm.target === 'local' ? 'Lokale opslagmap' : 'Samba mountpoint'}</span>
              <strong className="mono">{settingsForm.target === 'local' ? settingsForm.localPath : settingsForm.sambaMount}</strong>
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
                  <input type="password" placeholder={currentSettings?.samba_password_configured ? 'Ingesteld' : ''} value={settingsForm.sambaPassword} onChange={(event) => setSettingsForm((current) => ({ ...current, sambaPassword: event.target.value }))} />
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
                  <pre>{`Server/IP: ${settingsForm.sambaServer || '-'}\nPad/share: ${settingsForm.sambaShare || '-'}\nSamba mount: ${currentSettings?.samba_mounted ? 'Gekoppeld' : 'Niet gekoppeld'}\nWachtwoord: ${currentSettings?.samba_password_configured ? 'Ingesteld' : 'Niet ingesteld'}`}</pre>
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
              Bewaarlimiet per opslagdoel
              <input type="number" min="0" max="365" value={settingsForm.retentionCount} onChange={(event) => setSettingsForm((current) => ({ ...current, retentionCount: Number(event.target.value) }))} />
            </label>
            <p className="form-note form-grid__wide">
              De bewaarlimiet wordt afzonderlijk toegepast op lokaal en Samba. Gebruik 0 om backups onbeperkt te bewaren.
            </p>
            <div className="form-grid__wide section-heading">
              <h3>Rapport ontvangers</h3>
            </div>
            <RecipientSelector
              label="Succesrapport naar"
              placeholder="Typ naam of e-mail"
              datalistId="backup-success-recipients"
              recipients={recipients}
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
              recipients={recipients}
              selectedIds={settingsForm.backupReportFailedUserIds}
              search={failedRecipientSearch}
              onSearch={setFailedRecipientSearch}
              onAdd={(userId) => setSettingsForm((current) => ({ ...current, backupReportFailedUserIds: addId(current.backupReportFailedUserIds, userId) }))}
              onRemove={(userId) => setSettingsForm((current) => ({ ...current, backupReportFailedUserIds: current.backupReportFailedUserIds.filter((id) => id !== userId) }))}
            />
            <div className="actions-row form-grid__wide">
              <button className="primary-button" type="submit" disabled={busy !== null}>
                {busy === 'settings' ? 'Opslaan...' : 'Backupinstellingen opslaan'}
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
          <div className="backup-management-overview" aria-busy={busy === 'refresh' || busy === 'prune'}>
            <section className="backup-schedule-summary" aria-labelledby="backup-schedule-title">
              <header className="backup-schedule-summary__header">
                <div>
                  <span className="backup-schedule-summary__eyebrow">Automatische backups</span>
                  <h3 id="backup-schedule-title">{currentSettings?.auto_enabled ? autoBackupLabel(currentSettings) : 'Uitgeschakeld'}</h3>
                </div>
                <button className="secondary-button" type="button" onClick={() => void refreshOverview()} disabled={busy !== null}>
                  {busy === 'refresh' ? 'Vernieuwen...' : 'Overzicht vernieuwen'}
                </button>
              </header>
              <dl className="backup-schedule-summary__details">
                <dt>Standaard voor automatische backups</dt>
                <dd>{targetLabel(automaticTarget)}</dd>
                <dt>Laatste automatische uitvoering</dt>
                <dd>{formatDateTime(currentSettings?.auto_last_run_at)}</dd>
                <dt>Bewaarlimiet per opslagdoel</dt>
                <dd>{retentionLimit === null ? '-' : retentionLimit === 0 ? 'Onbeperkt' : `${retentionLimit} backups`}</dd>
              </dl>
              <p>
                Het laatste uitvoeringstijdstip is algemeen; het toen gebruikte opslagdoel wordt niet afzonderlijk vastgelegd.
              </p>
            </section>

            <div className="backup-target-overview">
              <BackupTargetCard
                target={automaticTarget}
                root={roots[automaticTarget]}
                backupCount={backupCounts[automaticTarget]}
                retentionLimit={retentionLimit}
                automaticDefault
                configured={automaticTarget === 'local' || sambaConfigured}
                inventoryAvailable={automaticTarget === 'local' || (sambaConfigured && currentSettings?.samba_mounted === true)}
                busy={busy !== null}
                settingsDirty={settingsDirty}
                pruning={busy === 'prune' && pruningTarget === automaticTarget}
                onPrune={() => void pruneBackups(automaticTarget)}
              />
              <BackupTargetCard
                target={otherTarget}
                root={roots[otherTarget]}
                backupCount={backupCounts[otherTarget]}
                retentionLimit={retentionLimit}
                automaticDefault={false}
                configured={otherTarget === 'local' || sambaConfigured}
                inventoryAvailable={otherTarget === 'local' || (sambaConfigured && currentSettings?.samba_mounted === true)}
                busy={busy !== null}
                settingsDirty={settingsDirty}
                pruning={busy === 'prune' && pruningTarget === otherTarget}
                onPrune={() => void pruneBackups(otherTarget)}
              />
            </div>

            <div className="backup-management-overview__feedback" aria-live="polite" aria-atomic="true">
              {busy === 'refresh' ? <p className="form-note" role="status">Backupoverzicht wordt vernieuwd.</p> : null}
              {message ? <p className="form-note" role="status">{message}</p> : null}
              {error ? <p className="form-error" role="alert">{error}</p> : null}
            </div>
          </div>
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
              Typ {confirmationText}
              <input value={uploadConfirmation} onChange={(event) => setUploadConfirmation(event.target.value)} />
            </label>
            <div className="actions-row form-grid__wide">
              <button
                className="danger-button"
                type="submit"
                disabled={busy !== null || uploadFile === null || uploadConfirmation !== confirmationText}
              >
                {busy === 'uploadRestore' ? 'Upload restore draait...' : 'Upload restore uitvoeren'}
              </button>
            </div>
          </form>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th scope="col">Backup</th>
                  <th scope="col">Doel</th>
                  <th scope="col">Database</th>
                  <th scope="col">Host</th>
                  <th scope="col">Versie</th>
                  <th scope="col">Grootte</th>
                  <th scope="col">Status</th>
                  <th scope="col">Actie</th>
                </tr>
              </thead>
              <tbody>
                {backupList.map((backup) => (
                  <tr key={`${backup.target}:${backup.id}`}>
                    <td>
                      <strong>{backup.id}</strong>
                      <span className="muted-text">{formatDateTime(backup.created_at)}</span>
                    </td>
                    <td>{targetLabel(backup.target)}</td>
                    <td>{backup.database ?? '-'}</td>
                    <td>{backup.host ?? '-'}</td>
                    <td>{backup.version ?? '-'}</td>
                    <td>{formatBytes(backup.size_bytes)}</td>
                    <td>{backup.has_manifest && backup.has_checksums ? (backup.encrypted ? 'Compleet, versleuteld' : 'Compleet, oud formaat') : 'Onvolledig'}</td>
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
              <button className="icon-button" type="button" onClick={() => setRestoreBackup(null)} aria-label="Sluiten">x</button>
            </header>
            <div className="form-grid">
              <p className="form-error form-grid__wide">
                Dit zet database en storage terug naar backup {restoreBackup.id}. Nieuwe gegevens na deze backup kunnen verdwijnen.
              </p>
              <dl className="definition-grid form-grid__wide">
                <dt>Doel</dt><dd>{targetLabel(restoreBackup.target)}</dd>
              </dl>
              <label className="form-grid__wide">
                Typ {confirmationText}
                <input value={confirmation} onChange={(event) => setConfirmation(event.target.value)} />
              </label>
              <div className="actions-row form-grid__wide">
                <button className="secondary-button" type="button" onClick={() => setRestoreBackup(null)}>Annuleren</button>
                <button
                  className="danger-button"
                  type="button"
                  disabled={busy !== null || confirmation !== confirmationText}
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

interface BackupTargetCardProps {
  target: BackupTarget;
  root: string;
  backupCount: number;
  retentionLimit: number | null;
  automaticDefault: boolean;
  configured: boolean;
  inventoryAvailable: boolean;
  busy: boolean;
  settingsDirty: boolean;
  pruning: boolean;
  onPrune: () => void;
}

function BackupTargetCard({
  target,
  root,
  backupCount,
  retentionLimit,
  automaticDefault,
  configured,
  inventoryAvailable,
  busy,
  settingsDirty,
  pruning,
  onPrune,
}: BackupTargetCardProps) {
  const retentionExcess = inventoryAvailable && retentionLimit !== null && retentionLimit > 0
    ? Math.max(0, backupCount - retentionLimit)
    : null;
  const canPrune = configured
    && retentionLimit !== null
    && retentionLimit > 0
    && (!inventoryAvailable || (retentionExcess !== null && retentionExcess > 0));
  const displayedCount = inventoryAvailable ? String(backupCount) : '-';
  const displayedCountLabel = inventoryAvailable ? (backupCount === 1 ? 'backup' : 'backups') : 'telling onbekend';
  const actionText = inventoryAvailable
    ? `Volgens het huidige overzicht ${retentionExcess === 1 ? 'staat' : 'staan'} ${retentionExcess ?? 0} ${retentionExcess === 1 ? 'backup' : 'backups'} boven de bewaarlimiet.`
    : 'De Samba-share is niet gekoppeld. De telling en retentiestatus zijn onbekend. De server probeert de share eerst te koppelen en past daarna de bewaarlimiet toe.';

  return (
    <article className={`backup-target-card ${automaticDefault ? 'backup-target-card--automatic' : 'backup-target-card--secondary'}`}>
      <header className="backup-target-card__header">
        <div>
          <span className={`status-pill ${automaticDefault ? 'status-pill--info' : 'status-pill--neutral'}`}>
            {automaticDefault ? 'Standaard voor automatische backups' : 'Ander opslagdoel'}
          </span>
          <h3>{targetLabel(target)}</h3>
        </div>
        <div
          className="backup-target-card__count"
          aria-label={inventoryAvailable ? `${backupCount} ${backupCount === 1 ? 'backup' : 'backups'}` : 'Aantal backups onbekend'}
        >
          <strong>{displayedCount}</strong>
          <span>{displayedCountLabel}</span>
        </div>
      </header>
      <dl className="backup-target-card__details">
        <dt>Opslaglocatie</dt>
        <dd className="mono">{root}</dd>
        <dt>Beschikbaarheid</dt>
        <dd>
          {!configured ? (
            <span className="status-pill status-pill--neutral">Niet ingesteld</span>
          ) : inventoryAvailable ? (
            <span className="status-pill status-pill--good">Beschikbaar</span>
          ) : (
            <span className="status-pill status-pill--warn">Samba niet gekoppeld</span>
          )}
        </dd>
        <dt>Bewaarlimiet</dt>
        <dd>{retentionLimit === null ? '-' : retentionLimit === 0 ? 'Onbeperkt' : `${retentionLimit} backups`}</dd>
        <dt>Retentiestatus</dt>
        <dd>
          {!configured ? (
            <span className="status-pill status-pill--neutral">Niet beschikbaar</span>
          ) : !inventoryAvailable ? (
            <span className="status-pill status-pill--warn">Onbekend</span>
          ) : retentionLimit === null ? (
            <span className="status-pill status-pill--neutral">Nog niet geladen</span>
          ) : retentionLimit === 0 ? (
            <span className="status-pill status-pill--info">Onbeperkt bewaren</span>
          ) : retentionExcess !== null && retentionExcess > 0 ? (
            <span className="status-pill status-pill--warn">
              Volgens overzicht: {retentionExcess} boven limiet
            </span>
          ) : (
            <span className="status-pill status-pill--good">Binnen limiet</span>
          )}
        </dd>
      </dl>
      {canPrune ? (
        <div className="backup-target-card__action">
          <p>{settingsDirty ? 'Sla de gewijzigde backupinstellingen eerst op voordat je de retentie toepast.' : actionText}</p>
          <button
            className="danger-button"
            type="button"
            onClick={onPrune}
            disabled={busy || settingsDirty}
            title={settingsDirty ? 'Sla de backupinstellingen eerst op.' : undefined}
          >
            {pruning ? 'Retentie toepassen...' : inventoryAvailable ? 'Retentie nu toepassen' : 'Retentie controleren en toepassen'}
          </button>
        </div>
      ) : (
        <p className="backup-target-card__note">
          {!configured
            ? 'Stel eerst de Samba-server, share, gebruikersnaam en het wachtwoord in om backups en retentie voor dit doel te gebruiken.'
            : !inventoryAvailable
              ? 'De Samba-share is niet gekoppeld. De telling en retentiestatus blijven onbekend tot de share beschikbaar is.'
            : automaticDefault
              ? 'Dit doel ontvangt standaard de automatische backups.'
              : 'Dit doel ontvangt niet standaard automatische backups; de bewaarlimiet wordt hier wel afzonderlijk beoordeeld.'}
        </p>
      )}
    </article>
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
  const recipientById = useMemo(() => new Map(recipients.map((recipient) => [recipient.id, recipient])), [recipients]);
  const selectedIdSet = useMemo(() => new Set(selectedIds), [selectedIds]);
  const selectedRecipients = useMemo(
    () => selectedIds
      .map((id) => recipientById.get(id))
      .filter((recipient): recipient is BackupReportRecipient => recipient !== undefined),
    [recipientById, selectedIds],
  );
  const availableRecipients = useMemo(
    () => recipients.filter((recipient) => !selectedIdSet.has(recipient.id)),
    [recipients, selectedIdSet],
  );
  const searchableRecipient = useMemo(() => findRecipientBySearch(availableRecipients, search), [availableRecipients, search]);

  function addFromSearch() {
    if (searchableRecipient === null) {
      return;
    }

    onAdd(searchableRecipient.id);
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
          <button className="secondary-button" type="button" onClick={addFromSearch} disabled={searchableRecipient === null}>
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

function actionSuccessMessage(label: BackupActionLabel, target?: BackupTarget): string {
  switch (label) {
    case 'create':
      return 'Backup is gemaakt.';
    case 'verify':
      return 'Backup is geverifieerd.';
    case 'settings':
      return 'Backupinstellingen zijn opgeslagen.';
    case 'prune':
      return target === undefined ? 'Retentie is toegepast.' : `Retentie is toegepast op ${targetLabel(target)}.`;
    case 'refresh':
      return 'Backupoverzicht is vernieuwd.';
    case 'uploadRestore':
      return 'Upload backup is teruggezet.';
    case 'restore':
      return 'Backup is teruggezet.';
  }
}

function toSettingsForm(settings?: BackupSettings | null, recipients: BackupReportRecipient[] = []): BackupSettingsForm {
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

function backupSettingsFormsEqual(left: BackupSettingsForm, right: BackupSettingsForm): boolean {
  return left.target === right.target
    && left.localPath === right.localPath
    && left.sambaServer === right.sambaServer
    && left.sambaShareName === right.sambaShareName
    && left.sambaShare === right.sambaShare
    && left.sambaMount === right.sambaMount
    && left.sambaUsername === right.sambaUsername
    && left.sambaPassword === right.sambaPassword
    && left.sambaDomain === right.sambaDomain
    && left.sambaVersion === right.sambaVersion
    && left.autoEnabled === right.autoEnabled
    && left.autoFrequency === right.autoFrequency
    && left.autoDayOfWeek === right.autoDayOfWeek
    && left.autoTime === right.autoTime
    && left.retentionCount === right.retentionCount
    && sameIds(left.backupReportSuccessUserIds, right.backupReportSuccessUserIds)
    && sameIds(left.backupReportFailedUserIds, right.backupReportFailedUserIds);
}

function sameIds(left: string[], right: string[]): boolean {
  if (left.length !== right.length) {
    return false;
  }

  const leftIds = new Set(left);
  return right.every((id) => leftIds.has(id));
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
