import { FormEvent, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { useApiResource } from '../../lib/useApiResource';
import type { ManualPushResult, Role, Team, User } from '../../types/api';
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

export function PushPage() {
  const { api } = useAuth();
  const roles = useApiResource<Role[]>('/admin/roles');
  const teams = useApiResource<Team[]>('/admin/teams');
  const users = useApiResource<User[]>('/users?per_page=200');
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
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Pushmelding versturen mislukt.');
    } finally {
      setSending(false);
    }
  }

  const resourcesLoading = roles.loading || teams.loading || users.loading;
  const resourcesError = roles.error ?? teams.error ?? users.error;

  return (
    <div className="page-stack">
      <Panel title="Handmatige pushmelding">
        <ResourceState loading={resourcesLoading} error={resourcesError} empty={false}>
          <form className="form-grid" onSubmit={submit}>
            <label>
              Titel
              <input value={form.title} maxLength={120} onChange={(event) => setForm((current) => ({ ...current, title: event.target.value }))} />
            </label>
            <label>
              Gebruikers
              <select multiple value={form.userIds} onChange={(event) => setForm((current) => ({ ...current, userIds: selectedValues(event.currentTarget) }))}>
                {users.data?.map((user) => (
                  <option key={user.id} value={user.id}>{user.name} - {user.email}</option>
                ))}
              </select>
            </label>
            <label>
              Teams
              <select multiple value={form.teamIds} onChange={(event) => setForm((current) => ({ ...current, teamIds: selectedValues(event.currentTarget) }))}>
                {teams.data?.map((team) => (
                  <option key={team.id} value={team.id}>{team.code} - {team.name}</option>
                ))}
              </select>
            </label>
            <label>
              Rollen
              <select multiple value={form.roleIds} onChange={(event) => setForm((current) => ({ ...current, roleIds: selectedValues(event.currentTarget) }))}>
                {roles.data?.map((role) => (
                  <option key={role.id} value={role.id}>{role.display_name}</option>
                ))}
              </select>
            </label>
            <label className="form-grid__wide">
              Bericht
              <textarea value={form.body} maxLength={1200} onChange={(event) => setForm((current) => ({ ...current, body: event.target.value }))} />
            </label>
            {error ? <p className="form-error form-grid__wide">{error}</p> : null}
            {result ? <p className="success-text form-grid__wide">{result}</p> : null}
            <div className="actions-row form-grid__wide">
              <button
                className="primary-button"
                type="submit"
                disabled={sending || !form.title.trim() || !form.body.trim() || recipientCount(form) === 0}
              >
                {sending ? 'Versturen...' : 'Pushmelding versturen'}
              </button>
            </div>
          </form>
        </ResourceState>
      </Panel>
    </div>
  );
}

function selectedValues(select: HTMLSelectElement): string[] {
  return Array.from(select.selectedOptions).map((option) => option.value);
}

function recipientCount(form: ManualPushForm): number {
  return form.teamIds.length + form.roleIds.length + form.userIds.length;
}
