'use client';

import { useState } from 'react';
import Link from 'next/link';
import { Pencil, Plus } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { droneTypeLabel } from '../../lib/droneTypes';
import { useApiResource } from '../../lib/useApiResource';
import type { Asset, DroneType } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

export function AssetsPage() {
  const { api, hasPermission } = useAuth();
  const assets = useApiResource<Asset[]>('/assets');
  const droneTypes = useApiResource<DroneType[]>('/drone-types');
  const [error, setError] = useState<string | null>(null);
  const [deletingDroneTypeId, setDeletingDroneTypeId] = useState<string | null>(null);
  const assetList = assets.data ?? [];
  const linkedAssets = assetList.filter((asset) => asset.active_assignment?.user !== undefined && asset.active_assignment?.user !== null);
  const unlinkedAssets = assetList.filter((asset) => asset.active_assignment?.user === undefined || asset.active_assignment?.user === null);
  const linkedDrones = linkedAssets.filter((asset) => asset.type === 'drone');
  const maintenanceAssets = assetList.filter((asset) => asset.status === 'maintenance');
  const unavailableAssets = assetList.filter((asset) => asset.status === 'unavailable' || asset.status === 'retired');
  const canManageAssets = hasPermission('assets.manage');

  async function deleteDroneType(droneType: DroneType) {
    if (!window.confirm(`${droneType.model} verwijderen?`)) {
      return;
    }

    setDeletingDroneTypeId(droneType.id);
    setError(null);
    try {
      await api.delete(`/admin/drone-types/${droneType.id}`);
      await droneTypes.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Drone type kon niet worden verwijderd.');
    } finally {
      setDeletingDroneTypeId(null);
    }
  }

  return (
    <div className="page-stack">
      <RealtimeBridge onOperationalEvent={() => void assets.reload()} />

      <Panel title="Gebruikersassets">
        <ResourceState loading={assets.loading} error={assets.error} empty={assetList.length === 0}>
          <div className="summary-grid">
            <SummaryItem label="Totaal assets" value={assetList.length} />
            <SummaryItem label="Gekoppeld aan gebruiker" value={linkedAssets.length} />
            <SummaryItem label="Nog niet gekoppeld" value={unlinkedAssets.length} />
            <SummaryItem label="Gekoppelde drones" value={linkedDrones.length} />
            <SummaryItem label="Onderhoud" value={maintenanceAssets.length} />
            <SummaryItem label="Niet inzetbaar" value={unavailableAssets.length} />
          </div>
        </ResourceState>
      </Panel>

      <Panel
        title="Assets per gebruiker"
        action={canManageAssets ? (
          <Link className="primary-button" href="/assets/new">
            <Plus size={16} /> Asset registreren
          </Link>
        ) : null}
      >
        <ResourceState loading={assets.loading} error={assets.error} empty={(assets.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th scope="col">Gebruiker</th><th scope="col">Asset</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Serienummer</th><th scope="col">Onderhoud</th><th scope="col">Actie</th></tr></thead>
            <tbody>
              {assets.data?.map((asset) => (
                <tr key={asset.id}>
                  <td>{asset.active_assignment?.user?.name ?? asset.active_assignment?.user?.email ?? 'Nog niet gekoppeld'}</td>
                  <td>{asset.name}</td>
                  <td>{asset.drone_type ? droneTypeLabel(asset.drone_type) : asset.type}</td>
                  <td><StatusPill value={asset.status} tone={asset.status === 'ready' ? 'good' : asset.status === 'maintenance' ? 'warn' : 'neutral'} /></td>
                  <td>{asset.serial_number ?? '-'}</td>
                  <td>{asset.maintenance_due_at ?? '-'}</td>
                  <td>
                    {canManageAssets ? (
                      <Link className="secondary-button" href={`/assets/${asset.id}/edit`}>
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

      <Panel
        title="Drone types"
        action={canManageAssets ? (
          <Link className="primary-button" href="/assets/drone-types/new">
            <Plus size={16} /> Drone type toevoegen
          </Link>
        ) : null}
      >
        {error ? <p className="form-error">{error}</p> : null}
        <ResourceState loading={droneTypes.loading} error={droneTypes.error} empty={(droneTypes.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th scope="col">Merk</th><th scope="col">Model</th><th scope="col">Thermal</th><th scope="col">Externe lamp</th><th scope="col">Speaker</th><th scope="col">Status</th><th scope="col">Actie</th></tr></thead>
            <tbody>
              {droneTypes.data?.map((type) => (
                <tr key={type.id}>
                  <td>{type.manufacturer}</td>
                  <td>{type.model}</td>
                  <td>{type.has_thermal ? 'Ja' : 'Nee'}</td>
                  <td>{type.has_spotlight ? 'Ja' : 'Nee'}</td>
                  <td>{type.has_speaker ? 'Ja' : 'Nee'}</td>
                  <td>{type.is_active ? 'Actief' : 'Uitgeschakeld'}</td>
                  <td>
                    <div className="actions-row">
                      {canManageAssets ? (
                        <>
                          <Link className="secondary-button" href={`/assets/drone-types/${type.id}/edit`}>
                            <Pencil size={16} /> Aanpassen
                          </Link>
                          <button
                            className="secondary-button"
                            type="button"
                            onClick={() => void deleteDroneType(type)}
                            disabled={deletingDroneTypeId !== null}
                          >
                            Verwijderen
                          </button>
                        </>
                      ) : '-'}
                    </div>
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

function SummaryItem({ label, value }: { label: string; value: number }) {
  return (
    <div>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}
