import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Send, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type { ManualPushResult, PushDeliveryLog, Role, SystemSetting, Team, User } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

interface ManualPushForm {
  title: string;
  body: string;
  teamIds: string[];
  roleIds: string[];
  userIds: string[];
}

const emptyPushForm: ManualPushForm = {
  title: '',
  body: '',
  teamIds: [],
  roleIds: [],
  userIds: [],
};

interface PushOptions {
  teams: Team[];
  roles: Role[];
  users: User[];
}

interface PushTemplateForm {
  preannouncementTitle: string;
  preannouncementBody: string;
  dispatchTitle: string;
  dispatchBody: string;
  additionalInfoTitle: string;
  additionalInfoBody: string;
  cancellationTitle: string;
  cancellationBody: string;
}

const defaultTemplates: PushTemplateForm = {
  preannouncementTitle: 'D.I.S vooraankondiging',
  preannouncementBody: 'Ben je beschikbaar voor een melding in {{place}}?',
  dispatchTitle: 'NDT Alarmering',
  dispatchBody: '{{message}}',
  additionalInfoTitle: 'D.I.S aanvullende info',
  additionalInfoBody: '{{message}}',
  cancellationTitle: 'D.I.S geannuleerd',
  cancellationBody: 'De vooraankondiging in {{place}} is geannuleerd.',
};

export function PushPage() {
  const { api } = useAuth();
  const options = useApiResource<PushOptions>('/admin/push/options');
  const logs = useApiResource<PushDeliveryLog[]>('/admin/push/logs?per_page=10');
  const settings = useApiResource<SystemSetting[]>('/admin/settings');
  const initialTemplates = useMemo(() => toPushTemplateForm(settings.data ?? []), [settings.data]);
  const [form, setForm] = useState<ManualPushForm>(emptyPushForm);
  const [templates, setTemplates] = useState<PushTemplateForm>(initialTemplates);
  const [sending, setSending] = useState(false);
  const [savingTemplates, setSavingTemplates] = useState(false);
  const [result, setResult] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [templateMessage, setTemplateMessage] = useState<string | null>(null);
  const [templateError, setTemplateError] = useState<string | null>(null);

  useEffect(() => {
    setTemplates(initialTemplates);
  }, [initialTemplates]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSending(true);
    setError(null);
    setResult(null);

    try {
      const response = await api.post<ManualPushResult>('/admin/push/manual', {
        title: form.title,
        body: form.body,
        team_ids: form.teamIds,
        role_ids: form.roleIds,
        user_ids: form.userIds,
      });
      setResult(`${response.data.recipient_users} gebruikers, ${response.data.queued_tokens} push tokens in wachtrij.`);
      setForm(emptyPushForm);
      await logs.reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Pushmelding versturen mislukt.');
    } finally {
      setSending(false);
    }
  }

  function toggleValue(field: 'teamIds' | 'roleIds' | 'userIds', value: string) {
    setForm((current) => ({
      ...current,
      [field]: current[field].includes(value)
        ? current[field].filter((candidate) => candidate !== value)
        : [...current[field], value],
    }));
  }

  async function saveTemplates() {
    setSavingTemplates(true);
    setTemplateMessage(null);
    setTemplateError(null);

    try {
      await api.patch('/admin/settings', {
        settings: {
          'push.template.preannouncement_title': textSetting(templates.preannouncementTitle, defaultTemplates.preannouncementTitle),
          'push.template.preannouncement_body': textSetting(templates.preannouncementBody, defaultTemplates.preannouncementBody),
          'push.template.dispatch_title': textSetting(templates.dispatchTitle, defaultTemplates.dispatchTitle),
          'push.template.dispatch_body': textSetting(templates.dispatchBody, defaultTemplates.dispatchBody),
          'push.template.additional_info_title': textSetting(templates.additionalInfoTitle, defaultTemplates.additionalInfoTitle),
          'push.template.additional_info_body': textSetting(templates.additionalInfoBody, defaultTemplates.additionalInfoBody),
          'push.template.cancellation_title': textSetting(templates.cancellationTitle, defaultTemplates.cancellationTitle),
          'push.template.cancellation_body': textSetting(templates.cancellationBody, defaultTemplates.cancellationBody),
        },
      });
      await settings.reload();
      setTemplateMessage('Push templates zijn opgeslagen.');
    } catch (err) {
      setTemplateError(err instanceof Error ? err.message : 'Push templates opslaan mislukt.');
    } finally {
      setSavingTemplates(false);
    }
  }

  const resourcesLoading = options.loading;
  const resourcesError = options.error;
  const selectedCount = recipientCount(form);

  return (
    <div className="page-stack">
      <Panel title="Handmatige pushmelding">
        <ResourceState loading={resourcesLoading} error={resourcesError} empty={false}>
          <form className="push-composer" onSubmit={submit}>
            <div className="form-grid">
              <label>
                Titel
                <input value={form.title} maxLength={120} onChange={(event) => setForm((current) => ({ ...current, title: event.target.value }))} required />
              </label>
              <div className="push-summary">
                <strong>{selectedCount}</strong>
                <span>ontvangerselecties</span>
                <button className="secondary-button" type="button" onClick={() => setForm((current) => ({ ...current, teamIds: [], roleIds: [], userIds: [] }))} disabled={selectedCount === 0}>
                  <X size={16} /> Selectie wissen
                </button>
              </div>
              <label className="form-grid__wide">
                Bericht
                <textarea value={form.body} maxLength={1200} onChange={(event) => setForm((current) => ({ ...current, body: event.target.value }))} required />
              </label>
            </div>

            <div className="push-targets">
              <section className="push-targets__section">
                <h3>Teams</h3>
                <div className="checkbox-grid">
                  {options.data?.teams.map((team) => (
                    <label className="checkbox-card" key={team.id}>
                      <input
                        type="checkbox"
                        checked={form.teamIds.includes(team.id)}
                        onChange={() => toggleValue('teamIds', team.id)}
                      />
                      <span>
                        <strong>{team.code} - {team.name}</strong>
                        <small>{team.alert_teams?.length ? `Alarmeert ook: ${team.alert_teams.map((alertTeam) => alertTeam.code).join(', ')}` : team.type}</small>
                      </span>
                    </label>
                  ))}
                </div>
              </section>

              <section className="push-targets__section">
                <h3>Rollen</h3>
                <div className="checkbox-grid">
                  {options.data?.roles.map((role) => (
                    <label className="checkbox-card" key={role.id}>
                      <input
                        type="checkbox"
                        checked={form.roleIds.includes(role.id)}
                        onChange={() => toggleValue('roleIds', role.id)}
                      />
                      <span>
                        <strong>{role.display_name}</strong>
                        <small>{role.description ?? role.name}</small>
                      </span>
                    </label>
                  ))}
                </div>
              </section>

              <section className="push-targets__section push-targets__section--wide">
                <h3>Individuele gebruikers</h3>
                <div className="checkbox-grid checkbox-grid--dense">
                  {options.data?.users.map((user) => (
                    <label className="checkbox-card" key={user.id}>
                      <input
                        type="checkbox"
                        checked={form.userIds.includes(user.id)}
                        onChange={() => toggleValue('userIds', user.id)}
                      />
                      <span>
                        <strong>{user.name}</strong>
                        <small>{user.email}</small>
                      </span>
                    </label>
                  ))}
                </div>
              </section>
            </div>

            {error ? <p className="form-error">{error}</p> : null}
            {result ? <p className="success-text">{result}</p> : null}
            <div className="actions-row">
              <button
                className="primary-button"
                type="submit"
                disabled={sending || !form.title.trim() || !form.body.trim() || selectedCount === 0}
              >
                <Send size={16} /> {sending ? 'Versturen...' : 'Pushmelding versturen'}
              </button>
            </div>
          </form>
        </ResourceState>
      </Panel>

      <Panel title="Push templates">
        <ResourceState loading={settings.loading} error={settings.error} empty={false}>
          <div className="form-grid">
            <TemplateFields
              title="Vooraankondiging"
              titleValue={templates.preannouncementTitle}
              bodyValue={templates.preannouncementBody}
              onTitleChange={(value) => setTemplates((current) => ({ ...current, preannouncementTitle: value }))}
              onBodyChange={(value) => setTemplates((current) => ({ ...current, preannouncementBody: value }))}
            />
            <TemplateFields
              title="Alarmering"
              titleValue={templates.dispatchTitle}
              bodyValue={templates.dispatchBody}
              onTitleChange={(value) => setTemplates((current) => ({ ...current, dispatchTitle: value }))}
              onBodyChange={(value) => setTemplates((current) => ({ ...current, dispatchBody: value }))}
            />
            <TemplateFields
              title="Nadere info"
              titleValue={templates.additionalInfoTitle}
              bodyValue={templates.additionalInfoBody}
              onTitleChange={(value) => setTemplates((current) => ({ ...current, additionalInfoTitle: value }))}
              onBodyChange={(value) => setTemplates((current) => ({ ...current, additionalInfoBody: value }))}
            />
            <TemplateFields
              title="Annulering"
              titleValue={templates.cancellationTitle}
              bodyValue={templates.cancellationBody}
              onTitleChange={(value) => setTemplates((current) => ({ ...current, cancellationTitle: value }))}
              onBodyChange={(value) => setTemplates((current) => ({ ...current, cancellationBody: value }))}
            />
          </div>
          <div className="metadata-example">
            <strong>Beschikbare tokens</strong>
            <pre>{'{{place}}, {{message}}, {{reference}}, {{title}}, {{location}}, {{priority}}'}</pre>
          </div>
          <div className="metadata-example">
            <strong>Voorbeeld vooraankondiging</strong>
            <pre>{`${renderTemplate(templates.preannouncementTitle, sampleTokens)}\n${renderTemplate(templates.preannouncementBody, sampleTokens)}`}</pre>
          </div>
          {templateError ? <p className="form-error">{templateError}</p> : null}
          {templateMessage ? <p className="form-note">{templateMessage}</p> : null}
          <div className="actions-row">
            <button className="primary-button" type="button" onClick={() => void saveTemplates()} disabled={savingTemplates}>
              {savingTemplates ? 'Opslaan...' : 'Templates opslaan'}
            </button>
          </div>
        </ResourceState>
      </Panel>

      <Panel title="Laatste afleverpogingen">
        <ResourceState loading={logs.loading} error={logs.error} empty={(logs.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Tijd</th><th>Type</th><th>Status</th><th>Fout</th></tr></thead>
            <tbody>
              {logs.data?.map((log) => (
                <tr key={log.id}>
                  <td>{formatDate(log.sent_at ?? log.created_at)}</td>
                  <td>{log.message_type}</td>
                  <td>{log.status}</td>
                  <td className="mono">{log.error_code ?? '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
    </div>
  );
}

function recipientCount(form: ManualPushForm): number {
  return form.teamIds.length + form.roleIds.length + form.userIds.length;
}

function TemplateFields({
  title,
  titleValue,
  bodyValue,
  onTitleChange,
  onBodyChange,
}: {
  title: string;
  titleValue: string;
  bodyValue: string;
  onTitleChange: (value: string) => void;
  onBodyChange: (value: string) => void;
}) {
  return (
    <section className="form-grid__wide push-template-card">
      <h3>{title}</h3>
      <div className="form-grid">
        <label>
          Titel
          <input maxLength={160} value={titleValue} onChange={(event) => onTitleChange(event.target.value)} />
        </label>
        <label className="form-grid__wide">
          Bericht
          <textarea rows={4} maxLength={2000} value={bodyValue} onChange={(event) => onBodyChange(event.target.value)} />
        </label>
      </div>
    </section>
  );
}

function formatDate(value?: string | null): string {
  return formatDateTime(value);
}

function toPushTemplateForm(settings: SystemSetting[]): PushTemplateForm {
  const byKey = new Map(settings.map((setting) => [setting.key, setting.value]));

  return {
    preannouncementTitle: asString(byKey.get('push.template.preannouncement_title')) || defaultTemplates.preannouncementTitle,
    preannouncementBody: asString(byKey.get('push.template.preannouncement_body')) || defaultTemplates.preannouncementBody,
    dispatchTitle: asString(byKey.get('push.template.dispatch_title')) || defaultTemplates.dispatchTitle,
    dispatchBody: asString(byKey.get('push.template.dispatch_body')) || defaultTemplates.dispatchBody,
    additionalInfoTitle: asString(byKey.get('push.template.additional_info_title')) || defaultTemplates.additionalInfoTitle,
    additionalInfoBody: asString(byKey.get('push.template.additional_info_body')) || defaultTemplates.additionalInfoBody,
    cancellationTitle: asString(byKey.get('push.template.cancellation_title')) || defaultTemplates.cancellationTitle,
    cancellationBody: asString(byKey.get('push.template.cancellation_body')) || defaultTemplates.cancellationBody,
  };
}

function asString(value: unknown): string {
  return typeof value === 'string' ? value : '';
}

function textSetting(value: string, fallback: string): string {
  const text = value.trim();

  return text === '' ? fallback : text;
}

const sampleTokens: Record<string, string> = {
  place: 'Apeldoorn',
  message: 'Reactie vereist - DIS-20260704-ABCD',
  reference: 'DIS-20260704-ABCD',
  title: 'Zoekactie',
  location: 'Apeldoorn',
  priority: 'normal',
};

function renderTemplate(template: string, tokens: Record<string, string>): string {
  return Object.entries(tokens).reduce(
    (result, [key, value]) => result.replaceAll(`{{${key}}}`, value),
    template,
  );
}
