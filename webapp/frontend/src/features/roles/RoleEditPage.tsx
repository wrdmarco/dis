'use client';

import { type FormEvent, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type { Permission, Role } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { createRoleFormFromRole, RoleForm, rolePayload, type RoleFormState } from './RoleForm';

export function RoleEditPage({ roleId }: { roleId: string }) {
  const roles = useApiResource<Role[]>('/admin/roles');
  const permissions = useApiResource<Permission[]>('/admin/permissions');
  const role = roles.data?.find((candidate) => candidate.id === roleId) ?? null;
  const protectedRole = role?.name === 'system-administrator';
  const roleNotFound = !roles.loading && roles.error === null && roles.data !== null && role === null;
  const pageError = roles.error
    ?? (protectedRole ? 'De system administrator rol mag niet worden aangepast.' : null)
    ?? (roleNotFound ? 'Rol niet gevonden.' : null);

  return (
    <div className="page-stack">
      <Panel
        title="Rol aanpassen"
        action={(
          <Link className="secondary-button" href="/roles">
            <ArrowLeft size={16} /> Terug naar rollen
          </Link>
        )}
      >
        <ResourceState
          loading={roles.loading}
          error={pageError}
          empty={false}
        >
          {role !== null && !protectedRole ? (
            <RoleEditor
              key={role.id}
              role={role}
              permissions={permissions.data ?? []}
              permissionsLoading={permissions.loading}
              permissionsError={permissions.error}
            />
          ) : null}
        </ResourceState>
      </Panel>
    </div>
  );
}

function RoleEditor({
  role,
  permissions,
  permissionsLoading,
  permissionsError,
}: {
  role: Role;
  permissions: Permission[];
  permissionsLoading: boolean;
  permissionsError: string | null;
}) {
  const router = useRouter();
  const { api } = useAuth();
  const [form, setForm] = useState<RoleFormState>(() => createRoleFormFromRole(role));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function updateRole(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (role.name === 'system-administrator') {
      setError('De system administrator rol mag niet worden aangepast.');
      return;
    }

    setSaving(true);
    setError(null);

    try {
      await api.patch<Role>(`/admin/roles/${role.id}`, rolePayload(form));
      router.push('/roles');
    } catch (requestError) {
      setError(requestError instanceof ApiClientError ? requestError.message : 'Rol opslaan mislukt.');
      setSaving(false);
    }
  }

  return (
    <RoleForm
      form={form}
      permissions={permissions}
      permissionsLoading={permissionsLoading}
      permissionsError={permissionsError}
      saving={saving}
      error={error}
      submitLabel="Rol opslaan"
      onCancel={() => router.push('/roles')}
      onSubmit={updateRole}
      onChange={setForm}
    />
  );
}
