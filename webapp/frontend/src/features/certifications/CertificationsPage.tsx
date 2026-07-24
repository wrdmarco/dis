import Link from 'next/link';
import { Pencil, Plus } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { Certification, UserCertification } from '../../types/api';

export function CertificationsPage() {
  const { hasPermission } = useAuth();
  const certifications = useApiResource<Certification[]>('/certifications');
  const userCertifications = flattenUserCertifications(certifications.data ?? []);
  const canManageCertifications = hasPermission('certifications.manage');

  return (
    <div className="page-stack">
      <Panel title="Gebruikerscertificaten">
        <ResourceState loading={certifications.loading} error={certifications.error} empty={userCertifications.length === 0}>
          <table className="data-table">
            <thead><tr><th scope="col">Gebruiker</th><th scope="col">Certificaat</th><th scope="col">Status</th><th scope="col">Nummer</th><th scope="col">Verloopt</th></tr></thead>
            <tbody>
              {userCertifications.map(({ certification, userCertification }) => (
                <tr key={userCertification.id}>
                  <td>{userCertification.user?.name ?? userCertification.user?.email ?? '-'}</td>
                  <td>{certification.name}</td>
                  <td><StatusPill value={userCertification.status} tone={userCertification.status === 'active' ? 'good' : 'warn'} /></td>
                  <td>{userCertification.certificate_number ?? '-'}</td>
                  <td>{userCertification.expires_at ?? '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>

      <Panel
        title="Certificaatsoorten"
        action={canManageCertifications ? (
          <Link className="primary-button" href="/certifications/new">
            <Plus size={16} /> Certificaat aanmaken
          </Link>
        ) : null}
      >
        <ResourceState loading={certifications.loading} error={certifications.error} empty={(certifications.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th scope="col">Code</th><th scope="col">Naam</th><th scope="col">Gekoppelde gebruikers</th><th scope="col">Actie</th></tr></thead>
            <tbody>
              {certifications.data?.map((certification) => (
                <tr key={certification.id}>
                  <td>{certification.code}</td>
                  <td>{certification.name}</td>
                  <td>{certificationUsers(certification)}</td>
                  <td>
                    {canManageCertifications ? (
                      <Link className="secondary-button" href={`/certifications/${certification.id}/edit`}>
                        <Pencil size={16} /> Aanpassen
                      </Link>
                    ) : '-'}
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

function certificationUsers(certification: Certification): string {
  const users = certification.user_certifications
    ?.map((userCertification) => userCertification.user?.name ?? userCertification.user?.email ?? null)
    .filter((value): value is string => value !== null && value !== undefined && value !== '') ?? [];

  if (users.length === 0) {
    return '-';
  }

  return users.join(', ');
}

function flattenUserCertifications(certifications: Certification[]): Array<{ certification: Certification; userCertification: UserCertification }> {
  return certifications.flatMap((certification) => (
    certification.user_certifications?.map((userCertification) => ({ certification, userCertification })) ?? []
  ));
}
