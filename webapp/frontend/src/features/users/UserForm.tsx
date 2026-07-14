'use client';

import type { Dispatch, FormEventHandler, SetStateAction } from 'react';
import { ResourceState } from '../../components/ResourceState';
import { countryOptions, regionOptionsForCountry } from '../../lib/profileLocation';
import type { Role, Team, User } from '../../types/api';

export type UserFormMode = 'create' | 'edit';

export interface UserFormState {
  firstName: string;
  lastName: string;
  email: string;
  phoneNumber: string;
  homeCity: string;
  homeRegion: string;
  homeCountry: string;
  accountStatus: User['account_status'];
  maxOperatorDevices: string;
  roleIds: string[];
  teamIds: string[];
}

interface UserFormProps {
  mode: UserFormMode;
  form: UserFormState;
  roles: Role[];
  rolesLoading: boolean;
  rolesError: string | null;
  teams: Team[];
  teamsLoading: boolean;
  teamsError: string | null;
  canManageRoles: boolean;
  canManageTeams: boolean;
  canManageCredentials: boolean;
  isSystemAdministrator: boolean;
  saving: boolean;
  error: string | null;
  submitLabel: string;
  onChange: Dispatch<SetStateAction<UserFormState>>;
  onCancel: () => void;
  onSubmit: FormEventHandler<HTMLFormElement>;
}

export function createEmptyUserForm(): UserFormState {
  return {
    firstName: '',
    lastName: '',
    email: '',
    phoneNumber: '',
    homeCity: '',
    homeRegion: '',
    homeCountry: 'NL',
    accountStatus: 'active',
    maxOperatorDevices: '1',
    roleIds: [],
    teamIds: [],
  };
}

export function userFormFromUser(user: User): UserFormState {
  return {
    firstName: user.first_name ?? firstNameFromDisplayName(user.name),
    lastName: user.last_name ?? lastNameFromDisplayName(user.name),
    email: user.email,
    phoneNumber: user.phone_number ?? '',
    homeCity: user.home_city ?? '',
    homeRegion: user.home_region ?? '',
    homeCountry: user.home_country ?? 'NL',
    accountStatus: user.account_status,
    maxOperatorDevices: String(user.max_operator_devices ?? 1),
    roleIds: user.roles?.map((role) => role.id) ?? [],
    teamIds: user.teams?.map((team) => team.id) ?? [],
  };
}

export function userFormPayload(form: UserFormState, mode: UserFormMode, canManageCredentials = true): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    first_name: form.firstName.trim(),
    last_name: form.lastName.trim(),
    phone_number: form.phoneNumber || null,
    home_city: form.homeCity.trim() === '' ? null : form.homeCity.trim(),
    home_region: form.homeRegion.trim() === '' ? null : form.homeRegion.trim(),
    home_country: form.homeCountry || null,
    account_status: form.accountStatus,
    max_operator_devices: Math.max(1, Number(form.maxOperatorDevices || 1)),
    role_ids: form.roleIds,
    team_ids: form.teamIds,
  };

  if (mode === 'create' || canManageCredentials) {
    payload.email = form.email;
  }

  return payload;
}

export function UserForm({
  mode,
  form,
  roles,
  rolesLoading,
  rolesError,
  teams,
  teamsLoading,
  teamsError,
  canManageRoles,
  canManageTeams,
  canManageCredentials,
  isSystemAdministrator,
  saving,
  error,
  submitLabel,
  onChange,
  onCancel,
  onSubmit,
}: UserFormProps) {
  const assignableRoles = roles.filter((role) => isSystemAdministrator || role.name !== 'system-administrator');

  function updateField<Key extends keyof UserFormState>(field: Key, value: UserFormState[Key]) {
    onChange((current) => ({ ...current, [field]: value }));
  }

  function toggleRole(roleId: string) {
    onChange((current) => ({
      ...current,
      roleIds: current.roleIds.includes(roleId)
        ? current.roleIds.filter((candidate) => candidate !== roleId)
        : [...current.roleIds, roleId],
    }));
  }

  function toggleTeam(teamId: string) {
    onChange((current) => ({
      ...current,
      teamIds: current.teamIds.includes(teamId)
        ? current.teamIds.filter((candidate) => candidate !== teamId)
        : [...current.teamIds, teamId],
    }));
  }

  return (
    <form className="form-grid" onSubmit={onSubmit}>
      <label>
        Voornaam
        <input value={form.firstName} onChange={(event) => updateField('firstName', event.target.value)} required maxLength={80} />
      </label>
      <label>
        Achternaam
        <input value={form.lastName} onChange={(event) => updateField('lastName', event.target.value)} required maxLength={120} />
      </label>
      <label>
        E-mail
        <input
          type="email"
          value={form.email}
          onChange={(event) => updateField('email', event.target.value)}
          required
          disabled={mode === 'edit' && !canManageCredentials}
        />
      </label>
      <label>
        Telefoonnummer
        <input value={form.phoneNumber} inputMode="tel" autoComplete="tel" placeholder="+31612345678" onChange={(event) => updateField('phoneNumber', event.target.value)} />
        <small>Wordt bij opslaan internationaal gemaakt op basis van land.</small>
      </label>
      <label>
        Woonplaats
        <input value={form.homeCity} maxLength={120} onChange={(event) => updateField('homeCity', event.target.value)} />
        <small>Globale plaats voor geschatte ETA-ringen, geen exact adres.</small>
      </label>
      <label>
        Land
        <select
          value={form.homeCountry}
          onChange={(event) => {
            const nextCountry = event.target.value;
            const nextRegions = regionOptionsForCountry(nextCountry);
            onChange((current) => ({
              ...current,
              homeCountry: nextCountry,
              homeRegion: nextRegions.includes(current.homeRegion) ? current.homeRegion : '',
            }));
          }}
        >
          {countryOptions.map((country) => (
            <option key={country.value} value={country.value}>{country.label}</option>
          ))}
        </select>
      </label>
      <label>
        Provincie / regio
        <select
          value={form.homeRegion}
          onChange={(event) => updateField('homeRegion', event.target.value)}
          disabled={regionOptionsForCountry(form.homeCountry).length === 0}
        >
          <option value="">Kies provincie/regio</option>
          {regionOptionsForCountry(form.homeCountry).map((region) => (
            <option key={region} value={region}>{region}</option>
          ))}
        </select>
      </label>
      <label>
        Accountstatus
        <select value={form.accountStatus} onChange={(event) => updateField('accountStatus', event.target.value as User['account_status'])}>
          <option value="active">Actief</option>
          <option value="suspended">Geschorst</option>
          <option value="blocked">Geblokkeerd</option>
        </select>
      </label>
      <label>
        Max operator-devices
        <input
          type="number"
          min="1"
          max="20"
          value={form.maxOperatorDevices}
          onChange={(event) => updateField('maxOperatorDevices', event.target.value)}
        />
        <small>Standaard 1. Admin-apps tellen niet mee.</small>
      </label>
      {mode === 'create' ? (
        <p className="form-note form-grid__wide">De gebruiker ontvangt automatisch een eenmalige activatielink en stelt het wachtwoord zelf in.</p>
      ) : null}
      {canManageRoles ? (
        <div className="form-grid__wide">
          <span className="field-label">Rollen</span>
          <ResourceState loading={rolesLoading} error={rolesError} empty={roles.length === 0}>
            <div className="checkbox-grid">
              {assignableRoles.map((role) => (
                <label className="checkbox-card" key={role.id}>
                  <input type="checkbox" checked={form.roleIds.includes(role.id)} onChange={() => toggleRole(role.id)} />
                  <span>
                    <strong>{role.display_name}</strong>
                    <small>{role.description ?? role.name}</small>
                  </span>
                </label>
              ))}
            </div>
          </ResourceState>
        </div>
      ) : null}
      {canManageTeams ? (
        <div className="form-grid__wide">
          <span className="field-label">Teams</span>
          <ResourceState loading={teamsLoading} error={teamsError} empty={teams.length === 0}>
            <div className="checkbox-grid">
              {teams.map((team) => (
                <label className="checkbox-card" key={team.id}>
                  <input type="checkbox" checked={form.teamIds.includes(team.id)} onChange={() => toggleTeam(team.id)} />
                  <span>
                    <strong>{team.code} - {team.name}</strong>
                    <small>{team.type}</small>
                  </span>
                </label>
              ))}
            </div>
          </ResourceState>
        </div>
      ) : null}
      {error ? <p className="form-error form-grid__wide" role="alert">{error}</p> : null}
      <div className="actions-row form-grid__wide">
        <button className="secondary-button" type="button" onClick={onCancel}>Annuleren</button>
        <button className="primary-button" type="submit" disabled={saving || rolesLoading || teamsLoading}>
          {saving ? 'Opslaan...' : submitLabel}
        </button>
      </div>
    </form>
  );
}

function firstNameFromDisplayName(name: string): string {
  return name.trim().split(/\s+/, 1)[0] ?? '';
}

function lastNameFromDisplayName(name: string): string {
  const parts = name.trim().split(/\s+/);
  return parts.length > 1 ? parts.slice(1).join(' ') : '';
}
