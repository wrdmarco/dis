import Link from 'next/link';
import { Pencil, Plus } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { useApiResource } from '../../lib/useApiResource';
import type { Team } from '../../types/api';

export function TeamsPage() {
  const teams = useApiResource<Team[]>('/admin/teams');

  return (
    <div className="page-stack">
      <Panel
        title="Teams"
        action={(
          <Link className="primary-button" href="/teams/new">
            <Plus size={16} /> Team aanmaken
          </Link>
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
                    <Link className="secondary-button" href={`/teams/${team.id}/edit`}>
                      <Pencil size={16} /> Aanpassen
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
    </div>
  );
}
