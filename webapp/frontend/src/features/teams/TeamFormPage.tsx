'use client';

import { type FormEvent, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type { Certification, Team } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

interface TeamFormPageProps {
  teamId?: string;
}

interface TeamFormState {
  code: string;
  name: string;
  isOperational: boolean;
  alertTeamIds: string[];
  requiredCertificationIds: string[];
}

interface TeamFormProps {
  team: Team | null;
  teams: Team[];
  certifications: Certification[] | null;
  certificationsLoading: boolean;
  certificationsError: string | null;
}

export function TeamFormPage({ teamId }: TeamFormPageProps) {
  const teams = useApiResource<Team[]>('/admin/teams');
  const certifications = useApiResource<Certification[]>('/admin/teams/certification-options');
  const team = teamId === undefined
    ? null
    : teams.data?.find((candidate) => candidate.id === teamId) ?? null;
  const formAvailable = teamId === undefined || team !== null;

  return (
    <div className="page-stack">
      <Panel
        title={teamId === undefined ? 'Team aanmaken' : 'Team aanpassen'}
        action={(
          <Link className="secondary-button" href="/teams">
            <ArrowLeft size={16} /> Terug naar teams
          </Link>
        )}
      >
        <ResourceState
          loading={teams.loading}
          error={teams.error}
          empty={teamId !== undefined && team === null}
        >
          {formAvailable ? (
            <TeamForm
              key={teamId ?? 'new'}
              team={team}
              teams={teams.data ?? []}
              certifications={certifications.data}
              certificationsLoading={certifications.loading}
              certificationsError={certifications.error}
            />
          ) : null}
        </ResourceState>
      </Panel>
    </div>
  );
}

function TeamForm({
  team,
  teams,
  certifications,
  certificationsLoading,
  certificationsError,
}: TeamFormProps) {
  const router = useRouter();
  const { api } = useAuth();
  const [form, setForm] = useState<TeamFormState>(() => team === null ? createEmptyForm() : formFromTeam(team));
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const selectableTeams = teams.filter((candidate) => candidate.id !== team?.id);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError(null);

    const payload = {
      code: form.code,
      name: form.name,
      is_operational: form.isOperational,
      alert_team_ids: form.alertTeamIds,
      required_certification_ids: form.requiredCertificationIds,
    };

    try {
      if (team === null) {
        await api.post('/admin/teams', payload);
      } else {
        await api.patch(`/admin/teams/${team.id}`, payload);
      }
      router.push('/teams');
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Team kon niet worden opgeslagen.');
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

  return (
    <form className="form-grid" onSubmit={submit}>
      <label>
        Code
        <input value={form.code} onChange={(event) => setForm((current) => ({ ...current, code: event.target.value.toUpperCase() }))} required />
      </label>
      <label>
        Naam
        <input value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} required />
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
          {selectableTeams.map((candidate) => (
            <label className="checkbox-card" key={candidate.id}>
              <input
                type="checkbox"
                checked={form.alertTeamIds.includes(candidate.id)}
                onChange={() => toggleAlertTeam(candidate.id)}
              />
              <span>
                <strong>{candidate.code} - {candidate.name}</strong>
              </span>
            </label>
          ))}
        </div>
      </div>
      <div className="form-grid__wide">
        <span className="field-label">Certificaten vereist voor alarmering</span>
        <p className="form-hint">Geen certificaten geselecteerd betekent dat dit team geen certificaateis heeft.</p>
        <ResourceState
          loading={certificationsLoading}
          error={certificationsError}
          empty={(certifications?.length ?? 0) === 0}
        >
          <div className="checkbox-grid">
            {certifications?.map((certification) => (
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
        <button className="secondary-button" type="button" onClick={() => router.push('/teams')}>Annuleren</button>
        <button className="primary-button" type="submit" disabled={saving}>
          {saving ? 'Opslaan...' : 'Opslaan'}
        </button>
      </div>
    </form>
  );
}

function createEmptyForm(): TeamFormState {
  return {
    code: '',
    name: '',
    isOperational: true,
    alertTeamIds: [],
    requiredCertificationIds: [],
  };
}

function formFromTeam(team: Team): TeamFormState {
  return {
    code: team.code,
    name: team.name,
    isOperational: team.is_operational,
    alertTeamIds: team.alert_teams?.map((alertTeam) => alertTeam.id) ?? [],
    requiredCertificationIds: team.required_certifications?.map((certification) => certification.id) ?? [],
  };
}
