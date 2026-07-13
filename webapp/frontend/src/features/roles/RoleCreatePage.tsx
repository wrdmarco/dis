'use client';

import { type FormEvent, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type { Permission, Role } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { createEmptyRoleForm, RoleForm, rolePayload, type RoleFormState } from './RoleForm';

export function RoleCreatePage() {
  const router = useRouter();
  const { api } = useAuth();
  const permissions = useApiResource<Permission[]>('/admin/permissions');
  const [form, setForm] = useState<RoleFormState>(createEmptyRoleForm);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function createRole(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError(null);

    try {
      await api.post<Role>('/admin/roles', rolePayload(form));
      router.push('/roles');
    } catch (requestError) {
      setError(requestError instanceof ApiClientError ? requestError.message : 'Rol opslaan mislukt.');
      setSaving(false);
    }
  }

  return (
    <div className="page-stack">
      <Panel
        title="Rol toevoegen"
        action={(
          <Link className="secondary-button" href="/roles">
            <ArrowLeft size={16} /> Terug naar rollen
          </Link>
        )}
      >
        <RoleForm
          form={form}
          permissions={permissions.data ?? []}
          permissionsLoading={permissions.loading}
          permissionsError={permissions.error}
          saving={saving}
          error={error}
          submitLabel="Rol toevoegen"
          onCancel={() => router.push('/roles')}
          onSubmit={createRole}
          onChange={setForm}
        />
      </Panel>
    </div>
  );
}
