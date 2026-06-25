import { FormEvent, useEffect, useState } from 'react';
import { Pencil, Plus, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { Certification } from '../../types/api';

interface CertificationFormState {
  code: string;
  name: string;
  description: string;
  isRequiredForDispatch: boolean;
  warningDaysBeforeExpiry: string;
}

const emptyForm: CertificationFormState = {
  code: '',
  name: '',
  description: '',
  isRequiredForDispatch: true,
  warningDaysBeforeExpiry: '30',
};

export function CertificationsPage() {
  const { api } = useAuth();
  const certifications = useApiResource<Certification[]>('/certifications');
  const [modalMode, setModalMode] = useState<'create' | 'edit' | null>(null);
  const [editingCertification, setEditingCertification] = useState<Certification | null>(null);
  const [form, setForm] = useState<CertificationFormState>(emptyForm);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

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
      warningDaysBeforeExpiry: String(certification.warning_days_before_expiry),
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
      warning_days_before_expiry: Number(form.warningDaysBeforeExpiry || 30),
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
      <Panel
        title="Certificeringen"
        action={(
          <button className="primary-button" type="button" onClick={openCreateModal}>
            <Plus size={16} /> Certificaat aanmaken
          </button>
        )}
      >
        <ResourceState loading={certifications.loading} error={certifications.error} empty={(certifications.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Code</th><th>Naam</th><th>Dispatch</th><th>Waarschuwing</th><th>Actie</th></tr></thead>
            <tbody>
              {certifications.data?.map((certification) => (
                <tr key={certification.id}>
                  <td>{certification.code}</td>
                  <td>{certification.name}</td>
                  <td><StatusPill value={certification.is_required_for_dispatch ? 'required' : 'optional'} tone={certification.is_required_for_dispatch ? 'warn' : 'neutral'} /></td>
                  <td>{certification.warning_days_before_expiry} dagen</td>
                  <td>
                    <button className="secondary-button" type="button" onClick={() => openEditModal(certification)}>
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
              <label>
                Waarschuwingstermijn
                <input
                  type="number"
                  min="1"
                  max="365"
                  value={form.warningDaysBeforeExpiry}
                  onChange={(event) => setForm((current) => ({ ...current, warningDaysBeforeExpiry: event.target.value }))}
                  required
                />
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
