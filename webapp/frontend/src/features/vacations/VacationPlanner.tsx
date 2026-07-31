'use client';

import { FormEvent, useEffect, useState } from 'react';
import { CalendarDays, Pencil, Plus, Trash2 } from 'lucide-react';
import { ModalDialog } from '../../components/ModalDialog';
import { Panel } from '../../components/Panel';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateOnly, todayAmsterdamDateInputValue } from '../../lib/dateTime';
import type { UserVacation } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

type VacationScope = 'mine' | 'user';

interface VacationPlannerProps {
  scope: VacationScope;
  userId?: string;
  canView?: boolean;
  canManage?: boolean;
  onChanged?: () => void | Promise<void>;
}

interface VacationFormState {
  startsAt: string;
  endsAt: string;
  isAvailable: boolean;
  note: string;
}

export function VacationPlanner({
  scope,
  userId,
  canView = true,
  canManage = true,
  onChanged,
}: VacationPlannerProps) {
  const { api } = useAuth();
  const [vacations, setVacations] = useState<UserVacation[]>([]);
  const [form, setForm] = useState<VacationFormState>(emptyVacationForm);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [editorOpen, setEditorOpen] = useState(false);
  const [vacationToDelete, setVacationToDelete] = useState<UserVacation | null>(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const enabled = canView && (scope === 'mine' || userId !== undefined);
  const listPath = scope === 'mine'
    ? '/vacations/mine'
    : `/users/${encodeURIComponent(userId ?? '')}/vacations`;

  useEffect(() => {
    if (!enabled) {
      setVacations([]);
      setLoading(false);
      return;
    }

    let cancelled = false;
    setVacations([]);
    setEditingId(null);
    setEditorOpen(false);
    setVacationToDelete(null);
    setLoading(true);
    setError(null);
    setDeleteError(null);
    setMessage(null);

    void api.get<UserVacation[]>(listPath)
      .then((response) => {
        if (!cancelled) {
          setVacations(sortVacations(response.data));
        }
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(errorMessage(err, 'Vakantieplanning kon niet worden geladen.'));
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [api, enabled, listPath]);

  if (!enabled) {
    return null;
  }

  async function submitVacation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canManage || form.startsAt === '' || form.endsAt === '') {
      return;
    }
    if (form.endsAt < form.startsAt) {
      setError('De einddatum mag niet voor de begindatum liggen.');
      return;
    }

    setSaving(true);
    setError(null);
    setMessage(null);
    const payload = {
      starts_at: form.startsAt,
      ends_at: form.endsAt,
      is_available: form.isAvailable,
      note: form.note.trim() === '' ? null : form.note.trim(),
    };

    try {
      const response = editingId === null
        ? await api.post<UserVacation>(listPath, payload)
        : await api.patch<UserVacation>(`/vacations/${editingId}`, payload);
      setVacations((current) => sortVacations([
        ...current.filter((vacation) => vacation.id !== response.data.id),
        response.data,
      ]));
      setMessage(editingId === null ? 'Periode toegevoegd.' : 'Periode aangepast.');
      resetForm();
      setEditorOpen(false);
      await onChanged?.();
    } catch (err) {
      setError(errorMessage(err, editingId === null ? 'Periode toevoegen mislukt.' : 'Periode aanpassen mislukt.'));
    } finally {
      setSaving(false);
    }
  }

  function editVacation(vacation: UserVacation) {
    if (!canManage) {
      return;
    }

    setEditingId(vacation.id);
    setForm({
      startsAt: vacation.starts_at,
      endsAt: vacation.ends_at,
      isAvailable: vacation.is_available,
      note: vacation.note ?? '',
    });
    setError(null);
    setMessage(null);
    setEditorOpen(true);
  }

  function resetForm() {
    setEditingId(null);
    setForm(emptyVacationForm());
  }

  function openVacationEditor() {
    resetForm();
    setError(null);
    setMessage(null);
    setEditorOpen(true);
  }

  function closeVacationEditor() {
    if (saving) {
      return;
    }

    setEditorOpen(false);
    setError(null);
    resetForm();
  }

  function openDeleteModal(vacation: UserVacation) {
    setDeleteError(null);
    setVacationToDelete(vacation);
  }

  async function deleteVacation() {
    if (!canManage || vacationToDelete === null) {
      return;
    }

    const target = vacationToDelete;
    setDeleting(true);
    setDeleteError(null);
    setMessage(null);
    try {
      await api.delete(`/vacations/${target.id}`);
      setVacations((current) => current.filter((vacation) => vacation.id !== target.id));
      if (editingId === target.id) {
        resetForm();
      }
      setVacationToDelete(null);
      setDeleteError(null);
      setMessage('Periode verwijderd.');
      await onChanged?.();
    } catch (err) {
      setDeleteError(errorMessage(err, 'Periode verwijderen mislukt.'));
    } finally {
      setDeleting(false);
    }
  }

  return (
    <>
      <Panel
        title={scope === 'mine' ? 'Mijn vakantieplanning' : 'Vakantieplanning'}
        action={canManage ? (
          <button className="primary-button" type="button" onClick={openVacationEditor}>
            <Plus aria-hidden size={16} /> Periode toevoegen
          </button>
        ) : undefined}
      >
        <div className="panel-body vacation-planner">
          {!editorOpen && error ? <p className="form-error" role="alert">{error}</p> : null}
          {message ? <p className="form-note" role="status">{message}</p> : null}
          {loading ? <p className="muted-text">Vakantieplanning laden...</p> : null}
          {!loading && vacations.length === 0 ? (
            <div className="vacation-planner__empty">
              <CalendarDays aria-hidden size={22} />
              <div>
                <strong>Nog geen periodes gepland</strong>
                <span>
                  {canManage
                    ? scope === 'mine' ? 'Voeg je eerste periode toe.' : 'Voeg een eerste periode toe.'
                    : 'Er zijn geen periodes geregistreerd.'}
                </span>
              </div>
            </div>
          ) : null}
          {vacations.length > 0 ? (
            <div className="vacation-card-grid" aria-label="Geplande vakantieperiodes">
              {vacations.map((vacation) => (
                <article className="vacation-card" key={vacation.id}>
                  <div className="vacation-card__date">
                    <CalendarDays aria-hidden size={19} />
                    <div>
                      <strong>{vacationDateRange(vacation)}</strong>
                      <span>{vacation.status === 'active' ? 'Actieve periode' : 'Geplande periode'}</span>
                    </div>
                  </div>
                  <div className="vacation-card__details">
                    <StatusPill
                      value={vacation.is_available ? 'available' : 'unavailable'}
                      tone={vacation.is_available ? 'good' : 'warn'}
                    />
                    {vacation.note ? <p>{vacation.note}</p> : <span className="muted-text">Geen notitie</span>}
                  </div>
                  {canManage ? (
                    <div className="vacation-card__actions">
                      <button className="secondary-button" type="button" onClick={() => editVacation(vacation)} disabled={saving || deleting}>
                        <Pencil aria-hidden size={16} /> Aanpassen
                      </button>
                      <button className="danger-button" type="button" onClick={() => openDeleteModal(vacation)} disabled={saving || deleting}>
                        <Trash2 aria-hidden size={16} /> Verwijderen
                      </button>
                    </div>
                  ) : null}
                </article>
              ))}
            </div>
          ) : null}
        </div>
      </Panel>

      {editorOpen ? (
        <ModalDialog
          eyebrow={scope === 'mine' ? 'Mijn vakantieplanning' : 'Vakantieplanning'}
          title={editingId === null ? 'Periode toevoegen' : 'Periode aanpassen'}
          description={scope === 'mine'
            ? 'Leg vast of je tijdens deze periode wel of niet beschikbaar bent.'
            : 'Leg vast of deze gebruiker tijdens deze periode wel of niet beschikbaar is.'}
          narrow
          closeDisabled={saving}
          onClose={closeVacationEditor}
        >
          <form className="form-grid" onSubmit={submitVacation}>
            <label>
              Begindatum
              <input
                data-dialog-initial="true"
                type="date"
                value={form.startsAt}
                required
                onChange={(event) => setForm((current) => ({ ...current, startsAt: event.target.value }))}
              />
            </label>
            <label>
              Einddatum
              <input
                type="date"
                value={form.endsAt}
                min={form.startsAt || undefined}
                required
                onChange={(event) => setForm((current) => ({ ...current, endsAt: event.target.value }))}
              />
            </label>
            <label>
              Beschikbaarheid
              <select
                value={form.isAvailable ? 'available' : 'unavailable'}
                onChange={(event) => setForm((current) => ({
                  ...current,
                  isAvailable: event.target.value === 'available',
                }))}
              >
                <option value="unavailable">Niet beschikbaar</option>
                <option value="available">Wel beschikbaar</option>
              </select>
            </label>
            <label>
              Notitie
              <input
                value={form.note}
                maxLength={1000}
                placeholder="Optioneel"
                onChange={(event) => setForm((current) => ({ ...current, note: event.target.value }))}
              />
            </label>
            {error ? <p className="form-error form-grid__wide" role="alert">{error}</p> : null}
            <div className="actions-row form-grid__wide">
              <button className="secondary-button" type="button" onClick={closeVacationEditor} disabled={saving}>
                Annuleren
              </button>
              <button
                className="primary-button"
                type="submit"
                disabled={saving || form.startsAt === '' || form.endsAt === ''}
              >
                {saving ? 'Opslaan...' : editingId === null ? 'Periode toevoegen' : 'Wijzigingen opslaan'}
              </button>
            </div>
          </form>
        </ModalDialog>
      ) : null}

      {vacationToDelete !== null ? (
        <ModalDialog
          eyebrow="Vakantieplanning"
          title="Periode verwijderen?"
          description={(
            <>
              Deze {vacationToDelete.status === 'active' ? 'actieve' : 'geplande'} periode staat als{' '}
              {vacationToDelete.is_available ? 'beschikbaar' : 'niet beschikbaar'} en wordt definitief verwijderd.
            </>
          )}
          narrow
          closeDisabled={deleting}
          onClose={() => setVacationToDelete(null)}
        >
          <div className="confirm-dialog">
            <p>
              Weet je zeker dat je de periode <strong>{vacationDateRange(vacationToDelete)}</strong> wilt verwijderen?
            </p>
            {deleteError ? <p className="form-error" role="alert">{deleteError}</p> : null}
          </div>
          <div className="actions-row">
            <button className="secondary-button" type="button" onClick={() => setVacationToDelete(null)} disabled={deleting}>
              Annuleren
            </button>
            <button className="danger-button" type="button" onClick={() => void deleteVacation()} disabled={deleting}>
              {deleting ? 'Verwijderen...' : 'Periode definitief verwijderen'}
            </button>
          </div>
        </ModalDialog>
      ) : null}
    </>
  );
}

function emptyVacationForm(): VacationFormState {
  const today = todayAmsterdamDateInputValue();

  return {
    startsAt: today,
    endsAt: today,
    isAvailable: false,
    note: '',
  };
}

function sortVacations(vacations: UserVacation[]): UserVacation[] {
  return [...vacations].sort((first, second) => (
    first.starts_at.localeCompare(second.starts_at) || first.id.localeCompare(second.id)
  ));
}

function vacationDateRange(vacation: UserVacation): string {
  const startsAt = formatDateOnly(vacation.starts_at);
  const endsAt = formatDateOnly(vacation.ends_at);

  return vacation.starts_at === vacation.ends_at ? startsAt : `${startsAt} t/m ${endsAt}`;
}

function errorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiClientError ? error.message : fallback;
}
