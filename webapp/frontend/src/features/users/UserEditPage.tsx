'use client';

import { type FormEvent, useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type { Role, Team, User } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { UserForm, userFormFromUser, userFormPayload, type UserFormState } from './UserForm';

export function UserEditPage({ userId }: { userId: string }) {
  const router = useRouter();
  const { api, hasPermission, user: currentUser } = useAuth();
  const canManageRoles = hasPermission('roles.manage');
  const canManageTeams = hasPermission('teams.manage');
  const canManageCredentials = hasPermission('users.credentials.manage');
  const user = useApiResource<User>(`/users/${userId}`, Boolean(userId));
  const roles = useApiResource<Role[]>('/admin/roles', canManageRoles);
  const teams = useApiResource<Team[]>('/admin/teams', canManageTeams);
  const [form, setForm] = useState<UserFormState | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const isSystemAdministrator = currentUser?.roles?.some((role) => role.name === 'system-administrator') ?? false;
  const detailPath = `/users/${userId}`;

  useEffect(() => {
    if (user.data !== null) {
      setForm(userFormFromUser(user.data));
    }
  }, [user.data]);

  async function updateUser(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (form === null) {
      return;
    }

    setSaving(true);
    setError(null);

    try {
      await api.patch(`/users/${userId}`, userFormPayload(form, 'edit', canManageCredentials));
      router.push(detailPath);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Gebruiker kon niet worden opgeslagen.');
      setSaving(false);
    }
  }

  return (
    <div className="page-stack">
      <Panel
        title="Gebruiker aanpassen"
        action={(
          <Link className="secondary-button" href={detailPath}>
            <ArrowLeft size={16} /> Terug naar gebruiker
          </Link>
        )}
      >
        <ResourceState
          loading={user.loading || (user.data !== null && form === null)}
          error={user.error}
          empty={user.data === null}
        >
          {form !== null ? (
            <UserForm
              mode="edit"
              form={form}
              roles={roles.data ?? []}
              rolesLoading={roles.loading}
              rolesError={roles.error}
              teams={teams.data ?? []}
              teamsLoading={teams.loading}
              teamsError={teams.error}
              canManageRoles={canManageRoles}
              canManageTeams={canManageTeams}
              canManageCredentials={canManageCredentials}
              isSystemAdministrator={isSystemAdministrator}
              saving={saving}
              error={error}
              submitLabel="Wijzigingen opslaan"
              onChange={(updater) => setForm((current) => current === null
                ? current
                : typeof updater === 'function' ? updater(current) : updater)}
              onCancel={() => router.push(detailPath)}
              onSubmit={updateUser}
            />
          ) : null}
        </ResourceState>
      </Panel>
    </div>
  );
}
