import { FormEvent, useState } from 'react';
import { Send, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type { ManualPushResult, PushDeliveryLog, Role, Team, User } from '../../types/api';
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

export function PushPage() {
  const { api } = useAuth();
  const options = useApiResource<PushOptions>('/admin/push/options');
  const logs = useApiResource<PushDeliveryLog[]>('/admin/push/logs?per_page=10');
  const [form, setForm] = useState<ManualPushForm>(emptyPushForm);
  const [sending, setSending] = useState(false);
  const [result, setResult] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

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

function formatDate(value?: string | null): string {
  return formatDateTime(value);
}
