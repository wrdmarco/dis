import Link from 'next/link';
import { type FormEvent, useMemo, useRef, useState } from 'react';
import {
  ArrowLeft,
  LockKeyhole,
  Pencil,
  Plus,
  Search,
  Trash2,
  UserRound,
  UsersRound,
} from 'lucide-react';
import { useConfirmDialog } from '../../components/ConfirmDialogContext';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type {
  CalendarGroup,
  CalendarGroupMemberOptions,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import styles from './CalendarGroupsPage.module.css';

interface CalendarGroupFormState {
  name: string;
  description: string;
  userIds: string[];
  teamIds: string[];
}

const initialForm: CalendarGroupFormState = {
  name: '',
  description: '',
  userIds: [],
  teamIds: [],
};

export function CalendarGroupsPage() {
  const { api } = useAuth();
  const confirmAction = useConfirmDialog();
  const groups = useApiResource<CalendarGroup[]>('/calendar-groups');
  const memberOptions = useApiResource<CalendarGroupMemberOptions>(
    '/calendar-groups/member-options',
  );
  const [form, setForm] = useState<CalendarGroupFormState>(initialForm);
  const [editingGroup, setEditingGroup] = useState<CalendarGroup | null>(null);
  const [memberSearch, setMemberSearch] = useState('');
  const [searching, setSearching] = useState(false);
  const [saving, setSaving] = useState(false);
  const [deletingId, setDeletingId] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const latestForm = useRef(form);
  const memberSearchSequence = useRef(0);
  latestForm.current = form;

  const selectableUsers = useMemo(
    () => mergeById(editingGroup?.direct_users ?? [], memberOptions.data?.users ?? []),
    [editingGroup, memberOptions.data?.users],
  );
  const selectableTeams = useMemo(
    () => mergeById(editingGroup?.teams ?? [], memberOptions.data?.teams ?? []),
    [editingGroup, memberOptions.data?.teams],
  );

  async function submitGroup(submitEvent: FormEvent<HTMLFormElement>) {
    submitEvent.preventDefault();
    if (form.name.trim() === '' || editingGroup?.is_everyone === true) {
      return;
    }

    setSaving(true);
    clearFeedback();
    const payload = {
      name: form.name.trim(),
      description: form.description.trim() || null,
      user_ids: form.userIds,
      team_ids: form.teamIds,
    };

    try {
      if (editingGroup === null) {
        await api.post<CalendarGroup>('/calendar-groups', payload);
        setMessage('Agendagroep toegevoegd.');
      } else {
        await api.patch<CalendarGroup>(`/calendar-groups/${editingGroup.id}`, payload);
        setMessage('Agendagroep bijgewerkt.');
      }
      resetForm();
      await groups.reload();
    } catch (err) {
      setError(apiError(err, 'Agendagroep opslaan mislukt.'));
    } finally {
      setSaving(false);
    }
  }

  async function searchMembers() {
    if (searching) {
      return;
    }

    const searchSequence = ++memberSearchSequence.current;
    setSearching(true);
    setError(null);
    try {
      const query = memberSearch.trim() === ''
        ? ''
        : `?search=${encodeURIComponent(memberSearch.trim())}`;
      const response = await api.get<CalendarGroupMemberOptions>(
        `/calendar-groups/member-options${query}`,
      );
      if (searchSequence !== memberSearchSequence.current) {
        return;
      }
      memberOptions.mutate((current) => ({
        users: mergeById(
          current?.users.filter((user) => latestForm.current.userIds.includes(user.id)) ?? [],
          response.data.users,
        ),
        teams: mergeById(
          current?.teams.filter((team) => latestForm.current.teamIds.includes(team.id)) ?? [],
          response.data.teams,
        ),
      }));
    } catch (err) {
      if (searchSequence === memberSearchSequence.current) {
        setError(apiError(err, 'Groepsleden zoeken mislukt.'));
      }
    } finally {
      if (searchSequence === memberSearchSequence.current) {
        setSearching(false);
      }
    }
  }

  function startEditing(group: CalendarGroup) {
    if (group.is_everyone) {
      return;
    }
    memberSearchSequence.current++;
    setSearching(false);
    setEditingGroup(group);
    setForm({
      name: group.name,
      description: group.description ?? '',
      userIds: group.direct_users.map((user) => user.id),
      teamIds: group.teams.map((team) => team.id),
    });
    setMemberSearch('');
    clearFeedback();
    window.requestAnimationFrame(() => {
      document.getElementById('calendar-group-name')?.focus();
      document.getElementById('calendar-group-form')?.scrollIntoView({
        block: 'start',
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
          ? 'auto'
          : 'smooth',
      });
    });
  }

  async function deleteGroup(group: CalendarGroup) {
    if (
      group.is_everyone
      || !await confirmAction({
        title: 'Agendagroep verwijderen?',
        message: `Je verwijdert de agendagroep “${group.name}”.`,
        confirmLabel: 'Groep verwijderen',
        intent: 'danger',
      })
    ) {
      return;
    }

    setDeletingId(group.id);
    clearFeedback();
    try {
      await api.delete(`/calendar-groups/${group.id}`);
      if (editingGroup?.id === group.id) {
        resetForm();
      }
      setMessage('Agendagroep verwijderd.');
      await groups.reload();
    } catch (err) {
      setError(apiError(err, 'Agendagroep verwijderen mislukt.'));
    } finally {
      setDeletingId(null);
    }
  }

  function resetForm() {
    memberSearchSequence.current++;
    setSearching(false);
    setEditingGroup(null);
    setForm(initialForm);
    setMemberSearch('');
  }

  function clearFeedback() {
    setMessage(null);
    setError(null);
  }

  return (
    <div className={`page-stack ${styles.groupsPage}`}>
      <Panel
        title="Agendagroepen"
        action={(
          <Link className="secondary-button" href="/calendar">
            <ArrowLeft size={16} aria-hidden /> Terug naar Agenda
          </Link>
        )}
      >
        <p className={styles.intro}>
          Agenda-items worden uitsluitend aan deze groepen gekoppeld. Voeg gebruikers rechtstreeks
          toe of koppel teams; wijzigingen in een team werken automatisch door in het effectieve
          groepslidmaatschap.
        </p>
        {message ? <p className="success-text" role="status">{message}</p> : null}
        {error ? <p className="form-error" role="alert">{error}</p> : null}
      </Panel>

      <div className={styles.workspace}>
        <Panel title={editingGroup ? `Groep aanpassen: ${editingGroup.name}` : 'Groep toevoegen'}>
          <form id="calendar-group-form" className={styles.groupForm} onSubmit={submitGroup}>
            <label htmlFor="calendar-group-name">
              Naam
              <input
                id="calendar-group-name"
                maxLength={160}
                value={form.name}
                onChange={(event) => setForm((current) => ({
                  ...current,
                  name: event.target.value,
                }))}
                required
              />
            </label>
            <label htmlFor="calendar-group-description">
              Omschrijving
              <textarea
                id="calendar-group-description"
                rows={3}
                maxLength={2000}
                value={form.description}
                onChange={(event) => setForm((current) => ({
                  ...current,
                  description: event.target.value,
                }))}
              />
            </label>

            <div className={styles.memberSearch}>
              <label htmlFor="calendar-group-member-search">Gebruikers zoeken</label>
              <div>
                <input
                  id="calendar-group-member-search"
                  type="search"
                  value={memberSearch}
                  onChange={(event) => setMemberSearch(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                      event.preventDefault();
                      void searchMembers();
                    }
                  }}
                  placeholder="Naam of e-mailadres"
                />
                <button
                  className="secondary-button"
                  type="button"
                  onClick={() => void searchMembers()}
                  disabled={searching}
                >
                  <Search size={16} aria-hidden />
                  {searching ? 'Zoeken...' : 'Zoeken'}
                </button>
              </div>
            </div>

            <fieldset>
              <legend>Individuele gebruikers</legend>
              {memberOptions.loading ? <p className="form-note">Gebruikers laden...</p> : null}
              {memberOptions.error ? (
                <p className="form-error" role="alert">{memberOptions.error}</p>
              ) : null}
              <div className={styles.memberChoices}>
                {selectableUsers.map((user) => (
                  <label key={user.id}>
                    <input
                      type="checkbox"
                      checked={form.userIds.includes(user.id)}
                      onChange={(event) => setForm((current) => ({
                        ...current,
                        userIds: toggleId(current.userIds, user.id, event.target.checked),
                      }))}
                    />
                    <span>
                      <strong>{user.name}</strong>
                      <small>{user.email}</small>
                    </span>
                  </label>
                ))}
              </div>
              {!memberOptions.loading && selectableUsers.length === 0 ? (
                <p className="form-note">Geen gebruikers gevonden.</p>
              ) : null}
            </fieldset>

            <fieldset>
              <legend>Teams met automatische doorerving</legend>
              <div className={styles.memberChoices}>
                {selectableTeams.map((team) => (
                  <label key={team.id}>
                    <input
                      type="checkbox"
                      checked={form.teamIds.includes(team.id)}
                      onChange={(event) => setForm((current) => ({
                        ...current,
                        teamIds: toggleId(current.teamIds, team.id, event.target.checked),
                      }))}
                    />
                    <span>
                      <strong>{team.name}</strong>
                      <small>{team.code}</small>
                    </span>
                  </label>
                ))}
              </div>
              {!memberOptions.loading && selectableTeams.length === 0 ? (
                <p className="form-note">Geen teams gevonden.</p>
              ) : null}
            </fieldset>

            <div className={styles.formSummary}>
              <span><UserRound size={16} aria-hidden /> {form.userIds.length} direct</span>
              <span><UsersRound size={16} aria-hidden /> {form.teamIds.length} teams</span>
            </div>
            <div className={styles.formActions}>
              {editingGroup ? (
                <button
                  className="secondary-button"
                  type="button"
                  onClick={resetForm}
                  disabled={saving}
                >
                  Annuleren
                </button>
              ) : null}
              <button
                className="primary-button"
                type="submit"
                disabled={saving || form.name.trim() === ''}
              >
                {editingGroup ? <Pencil size={16} aria-hidden /> : <Plus size={16} aria-hidden />}
                {saving ? 'Opslaan...' : editingGroup ? 'Wijzigingen opslaan' : 'Groep toevoegen'}
              </button>
            </div>
          </form>
        </Panel>

        <Panel title="Bestaande groepen">
          <ResourceState
            loading={groups.loading}
            error={groups.error}
            empty={(groups.data?.length ?? 0) === 0}
          >
            <div className={styles.groupList}>
              {(groups.data ?? []).map((group) => (
                <article
                  className={styles.groupCard}
                  data-system={group.is_everyone ? 'true' : 'false'}
                  key={group.id}
                >
                  <header>
                    <div>
                      <h3>{group.name}</h3>
                      {group.is_everyone ? (
                        <span className={styles.systemBadge}>
                          <LockKeyhole size={13} aria-hidden /> Systeemgroep
                        </span>
                      ) : null}
                    </div>
                    <strong>{group.effective_member_count}</strong>
                  </header>
                  <p>
                    {group.is_everyone
                      ? 'Bevat automatisch alle gebruikers en kan niet worden aangepast of verwijderd.'
                      : group.description || 'Geen omschrijving.'}
                  </p>
                  <dl>
                    <div>
                      <dt>Direct</dt>
                      <dd>{group.direct_user_count}</dd>
                    </div>
                    <div>
                      <dt>Teams</dt>
                      <dd>{group.team_count}</dd>
                    </div>
                    <div>
                      <dt>Effectief</dt>
                      <dd>{group.effective_member_count}</dd>
                    </div>
                  </dl>
                  {!group.is_everyone ? (
                    <div className={styles.cardActions}>
                      <button
                        className="secondary-button"
                        type="button"
                        onClick={() => startEditing(group)}
                      >
                        <Pencil size={16} aria-hidden /> Aanpassen
                      </button>
                      <button
                        className="secondary-button"
                        type="button"
                        onClick={() => void deleteGroup(group)}
                        disabled={deletingId !== null}
                      >
                        <Trash2 size={16} aria-hidden />
                        {deletingId === group.id ? 'Verwijderen...' : 'Verwijderen'}
                      </button>
                    </div>
                  ) : null}
                </article>
              ))}
            </div>
          </ResourceState>
        </Panel>
      </div>
    </div>
  );
}

function toggleId(ids: string[], id: string, checked: boolean): string[] {
  return checked
    ? [...new Set([...ids, id])]
    : ids.filter((candidate) => candidate !== id);
}

function mergeById<T extends { id: string }>(first: T[], second: T[]): T[] {
  return [...new Map([...first, ...second].map((item) => [item.id, item])).values()]
    .sort((left, right) => {
      const leftLabel = 'name' in left && typeof left.name === 'string' ? left.name : left.id;
      const rightLabel = 'name' in right && typeof right.name === 'string' ? right.name : right.id;
      return leftLabel.localeCompare(rightLabel, 'nl');
    });
}

function apiError(error: unknown, fallback: string): string {
  return error instanceof ApiClientError ? error.message : fallback;
}
