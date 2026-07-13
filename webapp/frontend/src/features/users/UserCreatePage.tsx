'use client';

import { type FormEvent, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type { Role, Team, User } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { createEmptyUserForm, UserForm, userFormPayload, type UserFormState } from './UserForm';

export function UserCreatePage() {
  const router = useRouter();
  const { api, hasPermission, user: currentUser } = useAuth();
  const canManageRoles = hasPermission('roles.manage');
  const canManageTeams = hasPermission('teams.manage');
  const roles = useApiResource<Role[]>('/admin/roles', canManageRoles);
  const teams = useApiResource<Team[]>('/admin/teams', canManageTeams);
  const [form, setForm] = useState<UserFormState>(createEmptyUserForm);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const isSystemAdministrator = currentUser?.roles?.some((role) => role.name === 'system-administrator') ?? false;

  async function createUser(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError(null);

    try {
      const response = await api.post<User>('/users', userFormPayload(form, 'create'));
      router.push(`/users/${response.data.id}`);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Gebruiker kon niet worden opgeslagen.');
      setSaving(false);
    }
  }

  return (
    <div className="page-stack">
      <Panel
        title="Gebruiker aanmaken"
        action={(
          <Link className="secondary-button" href="/users">
            <ArrowLeft size={16} /> Terug naar gebruikers
          </Link>
        )}
      >
        <UserForm
          mode="create"
          form={form}
          roles={roles.data ?? []}
          rolesLoading={roles.loading}
          rolesError={roles.error}
          teams={teams.data ?? []}
          teamsLoading={teams.loading}
          teamsError={teams.error}
          canManageRoles={canManageRoles}
          canManageTeams={canManageTeams}
          canManageCredentials
          isSystemAdministrator={isSystemAdministrator}
          saving={saving}
          error={error}
          submitLabel="Gebruiker aanmaken"
          onChange={setForm}
          onCancel={() => router.push('/users')}
          onSubmit={createUser}
        />
      </Panel>
    </div>
  );
}
