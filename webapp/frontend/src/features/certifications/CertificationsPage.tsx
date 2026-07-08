import { FormEvent, useEffect, useState } from 'react';
import { Pencil, Plus, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { Certification, UserCertification } from '../../types/api';

interface CertificationFormState {
  code: string;
  name: string;
  description: string;
  isRequiredForDispatch: boolean;
}

const emptyForm: CertificationFormState = {
  code: '',
  name: '',
  description: '',
  isRequiredForDispatch: true,
};

export function CertificationsPage() {
  const { api, hasPermission } = useAuth();
  const certifications = useApiResource<Certification[]>('/certifications');
  const [modalMode, setModalMode] = useState<'create' | 'edit' | null>(null);
  const [editingCertification, setEditingCertification] = useState<Certification | null>(null);
  const [form, setForm] = useState<CertificationFormState>(emptyForm);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const userCertifications = flattenUserCertifications(certifications.data ?? []);
  const canManageCertifications = hasPermission('certifications.manage');

  useEffect(() => {
    if (modalMode === null) {
      setForm(emptyForm);
      setEditingCertification(null);
      setError(null);
    }
  }, [modalMode]);

  function openCreateModal() {
    setEditingCertification(null);
    setForm(emptyForm);
    setError(null);
    setModalMode('create');
  }

  function openEditModal(certification: Certification) {
    setEditingCertification(certification);
    setForm({
      code: certification.code,
      name: certification.name,
      description: certification.description ?? '',
      isRequiredForDispatch: certification.is_required_for_dispatch,
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
      description: form.description || null,
      is_required_for_dispatch: form.isRequiredForDispatch,
    };

    try {
      if (modalMode === 'edit' && editingCertification !== null) {
        await api.patch(`/certifications/${editingCertification.id}`, payload);
      } else {
        await api.post('/certifications', payload);
      }
      setModalMode(null);
      await certifications.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Certificaat kon niet worden opgeslagen.');
    } finally {
      setSaving(false);
    }
  }

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
          <button className="primary-button" type="button" onClick={openCreateModal}>
            <Plus size={16} /> Certificaat aanmaken
          </button>
        ) : null}
      >
        <ResourceState loading={certifications.loading} error={certifications.error} empty={(certifications.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th scope="col">Code</th><th scope="col">Naam</th><th scope="col">Gekoppelde gebruikers</th><th scope="col">Dispatch</th><th scope="col">Actie</th></tr></thead>
            <tbody>
              {certifications.data?.map((certification) => (
                <tr key={certification.id}>
                  <td>{certification.code}</td>
                  <td>{certification.name}</td>
                  <td>{certificationUsers(certification)}</td>
                  <td><StatusPill value={certification.is_required_for_dispatch ? 'required' : 'optional'} tone={certification.is_required_for_dispatch ? 'warn' : 'neutral'} /></td>
                  <td>
                    {canManageCertifications ? (
                      <button className="secondary-button" type="button" onClick={() => openEditModal(certification)}>
                        <Pencil size={16} /> Aanpassen
                      </button>
                    ) : '-'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>

      {modalMode !== null && canManageCertifications ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal" role="dialog" aria-modal="true" aria-labelledby="certification-modal-title">
            <header className="modal__header">
              <h2 id="certification-modal-title">{modalMode === 'edit' ? 'Certificaat aanpassen' : 'Certificaat aanmaken'}</h2>
              <button className="icon-button" type="button" onClick={() => setModalMode(null)} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <form className="form-grid" onSubmit={submit}>
              <label>
                Code
                <input value={form.code} onChange={(event) => setForm((current) => ({ ...current, code: event.target.value }))} required />
              </label>
              <label>
                Naam
                <input value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} required />
              </label>
              <label className="check-label">
                <input
                  type="checkbox"
                  checked={form.isRequiredForDispatch}
                  onChange={(event) => setForm((current) => ({ ...current, isRequiredForDispatch: event.target.checked }))}
                />
                Dispatch vereist
              </label>
              <label className="form-grid__wide">
                Omschrijving
                <textarea value={form.description} onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))} />
              </label>
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
