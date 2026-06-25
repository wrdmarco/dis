import { FormEvent, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { Certification } from '../../types/api';

export function CertificationsPage() {
  const { api } = useAuth();
  const certifications = useApiResource<Certification[]>('/certifications');
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [required, setRequired] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    try {
      await api.post('/certifications', { code, name, is_required_for_dispatch: required, warning_days_before_expiry: 30 });
      setCode('');
      setName('');
      await certifications.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Certificering kon niet worden aangemaakt.');
    }
  };

  return (
    <div className="page-stack">
      <Panel title="Certificeringstype">
        <form className="inline-form" onSubmit={submit}>
          <input value={code} onChange={(event) => setCode(event.target.value)} placeholder="Code" required />
          <input value={name} onChange={(event) => setName(event.target.value)} placeholder="Naam" required />
          <label className="check-label">
            <input type="checkbox" checked={required} onChange={(event) => setRequired(event.target.checked)} />
            Dispatch vereist
          </label>
          <button className="primary-button" type="submit">Opslaan</button>
        </form>
        {error && <p className="form-error">{error}</p>}
      </Panel>
      <Panel title="Certificeringen">
        <ResourceState loading={certifications.loading} error={certifications.error} empty={(certifications.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Code</th><th>Naam</th><th>Dispatch</th><th>Waarschuwing</th></tr></thead>
            <tbody>
              {certifications.data?.map((certification) => (
                <tr key={certification.id}>
                  <td>{certification.code}</td>
                  <td>{certification.name}</td>
                  <td><StatusPill value={certification.is_required_for_dispatch ? 'required' : 'optional'} tone={certification.is_required_for_dispatch ? 'warn' : 'neutral'} /></td>
                  <td>{certification.warning_days_before_expiry} dagen</td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
    </div>
  );
}

