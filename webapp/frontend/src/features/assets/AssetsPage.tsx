import { FormEvent, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { Asset } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

export function AssetsPage() {
  const { api } = useAuth();
  const assets = useApiResource<Asset[]>('/assets');
  const [assetTag, setAssetTag] = useState('');
  const [name, setName] = useState('');
  const [type, setType] = useState('drone');
  const [error, setError] = useState<string | null>(null);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    try {
      await api.post('/assets', { asset_tag: assetTag, name, type, status: 'ready' });
      setAssetTag('');
      setName('');
      await assets.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Asset kon niet worden aangemaakt.');
    }
  };

  return (
    <div className="page-stack">
      <RealtimeBridge onOperationalEvent={() => void assets.reload()} />
      <Panel title="Asset registreren">
        <form className="inline-form" onSubmit={submit}>
          <input value={assetTag} onChange={(event) => setAssetTag(event.target.value)} placeholder="Asset tag" required />
          <input value={name} onChange={(event) => setName(event.target.value)} placeholder="Naam" required />
          <select value={type} onChange={(event) => setType(event.target.value)}>
            <option value="drone">Drone</option>
            <option value="battery">Battery</option>
            <option value="sensor">Sensor</option>
            <option value="vehicle">Vehicle</option>
            <option value="support_equipment">Support equipment</option>
          </select>
          <button className="primary-button" type="submit">Registreren</button>
        </form>
        {error && <p className="form-error">{error}</p>}
      </Panel>
      <Panel title="Assets">
        <ResourceState loading={assets.loading} error={assets.error} empty={(assets.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Tag</th><th>Naam</th><th>Type</th><th>Status</th><th>Onderhoud</th></tr></thead>
            <tbody>
              {assets.data?.map((asset) => (
                <tr key={asset.id}>
                  <td>{asset.asset_tag}</td>
                  <td>{asset.name}</td>
                  <td>{asset.type}</td>
                  <td><StatusPill value={asset.status} tone={asset.status === 'ready' ? 'good' : asset.status === 'maintenance' ? 'warn' : 'neutral'} /></td>
                  <td>{asset.maintenance_due_at ?? '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
    </div>
  );
}
