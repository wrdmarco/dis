'use client';

import { type FormEvent, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type { Certification } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

interface CertificationFormPageProps {
  certificationId?: string;
}

interface CertificationFormState {
  code: string;
  name: string;
  description: string;
}

export function CertificationFormPage({ certificationId }: CertificationFormPageProps) {
  const isEditing = certificationId !== undefined;
  const certifications = useApiResource<Certification[]>('/certifications', isEditing);
  const certification = certificationId === undefined
    ? null
    : certifications.data?.find((candidate) => candidate.id === certificationId) ?? null;
  const formAvailable = !isEditing || certification !== null;

  return (
    <div className="page-stack">
      <Panel
        title={isEditing ? 'Certificaat aanpassen' : 'Certificaat aanmaken'}
        action={(
          <Link className="secondary-button" href="/certifications">
            <ArrowLeft size={16} /> Terug naar certificaten
          </Link>
        )}
      >
        <ResourceState
          loading={isEditing && certifications.loading}
          error={isEditing ? certifications.error : null}
          empty={isEditing && certification === null}
        >
          {formAvailable ? (
            <CertificationForm key={certificationId ?? 'new'} certification={certification} />
          ) : null}
        </ResourceState>
      </Panel>
    </div>
  );
}

function CertificationForm({ certification }: { certification: Certification | null }) {
  const router = useRouter();
  const { api } = useAuth();
  const [form, setForm] = useState<CertificationFormState>(() => (
    certification === null ? createEmptyForm() : formFromCertification(certification)
  ));
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError(null);

    const payload = {
      code: form.code,
      name: form.name,
      description: form.description || null,
    };

    try {
      if (certification === null) {
        await api.post('/certifications', payload);
      } else {
        await api.patch(`/certifications/${certification.id}`, payload);
      }
      router.push('/certifications');
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Certificaat kon niet worden opgeslagen.');
      setSaving(false);
    }
  }

  return (
    <form className="form-grid" onSubmit={submit}>
      <label>
        Code
        <input value={form.code} onChange={(event) => setForm((current) => ({ ...current, code: event.target.value }))} required />
      </label>
      <label>
        Naam
        <input value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} required />
      </label>
      <label className="form-grid__wide">
        Omschrijving
        <textarea value={form.description} onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))} />
      </label>
      {error ? <p className="form-error form-grid__wide">{error}</p> : null}
      <div className="actions-row form-grid__wide">
        <button className="secondary-button" type="button" onClick={() => router.push('/certifications')}>Annuleren</button>
        <button className="primary-button" type="submit" disabled={saving}>
          {saving ? 'Opslaan...' : 'Opslaan'}
        </button>
      </div>
    </form>
  );
}

function createEmptyForm(): CertificationFormState {
  return {
    code: '',
    name: '',
    description: '',
  };
}

function formFromCertification(certification: Certification): CertificationFormState {
  return {
    code: certification.code,
    name: certification.name,
    description: certification.description ?? '',
  };
}
