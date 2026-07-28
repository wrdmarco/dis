import type { FormEventHandler } from 'react';
import { ResourceState } from '../../components/ResourceState';
import type { Permission, Role } from '../../types/api';

export interface RoleFormState {
  name: string;
  displayName: string;
  description: string;
  canUseOperatorApp: boolean;
  canUseAdminApp: boolean;
  permissionIds: string[];
}

interface RolePayload {
  name: string;
  display_name: string;
  description: string | null;
  can_use_operator_app: boolean;
  can_use_admin_app: boolean;
  permission_ids: string[];
}

interface RoleFormProps {
  form: RoleFormState;
  permissions: Permission[];
  permissionsLoading: boolean;
  permissionsError: string | null;
  saving: boolean;
  error: string | null;
  submitLabel: string;
  onCancel: () => void;
  onSubmit: FormEventHandler<HTMLFormElement>;
  onChange: (updater: (current: RoleFormState) => RoleFormState) => void;
}

export function RoleForm({
  form,
  permissions,
  permissionsLoading,
  permissionsError,
  saving,
  error,
  submitLabel,
  onCancel,
  onSubmit,
  onChange,
}: RoleFormProps) {
  function togglePermission(permissionId: string) {
    onChange((current) => ({
      ...current,
      permissionIds: current.permissionIds.includes(permissionId)
        ? current.permissionIds.filter((id) => id !== permissionId)
        : [...current.permissionIds, permissionId],
    }));
  }

  return (
    <form className="form-grid" onSubmit={onSubmit} aria-busy={saving}>
      <label>
        Rolcode
        <input
          value={form.name}
          maxLength={120}
          placeholder="bijv. drone-operator"
          required
          onChange={(event) => onChange((current) => ({ ...current, name: slugRoleName(event.target.value) }))}
        />
      </label>
      <label>
        Weergavenaam
        <input
          value={form.displayName}
          maxLength={160}
          required
          onChange={(event) => onChange((current) => ({ ...current, displayName: event.target.value }))}
        />
      </label>
      <label className="form-grid__wide">
        Omschrijving
        <textarea
          value={form.description}
          maxLength={2000}
          onChange={(event) => onChange((current) => ({ ...current, description: event.target.value }))}
        />
      </label>
      <label className="check-label">
        <input
          type="checkbox"
          checked={form.canUseOperatorApp}
          onChange={(event) => onChange((current) => ({ ...current, canUseOperatorApp: event.target.checked }))}
        />
        Operator-app toestaan
      </label>
      <label className="check-label">
        <input
          type="checkbox"
          checked={form.canUseAdminApp}
          onChange={(event) => onChange((current) => ({ ...current, canUseAdminApp: event.target.checked }))}
        />
        Webbeheer toestaan
      </label>

      <div className="form-grid__wide">
        <ResourceState loading={permissionsLoading} error={permissionsError} empty={permissions.length === 0}>
          <div className="metadata-example">
            <strong>Standaard toegang</strong>
            <p>Eigen profiel bekijken en waar toegestaan eigen profielgegevens wijzigen is voor iedere ingelogde gebruiker beschikbaar. Kies hieronder alleen extra rechten voor beheer, inzetten, alarmering en systeemfuncties.</p>
            <p>MFA staat niet per rol. Gebruik de globale MFA-schakelaar bij Admin onder MFA en wachtwoordeisen.</p>
            <p>Let op bij inzetten en instellingen: alarmeren staat los van inzetgegevens beheren, en push tokens beheren staat los van handmatige pushmeldingen versturen.</p>
            <p>Actierechten voor Agenda en Verzoeken werken alleen samen met het bijbehorende bekijkrecht.</p>
          </div>
          <div className="permission-category-list">
            {permissionGroups(permissions).map((group) => (
              <section className="permission-category" key={group.category}>
                <header>
                  <h3>{permissionCategoryLabel(group.category)}</h3>
                  <span>{group.permissions.length} rechten</span>
                </header>
                {permissionCategoryDescription(group.category) ? (
                  <p className="muted-text">{permissionCategoryDescription(group.category)}</p>
                ) : null}
                <div className="permission-grid">
                  {group.permissions.map((permission) => (
                    <label className="checkbox-card permission-card" key={permission.id}>
                      <input
                        type="checkbox"
                        checked={form.permissionIds.includes(permission.id)}
                        onChange={() => togglePermission(permission.id)}
                      />
                      <span>
                        <strong>{permission.display_name}</strong>
                        <small>{permission.description ?? permission.name}</small>
                        <code>{permission.name}</code>
                      </span>
                    </label>
                  ))}
                </div>
              </section>
            ))}
          </div>
        </ResourceState>
      </div>

      {error ? <p className="form-error form-grid__wide">{error}</p> : null}
      <div className="actions-row form-grid__wide">
        <button className="secondary-button" type="button" onClick={onCancel} disabled={saving}>
          Annuleren
        </button>
        <button
          className="primary-button"
          type="submit"
          disabled={saving || permissionsLoading || permissionsError !== null || form.name.trim() === '' || form.displayName.trim() === ''}
        >
          {saving ? 'Opslaan...' : submitLabel}
        </button>
      </div>
    </form>
  );
}

export function createEmptyRoleForm(): RoleFormState {
  return {
    name: '',
    displayName: '',
    description: '',
    canUseOperatorApp: true,
    canUseAdminApp: false,
    permissionIds: [],
  };
}

export function createRoleFormFromRole(role: Role): RoleFormState {
  return {
    name: role.name,
    displayName: role.display_name,
    description: role.description ?? '',
    canUseOperatorApp: role.can_use_operator_app,
    canUseAdminApp: role.can_use_admin_app,
    permissionIds: role.permissions?.map((permission) => permission.id) ?? [],
  };
}

export function rolePayload(form: RoleFormState): RolePayload {
  return {
    name: form.name.trim(),
    display_name: form.displayName.trim(),
    description: form.description.trim() === '' ? null : form.description.trim(),
    can_use_operator_app: form.canUseOperatorApp,
    can_use_admin_app: form.canUseAdminApp,
    permission_ids: form.permissionIds,
  };
}

function slugRoleName(value: string): string {
  return value
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/-{2,}/g, '-')
    .replace(/^-+/, '');
}

function permissionGroups(permissions: Permission[]): Array<{ category: string; permissions: Permission[] }> {
  const groups = new Map<string, Permission[]>();
  for (const permission of permissions) {
    const category = permission.category || 'other';
    groups.set(category, [...(groups.get(category) ?? []), permission]);
  }

  return Array.from(groups.entries())
    .map(([category, items]) => ({
      category,
      permissions: [...items].sort((left, right) => left.display_name.localeCompare(right.display_name)),
    }))
    .sort((left, right) => permissionCategoryLabel(left.category).localeCompare(permissionCategoryLabel(right.category)));
}

function permissionCategoryLabel(category: string): string {
  switch (category) {
    case 'user_management':
      return 'Gebruikers';
    case 'address_book':
      return 'Adresboek';
    case 'role_management':
      return 'Rollen en rechten';
    case 'team_management':
      return 'Teams';
    case 'deployment_management':
      return 'Inzetten';
    case 'dispatch_management':
      return 'Inzetalarmering';
    case 'status_management':
      return 'Operationele status';
    case 'vacation_management':
      return 'Vakantieplanning';
    case 'calendar_management':
      return 'Agenda';
    case 'product_request_management':
      return 'Verzoeken';
    case 'asset_management':
      return 'Middelen';
    case 'certification_management':
      return 'Certificaten';
    case 'expiry_management':
      return 'Verloop';
    case 'form_configuration':
      return 'Formulieren';
    case 'weather_configuration':
      return 'Weer en KNMI';
    case 'branding_configuration':
      return 'Branding';
    case 'audit_log_access':
      return 'Audit';
    case 'update_management':
      return 'Updates';
    case 'push_management':
      return 'Pushmeldingen';
    case 'system_configuration':
      return 'Instellingen en systeem';
    default:
      return category;
  }
}

function permissionCategoryDescription(category: string): string | null {
  switch (category) {
    case 'deployment_management':
      return 'Inzetregistratie, inzetstatus en alarmering staan bij elkaar, maar blijven aparte rechten zodat iemand niet automatisch mag alarmeren omdat hij inzetgegevens mag aanpassen.';
    case 'system_configuration':
      return 'Technische systeemfuncties, backups en pushbeheer zijn apart te verlenen. Push tokens intrekken is los van handmatige pushmeldingen versturen.';
    case 'expiry_management':
      return 'Toegang tot het centrale verloopoverzicht staat los van toegang tot afzonderlijke middelen- en certificaatgegevens.';
    case 'vacation_management':
      return 'Eigen periodes plannen blijft voor iedere gebruiker beschikbaar. Deze rechten bepalen alleen wie de vakantieplanning van anderen mag bekijken of beheren.';
    case 'calendar_management':
      return 'Agenda bekijken, jezelf inschrijven, agenda-items beheren, deelnemers inzien/beheren en agendagroepen beheren zijn afzonderlijke rechten.';
    case 'product_request_management':
      return 'Bepaal afzonderlijk wie verzoeken kan lezen, indienen, eigen open verzoeken kan wijzigen, ieder niet-afgesloten verzoek inhoudelijk kan aanpassen en verzoeken kan afhandelen.';
    case 'form_configuration':
      return 'Beheer de dynamische inzet- en rapportformulieren zonder toegang tot overige systeeminstellingen.';
    case 'weather_configuration':
      return 'Toegang tot Weer en UAV Forecast staat los van het technische beheer van KNMI-bronnen en datasets.';
    case 'branding_configuration':
      return 'Beheer huisstijl, logo en berichttemplates zonder toegang tot technische systeeminstellingen.';
    default:
      return null;
  }
}
