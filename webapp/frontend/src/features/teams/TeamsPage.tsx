import { FormEvent, useEffect, useState } from 'react';
import { Pencil, Plus, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { Certification, Team } from '../../types/api';

interface TeamFormState {
  code: string;
  name: string;
  type: string;
  parentTeamId: string;
  isOperational: boolean;
  alertTeamIds: string[];
  requiredCertificationIds: string[];
}

const emptyForm: TeamFormState = {
  code: '',
  name: '',
  type: 'base',
  parentTeamId: '',
  isOperational: true,
  alertTeamIds: [],
  requiredCertificationIds: [],
};

const teamTypes = [
  { value: 'base', label: 'Basisteam' },
  { value: 'subset', label: 'Subset' },
  { value: 'support', label: 'Support' },
];

export function TeamsPage() {
  const { api } = useAuth();
  const teams = useApiResource<Team[]>('/admin/teams');
  const certifications = useApiResource<Certification[]>('/admin/teams/certification-options');
  const [modalMode, setModalMode] = useState<'create' | 'edit' | null>(null);
  const [editingTeam, setEditingTeam] = useState<Team | null>(null);
  const [form, setForm] = useState<TeamFormState>(emptyForm);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (modalMode === null) {
      setEditingTeam(null);
      setForm(emptyForm);
      setError(null);
    }
  }, [modalMode]);

  function openCreateModal() {
    setEditingTeam(null);
    setForm(emptyForm);
    setError(null);
    setModalMode('create');
  }

  function openEditModal(team: Team) {
    setEditingTeam(team);
    setForm({
      code: team.code,
      name: team.name,
      type: team.type,
      parentTeamId: team.parent_team_id ?? '',
      isOperational: team.is_operational,
      alertTeamIds: team.alert_teams?.map((alertTeam) => alertTeam.id) ?? [],
      requiredCertificationIds: team.required_certifications?.map((certification) => certification.id) ?? [],
    });
    setError(null);
    setModalMode('edit');
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError(null);

    const payload = {
      code: form.code,
      name: form.name,
      type: form.type,
      parent_team_id: form.parentTeamId || null,
      is_operational: form.isOperational,
      alert_team_ids: form.alertTeamIds,
      required_certification_ids: form.requiredCertificationIds,
    };

    try {
      if (modalMode === 'edit' && editingTeam !== null) {
        await api.patch(`/admin/teams/${editingTeam.id}`, payload);
      } else {
        await api.post('/admin/teams', payload);
      }
      setModalMode(null);
      await teams.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Team kon niet worden opgeslagen.');
    } finally {
      setSaving(false);
    }
  }

  function toggleAlertTeam(teamId: string) {
    setForm((current) => ({
      ...current,
      alertTeamIds: current.alertTeamIds.includes(teamId)
        ? current.alertTeamIds.filter((candidate) => candidate !== teamId)
        : [...current.alertTeamIds, teamId],
    }));
  }

  function toggleRequiredCertification(certificationId: string) {
    setForm((current) => ({
      ...current,
      requiredCertificationIds: current.requiredCertificationIds.includes(certificationId)
        ? current.requiredCertificationIds.filter((candidate) => candidate !== certificationId)
        : [...current.requiredCertificationIds, certificationId],
    }));
  }

  const selectableTeams = teams.data?.filter((team) => team.id !== editingTeam?.id) ?? [];

  return (
    <div className="page-stack">
      <Panel
        title="Teams"
        action={(
          <button className="primary-button" type="button" onClick={openCreateModal}>
            <Plus size={16} /> Team aanmaken
          </button>
        )}
      >
        <ResourceState loading={teams.loading} error={teams.error} empty={(teams.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th scope="col">Code</th><th scope="col">Naam</th><th scope="col">Type</th><th scope="col">Ouderteam</th><th scope="col">Operationeel</th><th scope="col">Mee alarmeren</th><th scope="col">Certificaten vereist</th><th scope="col">Actie</th></tr></thead>
            <tbody>
              {teams.data?.map((team) => (
                <tr key={team.id}>
                  <td>{team.code}</td>
                  <td>{team.name}</td>
                  <td>{team.type}</td>
                  <td>{team.parent?.code ?? '-'}</td>
                  <td><StatusPill value={team.is_operational ? 'actief' : 'uit'} tone={team.is_operational ? 'good' : 'bad'} /></td>
                  <td>{team.alert_teams?.map((alertTeam) => alertTeam.code).join(', ') || '-'}</td>
                  <td>{team.required_certifications?.map((certification) => certification.code).join(', ') || '-'}</td>
                  <td>
                    <button className="secondary-button" type="button" onClick={() => openEditModal(team)}>
                      <Pencil size={16} /> Aanpassen
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>

      {modalMode !== null ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal" role="dialog" aria-modal="true" aria-labelledby="team-modal-title">
            <header className="modal__header">
              <h2 id="team-modal-title">{modalMode === 'edit' ? 'Team aanpassen' : 'Team aanmaken'}</h2>
              <button className="icon-button" type="button" onClick={() => setModalMode(null)} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <form className="form-grid" onSubmit={submit}>
              <label>
                Code
                <input value={form.code} onChange={(event) => setForm((current) => ({ ...current, code: event.target.value.toUpperCase() }))} required />
              </label>
              <label>
                Naam
                <input value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} required />
              </label>
              <label>
                Type
                <select value={form.type} onChange={(event) => setForm((current) => ({ ...current, type: event.target.value }))}>
                  {teamTypes.map((type) => (
                    <option key={type.value} value={type.value}>{type.label}</option>
                  ))}
                </select>
              </label>
              <label>
                Ouderteam
                <select value={form.parentTeamId} onChange={(event) => setForm((current) => ({ ...current, parentTeamId: event.target.value }))}>
                  <option value="">Geen</option>
                  {selectableTeams.map((team) => (
                    <option key={team.id} value={team.id}>{team.code} - {team.name}</option>
                  ))}
                </select>
              </label>
              <label className="check-label form-grid__wide">
                <input
                  type="checkbox"
                  checked={form.isOperational}
                  onChange={(event) => setForm((current) => ({ ...current, isOperational: event.target.checked }))}
                />
                Operationeel team
              </label>
              <div className="form-grid__wide">
                <span className="field-label">Teams die mee gealarmeerd moeten worden</span>
                <div className="checkbox-grid">
                  {selectableTeams.map((team) => (
                    <label className="checkbox-card" key={team.id}>
                      <input
                        type="checkbox"
                        checked={form.alertTeamIds.includes(team.id)}
                        onChange={() => toggleAlertTeam(team.id)}
                      />
                      <span>
                        <strong>{team.code} - {team.name}</strong>
                        <small>{team.type}</small>
                      </span>
                    </label>
                  ))}
                </div>
              </div>
              <div className="form-grid__wide">
                <span className="field-label">Certificaten vereist voor alarmering</span>
                <ResourceState loading={certifications.loading} error={certifications.error} empty={(certifications.data?.length ?? 0) === 0}>
                  <div className="checkbox-grid">
                    {certifications.data?.map((certification) => (
                      <label className="checkbox-card" key={certification.id}>
                        <input
                          type="checkbox"
                          checked={form.requiredCertificationIds.includes(certification.id)}
                          onChange={() => toggleRequiredCertification(certification.id)}
                        />
                        <span>
                          <strong>{certification.code} - {certification.name}</strong>
                          <small>{certification.description ?? 'Geen omschrijving'}</small>
                        </span>
                      </label>
                    ))}
                  </div>
                </ResourceState>
              </div>
              {error ? <p className="form-error form-grid__wide">{error}</p> : null}
              <div className="actions-row form-grid__wide">
                <button className="secondary-button" type="button" onClick={() => setModalMode(null)}>Annuleren</button>
                <button className="primary-button" type="submit" disabled={saving}>
                  {saving ? 'Opslaan...' : 'Opslaan'}
                </button>
              </div>
            </form>
          </section>
        </div>
      ) : null}
    </div>
  );
}
