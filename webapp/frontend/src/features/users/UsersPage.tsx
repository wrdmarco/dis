'use client';

import Link from 'next/link';
import { Eye, Plus } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { activeOperatorDeviceCount, onlineOperatorDeviceCount, reachableOperatorDeviceCount } from '../../lib/devicePresence';
import { locationLabel } from '../../lib/profileLocation';
import { useApiResource } from '../../lib/useApiResource';
import type { User } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

export function UsersPage() {
  const { hasPermission } = useAuth();
  const users = useApiResource<User[]>('/users');
  const canManageUsers = hasPermission('users.manage');

  return (
    <div className="page-stack">
      <Panel
        title="Gebruikers"
        action={canManageUsers ? (
          <Link className="primary-button" href="/users/new">
            <Plus size={16} /> Gebruiker aanmaken
          </Link>
        ) : null}
      >
        <ResourceState loading={users.loading} error={users.error} empty={(users.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead>
              <tr>
                <th scope="col">Naam</th>
                <th scope="col">E-mail</th>
                <th scope="col">Locatie</th>
                <th scope="col">Account</th>
                <th scope="col">Bereikbaarheid</th>
                <th scope="col">Push</th>
                <th scope="col">Teams</th>
                <th scope="col">Rollen</th>
                <th scope="col">Actie</th>
              </tr>
            </thead>
            <tbody>
              {users.data?.map((user) => {
                const onlineDevices = onlineOperatorDeviceCount(user.fcm_tokens ?? []);
                const reachableDevices = reachableOperatorDeviceCount(user.fcm_tokens ?? []);
                const activeDevices = activeOperatorDeviceCount(user.fcm_tokens ?? []);
                const presence = onlineDevices > 0
                  ? { label: `Online (${onlineDevices})`, tone: 'good' as const }
                  : reachableDevices > 0
                    ? { label: `Stand-by (${reachableDevices})`, tone: 'neutral' as const }
                    : { label: 'Offline', tone: 'bad' as const };

                return (
                  <tr key={user.id}>
                    <td>
                      <Link href={`/users/${user.id}`}>{user.name}</Link>
                    </td>
                    <td>{user.email}</td>
                    <td>{locationLabel(user.home_city, user.home_region, user.home_country)}</td>
                    <td><StatusPill value={user.account_status} tone={user.account_status === 'active' ? 'good' : 'bad'} /></td>
                    <td><StatusPill value={presence.label} tone={presence.tone} /></td>
                    <td>{user.push_enabled ? `Actief (${activeDevices}/${user.max_operator_devices ?? 1})` : 'Uit'}</td>
                    <td>{user.teams?.map((team) => team.code).join(', ') || '-'}</td>
                    <td>{user.roles?.map((role) => role.display_name).join(', ') || '-'}</td>
                    <td>
                      <div className="table-actions">
                        <Link className="secondary-button" href={`/users/${user.id}`}>
                          <Eye size={16} /> Details
                        </Link>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
    </div>
  );
}
