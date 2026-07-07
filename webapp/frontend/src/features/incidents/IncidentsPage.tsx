import { FormEvent, useEffect, useState, type ReactNode } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Archive, Clock, CloudSun, FileText, MapPin, Plane, Plus, RadioTower, Search, Users, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { fetchLocationSuggestions, geocodeAddressLabel, lookupLocationSuggestion, type LocationSuggestion } from '../../lib/locationSearch';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { ConfigurableFormField, DroneFlightContext, Incident, IncidentFormConfig, IncidentFormLayoutItem, Team, User } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

export interface IncidentFormState {
  title: string;
  description: string;
  reporterName: string;
  reporterPhone: string;
  requestingOrganization: string;
  requestingUnit: string;
  onSceneContactName: string;
  onSceneContactPhone: string;
  onSceneContactRole: string;
  requiredResources: string;
  priority: Incident['priority'];
  status: Incident['status'];
  locationLabel: string;
  latitude: string;
  longitude: string;
  coordinatorId: string;
  teamIds: string[];
  customFields: Record<string, unknown>;
}

const emptyIncidentForm: IncidentFormState = {
  title: '',
  description: '',
  reporterName: '',
  reporterPhone: '',
  requestingOrganization: '',
  requestingUnit: '',
  onSceneContactName: '',
  onSceneContactPhone: '',
  onSceneContactRole: '',
  requiredResources: '',
  priority: 'normal',
  status: 'draft',
  locationLabel: '',
  latitude: '',
  longitude: '',
  coordinatorId: '',
  teamIds: [],
  customFields: {},
};

type IncidentPageMode = 'active' | 'archive';

const activeIncidentStatuses: Incident['status'][] = ['draft', 'active', 'dispatching', 'in_progress'];
const archiveIncidentStatuses: Incident['status'][] = ['resolved', 'cancelled'];

export function IncidentsPage({ mode = 'active' }: { mode?: IncidentPageMode }) {
  const { api, hasPermission } = useAuth();
  const router = useRouter();
  const incidents = useApiResource<Incident[]>(incidentListPath(mode));
  const users = useApiResource<User[]>('/users?per_page=200');
  const teams = useApiResource<Team[]>('/teams');
  const incidentFormConfig = useApiResource<IncidentFormConfig>('/incident-form/config?target=web');
  const [form, setForm] = useState<IncidentFormState>(emptyIncidentForm);
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const incidentList = filterIncidentsForMode(incidents.data ?? [], mode);
  const activeCount = incidentList.filter((incident) => ['active', 'dispatching', 'in_progress'].includes(incident.status)).length;
  const draftCount = incidentList.filter((incident) => incident.status === 'draft').length;
  const criticalCount = incidentList.filter((incident) => incident.priority === 'critical').length;
  const resolvedCount = incidentList.filter((incident) => incident.status === 'resolved').length;
  const cancelledCount = incidentList.filter((incident) => incident.status === 'cancelled').length;
  const pageTitle = mode === 'archive' ? 'Archief' : 'Actieve meldingen';
  const emptyText = mode === 'archive' ? 'Geen afgeronde of geannuleerde meldingen gevonden.' : 'Geen actieve meldingen of concepten gevonden.';
  const canManageIncidents = hasPermission('incidents.manage');

  const createIncident = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setCreating(true);
    setError(null);
    try {
      const response = await api.post<Incident>('/incidents', incidentPayload(form));
      setForm(emptyIncidentForm);
      setCreateModalOpen(false);
      await incidents.reload();
      router.push(`/incidents/${response.data.id}`);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Incident kon niet worden aangemaakt.');
    } finally {
      setCreating(false);
    }
  };

  function openCreateModal() {
    setForm(emptyIncidentForm);
    setError(null);
    setCreateModalOpen(true);
  }

  return (
    <div className="page-stack incident-page">
      <RealtimeBridge onOperationalEvent={() => void incidents.silentReload()} />
      <Panel
        title={pageTitle}
        action={mode === 'active' && canManageIncidents ? (
          <button className="primary-button" type="button" onClick={openCreateModal}>
            <Plus size={16} /> Incident aanmaken
          </button>
        ) : null}
      >
        <ResourceState loading={incidents.loading} error={incidents.error} empty={false}>
          <div className="incident-list-view">
            <div className="incident-list-summary">
              {mode === 'archive' ? (
                <>
                  <SummaryMetric label="Archief" value={String(incidentList.length)} />
                  <SummaryMetric label="Afgerond" value={String(resolvedCount)} />
                  <SummaryMetric label="Geannuleerd" value={String(cancelledCount)} />
                  <SummaryMetric label="Kritiek" value={String(criticalCount)} />
                </>
              ) : (
                <>
                  <SummaryMetric label="Openstaand" value={String(incidentList.length)} />
                  <SummaryMetric label="Actief" value={String(activeCount)} />
                  <SummaryMetric label="Concept" value={String(draftCount)} />
                  <SummaryMetric label="Kritiek" value={String(criticalCount)} />
                </>
              )}
            </div>
            {incidentList.length > 0 ? (
              <div className={mode === 'archive' ? 'incident-card-grid incident-card-grid--archive' : 'incident-card-grid'}>
                {incidentList.map((incident) => (
                  <IncidentCard incident={incident} mode={mode} key={incident.id} />
                ))}
              </div>
            ) : (
              <div className="empty-panel">
                {mode === 'archive' ? <Archive size={28} /> : <FileText size={28} />}
                <span>{emptyText}</span>
              </div>
            )}
          </div>
        </ResourceState>
      </Panel>

      {createModalOpen && canManageIncidents ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal modal--incident-form" role="dialog" aria-modal="true" aria-labelledby="incident-create-title">
            <header className="modal__header">
              <h2 id="incident-create-title">Incident aanmaken</h2>
              <button className="icon-button" type="button" onClick={() => setCreateModalOpen(false)} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <IncidentForm
              form={form}
              users={users.data ?? []}
              teams={teams.data ?? []}
              customFields={incidentFormConfig.data?.fields ?? []}
              layout={incidentFormConfig.data?.layout ?? []}
              usersError={users.error}
              teamsError={teams.error}
              saving={creating}
              error={error}
              submitLabel="Incident aanmaken"
              onCancel={() => setCreateModalOpen(false)}
              onSubmit={createIncident}
              onChange={setForm}
            />
          </section>
        </div>
      ) : null}
    </div>
  );
}

function filterIncidentsForMode(incidents: Incident[], mode: IncidentPageMode): Incident[] {
  const statuses = mode === 'archive' ? archiveIncidentStatuses : activeIncidentStatuses;

  return incidents.filter((incident) => statuses.includes(incident.status));
}

function incidentListPath(mode: IncidentPageMode): string {
  const statuses = mode === 'archive' ? archiveIncidentStatuses : activeIncidentStatuses;

  return `/incidents?status=${statuses.join(',')}`;
}

function IncidentCard({ incident, mode }: { incident: Incident; mode: IncidentPageMode }) {
  return (
    <Link className={`incident-card incident-card--${incident.status}`} href={`/incidents/${incident.id}`}>
      <header>
        <span className="incident-card__reference">{incident.reference}</span>
        <div className="incident-card__badges">
          <StatusPill value={priorityLabel(incident.priority)} tone={incident.priority === 'critical' ? 'bad' : incident.priority === 'high' ? 'warn' : 'neutral'} />
          <StatusPill value={incidentStatusLabel(incident.status)} tone={incidentTone(incident.status)} />
        </div>
      </header>
      <strong>{incident.title}</strong>
      <div className="incident-card__meta">
        <MetaLine icon={<MapPin size={15} />} value={incident.location_label ?? 'Geen opkomstlocatie'} />
        <MetaLine icon={<Users size={15} />} value={incidentTeamsLabel(incident)} />
        <MetaLine icon={<RadioTower size={15} />} value={incident.coordinator?.name ?? 'Geen coordinator'} />
        <MetaLine icon={<Clock size={15} />} value={mode === 'archive' ? `Gesloten: ${formatDate(incident.closed_at)}` : `Geopend: ${formatDate(incident.opened_at)}`} />
      </div>
    </Link>
  );
}

function SummaryMetric({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

function MetaLine({ icon, value }: { icon: ReactNode; value: string }) {
  return (
    <span>
      {icon}
      <span>{value}</span>
    </span>
  );
}

function formatDate(value?: string | null): string {
  return formatDateTime(value);
}

function incidentStatusLabel(status: Incident['status']): string {
  switch (status) {
    case 'draft':
      return 'Concept';
    case 'active':
      return 'Actief';
    case 'dispatching':
      return 'Alarmeren';
    case 'in_progress':
      return 'In uitvoering';
    case 'resolved':
      return 'Afgerond';
    case 'cancelled':
      return 'Geannuleerd';
    default:
      return status;
  }
}

function priorityLabel(priority: Incident['priority']): string {
  switch (priority) {
    case 'low':
      return 'Laag';
    case 'normal':
      return 'Normaal';
    case 'high':
      return 'Hoog';
    case 'critical':
      return 'Kritiek';
    default:
      return priority;
  }
}

function incidentTone(status: Incident['status']): 'neutral' | 'good' | 'warn' | 'bad' {
  switch (status) {
    case 'active':
    case 'dispatching':
    case 'in_progress':
      return 'warn';
    case 'resolved':
      return 'good';
    case 'cancelled':
      return 'bad';
    default:
      return 'neutral';
  }
}

export function IncidentForm(props: {
  form: IncidentFormState;
  users: User[];
  teams: Team[];
  customFields?: ConfigurableFormField[];
  layout?: IncidentFormLayoutItem[];
  usersError?: string | null;
  teamsError?: string | null;
  saving: boolean;
  error?: string | null;
  extraFields?: ReactNode;
  enforceConfiguredRequiredFixedInputs?: boolean;
  submitLabel: string;
  onCancel: () => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  onChange: (updater: (current: IncidentFormState) => IncidentFormState) => void;
}) {
  const { api } = useAuth();
  const { form, users, teams, customFields = [], layout = [], usersError, teamsError, saving, error, extraFields, enforceConfiguredRequiredFixedInputs = true, submitLabel, onCancel, onSubmit, onChange } = props;
  const [locationSuggestions, setLocationSuggestions] = useState<LocationSuggestion[]>([]);
  const [flightContext, setFlightContext] = useState<DroneFlightContext | null>(null);
  const [flightContextLoading, setFlightContextLoading] = useState(false);
  const [flightContextError, setFlightContextError] = useState<string | null>(null);

  useEffect(() => {
    const query = form.locationLabel.trim();
    if (query.length < 3) {
      setLocationSuggestions([]);
      return;
    }

    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => {
      void fetchLocationSuggestions(query, controller.signal).then(setLocationSuggestions).catch(() => undefined);
    }, 250);

    return () => {
      window.clearTimeout(timeoutId);
      controller.abort();
    };
  }, [form.locationLabel]);

  useEffect(() => {
    const latitude = coordinatePayload(form.latitude);
    const longitude = coordinatePayload(form.longitude);
    if (latitude === null || longitude === null) {
      setFlightContext(null);
      setFlightContextError(null);
      return;
    }

    let cancelled = false;
    const timeoutId = window.setTimeout(async () => {
      setFlightContextLoading(true);
      setFlightContextError(null);
      try {
        const response = await api.post<DroneFlightContext>('/incidents/flight-context-preview', {
          latitude,
          longitude,
          location_label: form.locationLabel.trim() === '' ? null : form.locationLabel,
        });
        if (!cancelled) {
          setFlightContext(response.data);
        }
      } catch (err) {
        if (!cancelled) {
          setFlightContext(null);
          setFlightContextError(err instanceof ApiClientError ? err.message : 'Drone vluchtinformatie kon niet worden opgehaald.');
        }
      } finally {
        if (!cancelled) {
          setFlightContextLoading(false);
        }
      }
    }, 450);

    return () => {
      cancelled = true;
      window.clearTimeout(timeoutId);
    };
  }, [api, form.latitude, form.longitude, form.locationLabel]);

  const activeLayout = incidentFormLayout(layout, customFields);

  return (
    <form className="form-grid" onSubmit={onSubmit}>
      {activeLayout.map((item) => item.visible ? (
        <IncidentFormBlock
          key={item.key}
          item={item}
          form={form}
          users={users}
          teams={teams}
          customFields={customFields}
          usersError={usersError}
          teamsError={teamsError}
          locationSuggestions={locationSuggestions}
          flightContext={flightContext}
          flightContextLoading={flightContextLoading}
          flightContextError={flightContextError}
          enforceConfiguredRequiredFixedInputs={enforceConfiguredRequiredFixedInputs}
          onChange={onChange}
        />
      ) : null)}
      {extraFields}
      {error ? <p className="form-error form-grid__wide">{error}</p> : null}
      <div className="actions-row form-grid__wide">
        <button className="secondary-button" type="button" onClick={onCancel}>Annuleren</button>
        <button className="primary-button" type="submit" disabled={saving || hasMissingRequiredFixedIncidentInput(activeLayout, form, enforceConfiguredRequiredFixedInputs)}>
          {saving ? 'Opslaan...' : submitLabel}
        </button>
      </div>
    </form>
  );
}

function FormSectionTitle({ title }: { title: string }) {
  return (
    <div className="form-section-title form-grid__wide">
      <span>{title}</span>
    </div>
  );
}

function IncidentFormBlock(props: {
  item: IncidentFormLayoutItem;
  form: IncidentFormState;
  users: User[];
  teams: Team[];
  customFields: ConfigurableFormField[];
  usersError?: string | null;
  teamsError?: string | null;
  locationSuggestions: LocationSuggestion[];
  flightContext: DroneFlightContext | null;
  flightContextLoading: boolean;
  flightContextError: string | null;
  enforceConfiguredRequiredFixedInputs: boolean;
  onChange: (updater: (current: IncidentFormState) => IncidentFormState) => void;
}) {
  const wideClass = props.item.width === 'half' ? undefined : 'form-grid__wide';
  const required = incidentLayoutItemRequired(props.item, props.enforceConfiguredRequiredFixedInputs);
  const requiredMark = required ? ' *' : '';

  switch (props.item.key) {
    case 'section_incident':
      return <FormSectionTitle title="Incidentgegevens" />;
    case 'title':
      return (
        <label className={wideClass}>
          Titel{requiredMark}
          <input value={props.form.title} maxLength={180} onChange={(event) => updateForm(props.onChange, 'title', event.target.value)} required={required} />
        </label>
      );
    case 'description':
      return (
        <label className={wideClass}>
          Details{requiredMark}
          <textarea value={props.form.description} rows={5} onChange={(event) => updateForm(props.onChange, 'description', event.target.value)} required={required} />
        </label>
      );
    case 'section_reporter':
      return <FormSectionTitle title="Melder en aanvraag" />;
    case 'reporter_name':
      return <label className={wideClass}>Naam melder{requiredMark}<input value={props.form.reporterName} maxLength={180} required={required} onChange={(event) => updateForm(props.onChange, 'reporterName', event.target.value)} /></label>;
    case 'reporter_phone':
      return <label className={wideClass}>Telefoonnummer melder{requiredMark}<input value={props.form.reporterPhone} maxLength={40} inputMode="tel" autoComplete="tel" required={required} onChange={(event) => updateForm(props.onChange, 'reporterPhone', event.target.value)} /></label>;
    case 'requesting_organization':
      return <label className={wideClass}>Aanvragende organisatie<input value={props.form.requestingOrganization} maxLength={180} onChange={(event) => updateForm(props.onChange, 'requestingOrganization', event.target.value)} /></label>;
    case 'requesting_unit':
      return <label className={wideClass}>Dienst / eenheid<input value={props.form.requestingUnit} maxLength={180} onChange={(event) => updateForm(props.onChange, 'requestingUnit', event.target.value)} /></label>;
    case 'on_scene_contact_name':
      return <label className={wideClass}>Contact ter plaatse<input value={props.form.onSceneContactName} maxLength={180} onChange={(event) => updateForm(props.onChange, 'onSceneContactName', event.target.value)} /></label>;
    case 'on_scene_contact_phone':
      return <label className={wideClass}>Telefoon ter plaatse<input value={props.form.onSceneContactPhone} maxLength={40} inputMode="tel" autoComplete="tel" onChange={(event) => updateForm(props.onChange, 'onSceneContactPhone', event.target.value)} /></label>;
    case 'on_scene_contact_role':
      return <label className={wideClass}>Functie / rol contactpersoon<input value={props.form.onSceneContactRole} maxLength={120} onChange={(event) => updateForm(props.onChange, 'onSceneContactRole', event.target.value)} /></label>;
    case 'section_dispatch':
      return <FormSectionTitle title="Inzet" />;
    case 'priority':
      return (
        <label className={wideClass}>
          Prioriteit{requiredMark}
          <select value={props.form.priority} required={required} onChange={(event) => updateForm(props.onChange, 'priority', event.target.value as Incident['priority'])}>
            <option value="low">Laag</option>
            <option value="normal">Normaal</option>
            <option value="high">Hoog</option>
            <option value="critical">Kritiek</option>
          </select>
        </label>
      );
    case 'status':
      return (
        <label className={wideClass}>
          Status{requiredMark}
          <select value={props.form.status} required={required} onChange={(event) => updateForm(props.onChange, 'status', event.target.value as Incident['status'])}>
            <option value="draft">Concept</option>
            <option value="active">Actief</option>
            <option value="dispatching">Alarmeren</option>
            <option value="in_progress">In uitvoering</option>
            <option value="resolved">Afgerond</option>
            <option value="cancelled">Geannuleerd</option>
          </select>
        </label>
      );
    case 'teams':
      return (
        <div className={wideClass}>
          <span className="field-label">Teams</span>
          <div className="checkbox-grid">
            {props.teams.map((team) => (
              <label className="checkbox-card" key={team.id}>
                <input type="checkbox" checked={props.form.teamIds.includes(team.id)} onChange={() => toggleTeam(props.onChange, team.id)} />
                <span><strong>{team.code} - {team.name}</strong><small>{team.type}</small></span>
              </label>
            ))}
          </div>
          {props.teamsError ? <p className="form-error">Teams laden mislukt: {props.teamsError}</p> : null}
        </div>
      );
    case 'section_location':
      return <FormSectionTitle title="Opkomstlocatie" />;
    case 'location_search':
      return (
        <>
          <LocationSearch form={props.form} suggestions={props.locationSuggestions} onChange={props.onChange} className={wideClass} required={required} />
          <input type="hidden" name="latitude" value={props.form.latitude} />
          <input type="hidden" name="longitude" value={props.form.longitude} />
        </>
      );
    case 'location_map':
      return <LocationMap form={props.form} className={wideClass} />;
    case 'section_resources':
      return <FormSectionTitle title="Middelen" />;
    case 'required_resources':
      return (
        <label className={wideClass}>
          Benodigde middelen
          <textarea value={props.form.requiredResources} rows={4} placeholder="Bijvoorbeeld: drone type, warmtebeeld, zoomcamera, verlichting, voertuig, extra piloot of waarnemer." onChange={(event) => updateForm(props.onChange, 'requiredResources', event.target.value)} />
        </label>
      );
    case 'section_drone':
      return <FormSectionTitle title="Drone vluchtcheck" />;
    case 'drone_status':
      return <DroneFlightStatus context={props.flightContext} loading={props.flightContextLoading} error={props.flightContextError} className={wideClass} />;
    case 'drone_weather':
      return <DroneWeatherModule context={props.flightContext} className={wideClass} />;
    case 'drone_airspace':
      return <DroneAirspaceModule context={props.flightContext} className={wideClass} />;
    case 'drone_aeret_link':
      return <DroneAeretLinkModule context={props.flightContext} className={wideClass} />;
    case 'drone_aeret_map':
      return <DroneAeretMapModule context={props.flightContext} className={wideClass} />;
    case 'incident_details':
      return (
        <div className="form-grid__wide form-grid">
          <FormSectionTitle title="Incidentgegevens" />
          <label className="form-grid__wide">
            Titel *
            <input value={props.form.title} maxLength={180} onChange={(event) => updateForm(props.onChange, 'title', event.target.value)} required />
          </label>
          <label className="form-grid__wide">
            Details *
            <textarea value={props.form.description} rows={5} onChange={(event) => updateForm(props.onChange, 'description', event.target.value)} required />
          </label>
        </div>
      );
    case 'reporter_request':
      return (
        <div className="form-grid__wide form-grid">
          <FormSectionTitle title="Melder en aanvraag" />
          <label>Naam melder<input value={props.form.reporterName} maxLength={180} onChange={(event) => updateForm(props.onChange, 'reporterName', event.target.value)} /></label>
          <label>Telefoonnummer melder<input value={props.form.reporterPhone} maxLength={40} inputMode="tel" autoComplete="tel" onChange={(event) => updateForm(props.onChange, 'reporterPhone', event.target.value)} /></label>
          <label>Aanvragende organisatie<input value={props.form.requestingOrganization} maxLength={180} onChange={(event) => updateForm(props.onChange, 'requestingOrganization', event.target.value)} /></label>
          <label>Dienst / eenheid<input value={props.form.requestingUnit} maxLength={180} onChange={(event) => updateForm(props.onChange, 'requestingUnit', event.target.value)} /></label>
          <label>Contact ter plaatse<input value={props.form.onSceneContactName} maxLength={180} onChange={(event) => updateForm(props.onChange, 'onSceneContactName', event.target.value)} /></label>
          <label>Telefoon ter plaatse<input value={props.form.onSceneContactPhone} maxLength={40} inputMode="tel" autoComplete="tel" onChange={(event) => updateForm(props.onChange, 'onSceneContactPhone', event.target.value)} /></label>
          <label className="form-grid__wide">Functie / rol contactpersoon<input value={props.form.onSceneContactRole} maxLength={120} onChange={(event) => updateForm(props.onChange, 'onSceneContactRole', event.target.value)} /></label>
        </div>
      );
    case 'priority_teams':
      return (
        <div className="form-grid__wide form-grid">
          <FormSectionTitle title="Prioriteit en teams" />
          <label>
            Prioriteit
            <select value={props.form.priority} onChange={(event) => updateForm(props.onChange, 'priority', event.target.value as Incident['priority'])}>
              <option value="low">Laag</option>
              <option value="normal">Normaal</option>
              <option value="high">Hoog</option>
              <option value="critical">Kritiek</option>
            </select>
          </label>
          <label>
            Status
            <select value={props.form.status} onChange={(event) => updateForm(props.onChange, 'status', event.target.value as Incident['status'])}>
              <option value="draft">Concept</option>
              <option value="active">Actief</option>
              <option value="dispatching">Alarmeren</option>
              <option value="in_progress">In uitvoering</option>
              <option value="resolved">Afgerond</option>
              <option value="cancelled">Geannuleerd</option>
            </select>
          </label>
          <div className="form-grid__wide">
            <span className="field-label">Teams</span>
            <div className="checkbox-grid">
              {props.teams.map((team) => (
                <label className="checkbox-card" key={team.id}>
                  <input type="checkbox" checked={props.form.teamIds.includes(team.id)} onChange={() => toggleTeam(props.onChange, team.id)} />
                  <span><strong>{team.code} - {team.name}</strong><small>{team.type}</small></span>
                </label>
              ))}
            </div>
          </div>
          {props.teamsError ? <p className="form-error form-grid__wide">Teams laden mislukt: {props.teamsError}</p> : null}
        </div>
      );
    case 'location':
      return (
        <div className="form-grid__wide form-grid">
          <FormSectionTitle title="Opkomstlocatie" />
          <LocationPicker form={props.form} suggestions={props.locationSuggestions} onChange={props.onChange} />
          <input type="hidden" name="latitude" value={props.form.latitude} />
          <input type="hidden" name="longitude" value={props.form.longitude} />
        </div>
      );
    case 'coordinator':
      return (
        <div className={wideClass}>
          <label>
            Coordinator
            <select value={props.form.coordinatorId} onChange={(event) => updateForm(props.onChange, 'coordinatorId', event.target.value)}>
              <option value="">Niet toegewezen</option>
              {props.users.map((user) => <option key={user.id} value={user.id}>{user.name} - {user.email}</option>)}
            </select>
          </label>
          {props.usersError ? <p className="form-error">Coordinators laden mislukt: {props.usersError}</p> : null}
        </div>
      );
    case 'resources':
      return (
        <div className="form-grid__wide form-grid">
          <FormSectionTitle title="Middelen" />
          <label className="form-grid__wide">
            Benodigde middelen
            <textarea value={props.form.requiredResources} rows={4} placeholder="Bijvoorbeeld: drone type, warmtebeeld, zoomcamera, verlichting, voertuig, extra piloot of waarnemer." onChange={(event) => updateForm(props.onChange, 'requiredResources', event.target.value)} />
          </label>
        </div>
      );
    case 'drone_context':
      return <DroneFlightContextPanel context={props.flightContext} loading={props.flightContextLoading} error={props.flightContextError} />;
    case 'custom_fields':
      return <DynamicIncidentFields fields={props.customFields} values={props.form.customFields} onChange={props.onChange} />;
    default:
      if (props.item.key.startsWith('custom_field:')) {
        const field = props.customFields.find((candidate) => props.item.key === customFieldLayoutKey(candidate.key));
        if (field === undefined || !field.visible) {
          return null;
        }

        return (
          <DynamicIncidentField
            field={{ ...field, width: props.item.width ?? field.width }}
            value={props.form.customFields[field.key]}
            onChange={(value) => updateCustomField(props.onChange, field.key, value)}
          />
        );
      }
      return null;
  }
}

function incidentFormLayout(layout: IncidentFormLayoutItem[], customFields: ConfigurableFormField[]): IncidentFormLayoutItem[] {
  const defaults: IncidentFormLayoutItem[] = [
    { key: 'section_incident', label: 'Sectie: incident', visible: true, width: 'full' },
    { key: 'title', label: 'Titel', visible: true, width: 'full', required: true, expose_to_push: true },
    { key: 'description', label: 'Details', visible: true, width: 'full', required: true, expose_to_push: true },
    { key: 'section_reporter', label: 'Sectie: melder', visible: true, width: 'full', locked: true },
    { key: 'reporter_name', label: 'Naam melder', visible: true, width: 'half', locked: true, required: false, expose_to_push: true },
    { key: 'reporter_phone', label: 'Telefoonnummer melder', visible: true, width: 'half', locked: true, required: false, expose_to_push: true },
    { key: 'section_dispatch', label: 'Sectie: inzet', visible: true, width: 'full' },
    { key: 'priority', label: 'Prioriteit', visible: true, width: 'half', required: true, expose_to_push: true },
    { key: 'status', label: 'Status', visible: true, width: 'half', required: false, expose_to_push: true },
    { key: 'teams', label: 'Teams', visible: true, width: 'full' },
    { key: 'coordinator', label: 'Coordinator', visible: true, width: 'full' },
    { key: 'section_location', label: 'Sectie: locatie', visible: true, width: 'full', locked: true },
    { key: 'location_search', label: 'Adres zoeken', visible: true, width: 'half', locked: true, required: false, expose_to_push: true },
    { key: 'location_map', label: 'Kaart opkomstlocatie', visible: true, width: 'half', locked: true },
    { key: 'section_drone', label: 'Sectie: drone vluchtcheck', visible: true, width: 'full' },
    { key: 'drone_status', label: 'Drone vluchtcheck status', visible: true, width: 'full' },
    { key: 'drone_weather', label: 'Weer', visible: true, width: 'half' },
    { key: 'drone_airspace', label: 'Luchtruim', visible: true, width: 'half' },
    { key: 'drone_aeret_link', label: 'Aeret link', visible: true, width: 'full' },
    { key: 'drone_aeret_map', label: 'Aeret kaart', visible: true, width: 'full' },
    ...customFields.map(customFieldLayoutItem),
  ];
  const defaultKeys = new Set(defaults.map((item) => item.key));
  const defaultsByKey = new Map(defaults.map((item) => [item.key, item]));
  const merged = expandLegacyIncidentLayout(layout, customFields)
    .filter((item) => defaultKeys.has(item.key))
    .map((item) => ({ ...defaultsByKey.get(item.key), ...item }));
  const missing = defaults.filter((item) => !merged.some((candidate) => candidate.key === item.key));

  return [...merged, ...missing];
}

function expandLegacyIncidentLayout(layout: IncidentFormLayoutItem[], customFields: ConfigurableFormField[]): IncidentFormLayoutItem[] {
  const replacements: Record<string, IncidentFormLayoutItem[]> = {
    incident_details: [
      { key: 'section_incident', label: 'Sectie: incident', visible: true, width: 'full' },
      { key: 'title', label: 'Titel', visible: true, width: 'full' },
      { key: 'description', label: 'Details', visible: true, width: 'full' },
    ],
    reporter_request: [
      { key: 'section_reporter', label: 'Sectie: melder', visible: true, width: 'full' },
      { key: 'reporter_name', label: 'Naam melder', visible: true, width: 'half' },
      { key: 'reporter_phone', label: 'Telefoonnummer melder', visible: true, width: 'half' },
    ],
    priority_teams: [
      { key: 'section_dispatch', label: 'Sectie: inzet', visible: true, width: 'full' },
      { key: 'priority', label: 'Prioriteit', visible: true, width: 'half' },
      { key: 'status', label: 'Status', visible: true, width: 'half' },
      { key: 'teams', label: 'Teams', visible: true, width: 'full' },
    ],
    location: [
      { key: 'section_location', label: 'Sectie: locatie', visible: true, width: 'full' },
      { key: 'location_search', label: 'Adres zoeken', visible: true, width: 'half' },
      { key: 'location_map', label: 'Kaart opkomstlocatie', visible: true, width: 'half' },
    ],
    resources: [],
    custom_fields: customFields.map(customFieldLayoutItem),
    drone_context: [
      { key: 'section_drone', label: 'Sectie: drone vluchtcheck', visible: true, width: 'full' },
      { key: 'drone_status', label: 'Drone vluchtcheck status', visible: true, width: 'full' },
      { key: 'drone_weather', label: 'Weer', visible: true, width: 'half' },
      { key: 'drone_airspace', label: 'Luchtruim', visible: true, width: 'half' },
      { key: 'drone_aeret_link', label: 'Aeret link', visible: true, width: 'full' },
      { key: 'drone_aeret_map', label: 'Aeret kaart', visible: true, width: 'full' },
    ],
  };

  const seen = new Set<string>();
  return layout.flatMap((item) => replacements[item.key] ?? [item]).filter((item) => {
    if (seen.has(item.key)) {
      return false;
    }
    seen.add(item.key);
    return true;
  });
}

function customFieldLayoutKey(fieldKey: string): string {
  return `custom_field:${fieldKey}`;
}

function customFieldLayoutItem(field: ConfigurableFormField): IncidentFormLayoutItem {
  return {
    key: customFieldLayoutKey(field.key),
    label: field.label,
    visible: field.visible,
    width: field.width ?? (field.type === 'section' ? 'full' : 'half'),
  };
}

const fixedIncidentInputModuleKeys = new Set([
  'title',
  'description',
  'reporter_name',
  'reporter_phone',
  'priority',
  'status',
  'location_search',
]);

const alwaysRequiredIncidentModuleKeys = new Set(['title', 'description', 'priority']);

function incidentLayoutItemRequired(item: IncidentFormLayoutItem, enforceConfiguredRequired = true): boolean {
  return alwaysRequiredIncidentModuleKeys.has(item.key) || (enforceConfiguredRequired && item.required === true);
}

function hasMissingRequiredFixedIncidentInput(layout: IncidentFormLayoutItem[], form: IncidentFormState, enforceConfiguredRequired: boolean): boolean {
  return layout.some((item) => item.visible && fixedIncidentInputModuleKeys.has(item.key) && incidentLayoutItemRequired(item, enforceConfiguredRequired) && fixedIncidentValue(item.key, form).trim() === '');
}

function fixedIncidentValue(key: string, form: IncidentFormState): string {
  switch (key) {
    case 'title':
      return form.title;
    case 'description':
      return form.description;
    case 'reporter_name':
      return form.reporterName;
    case 'reporter_phone':
      return form.reporterPhone;
    case 'priority':
      return form.priority;
    case 'status':
      return form.status;
    case 'location_search':
      return form.locationLabel;
    default:
      return '';
  }
}

function DroneFlightStatus({ context, loading, error, className }: { context: DroneFlightContext | null; loading: boolean; error: string | null; className?: string }) {
  return (
    <section className={`drone-flight-panel ${className ?? ''}`} aria-live="polite">
      <header>
        <div>
          <span>Drone vluchtcheck</span>
          <strong>{loading ? 'Ophalen...' : context ? 'Opkomstlocatie beoordeeld' : 'Wacht op opkomstlocatie'}</strong>
        </div>
        <Plane size={20} />
      </header>
      {error ? <p className="form-error">{error}</p> : null}
      {!context && !error ? <p className="muted-text">Vul een opkomstlocatie of coordinaten in om de drone-informatie op te halen.</p> : null}
    </section>
  );
}

function DroneWeatherModule({ context, className }: { context: DroneFlightContext | null; className?: string }) {
  return (
    <div className={className}>
      <FlightInfoCard
        icon={<CloudSun size={18} />}
        title="Weer"
        items={[
          ['Temperatuur', formatFlightMetric(context?.weather?.temperature_c, ' C')],
          ['Wind', formatFlightMetric(context?.weather?.wind_speed_kmh, ' km/u')],
          ['Windstoten', formatFlightMetric(context?.weather?.wind_gust_kmh, ' km/u')],
          ['Zicht', formatVisibility(context?.weather?.visibility_m)],
          ['Samenvatting', context?.weather?.summary ?? '-'],
        ]}
      />
    </div>
  );
}

function DroneAirspaceModule({ context, className }: { context: DroneFlightContext | null; className?: string }) {
  return (
    <div className={className}>
      <FlightInfoCard
        icon={<Plane size={18} />}
        title="Luchtruim"
        items={[
          ['Aeret', airspaceStatusLabel(context?.airspace?.status)],
          ['No-fly zones', String(context?.airspace?.no_fly_zones?.length ?? 0)],
          ['NOTAM', String(context?.airspace?.notams?.length ?? 0)],
          ['Samenvatting', context?.airspace?.summary ?? '-'],
        ]}
      />
    </div>
  );
}

function DroneAeretLinkModule({ context, className }: { context: DroneFlightContext | null; className?: string }) {
  if (!context?.map?.aeret_url) {
    return <p className={`muted-text ${className ?? ''}`}>Aeret link verschijnt zodra de locatie bekend is.</p>;
  }

  return <div className={`drone-flight-links ${className ?? ''}`}><a href={context.map.aeret_url} target="_blank" rel="noreferrer">Open Aeret dronekaart</a></div>;
}

function DroneAeretMapModule({ context, className }: { context: DroneFlightContext | null; className?: string }) {
  if (!context?.map?.aeret_url) {
    return <p className={`muted-text ${className ?? ''}`}>Aeret kaart verschijnt zodra de locatie bekend is.</p>;
  }

  return <iframe className={`drone-flight-aeret-frame ${className ?? ''}`} title="Aeret dronekaart" src={context.map.aeret_url} loading="lazy" />;
}

function DroneFlightContextPanel({ context, loading, error }: { context: DroneFlightContext | null; loading: boolean; error: string | null }) {
  return (
    <section className="drone-flight-panel form-grid__wide" aria-live="polite">
      <header>
        <div>
          <span>Drone vluchtcheck</span>
          <strong>{loading ? 'Ophalen...' : context ? 'Opkomstlocatie beoordeeld' : 'Wacht op opkomstlocatie'}</strong>
        </div>
        <Plane size={20} />
      </header>
      {error ? <p className="form-error">{error}</p> : null}
      {context ? (
        <div className="drone-flight-grid">
          <FlightInfoCard
            icon={<CloudSun size={18} />}
            title="Weer"
            items={[
              ['Temperatuur', formatFlightMetric(context.weather?.temperature_c, ' C')],
              ['Wind', formatFlightMetric(context.weather?.wind_speed_kmh, ' km/u')],
              ['Windstoten', formatFlightMetric(context.weather?.wind_gust_kmh, ' km/u')],
              ['Zicht', formatVisibility(context.weather?.visibility_m)],
              ['Samenvatting', context.weather?.summary ?? '-'],
            ]}
          />
          <FlightInfoCard
            icon={<Plane size={18} />}
            title="Luchtruim"
            items={[
              ['Aeret', airspaceStatusLabel(context.airspace?.status)],
              ['No-fly zones', String(context.airspace?.no_fly_zones?.length ?? 0)],
              ['NOTAM', String(context.airspace?.notams?.length ?? 0)],
              ['Samenvatting', context.airspace?.summary ?? '-'],
            ]}
          />
          <div className="drone-flight-links">
            {context.map?.aeret_url ? <a href={context.map.aeret_url} target="_blank" rel="noreferrer">Open Aeret dronekaart</a> : null}
          </div>
          {context.map?.aeret_url ? (
            <iframe className="drone-flight-aeret-frame" title="Aeret dronekaart" src={context.map.aeret_url} loading="lazy" />
          ) : null}
        </div>
      ) : (
        <p className="muted-text">Vul een opkomstlocatie of coordinaten in om weer, no-fly/NOTAM status en dronekaart te tonen.</p>
      )}
    </section>
  );
}

function DynamicIncidentFields(props: {
  fields: ConfigurableFormField[];
  values: Record<string, unknown>;
  onChange: (updater: (current: IncidentFormState) => IncidentFormState) => void;
}) {
  const visibleFields = props.fields.filter((field) => field.visible);
  if (visibleFields.length === 0) {
    return null;
  }

  return (
    <>
      <FormSectionTitle title="Extra gegevens" />
      {visibleFields.map((field) => (
        <DynamicIncidentField
          field={field}
          value={props.values[field.key]}
          onChange={(value) => updateCustomField(props.onChange, field.key, value)}
          key={field.key}
        />
      ))}
    </>
  );
}

function DynamicIncidentField(props: {
  field: ConfigurableFormField;
  value: unknown;
  onChange: (value: unknown) => void;
}) {
  const { field, value, onChange } = props;
  if (field.type === 'section') {
    return <FormSectionTitle title={field.label} />;
  }

  const label = field.required ? `${field.label} *` : field.label;
  const className = field.width === 'full' ? 'form-grid__wide' : undefined;

  if (field.type === 'textarea') {
    return (
      <label className="form-grid__wide">
        {label}
        <textarea value={asFormString(value)} rows={4} required={field.required} onChange={(event) => onChange(event.target.value)} />
      </label>
    );
  }

  if (field.type === 'number') {
    return (
      <label className={className}>
        {label}
        <input type="number" value={asFormString(value)} required={field.required} onChange={(event) => onChange(event.target.value === '' ? null : Number(event.target.value))} />
      </label>
    );
  }

  if (field.type === 'phone') {
    return (
      <label className={className}>
        {label}
        <input
          type="tel"
          inputMode="tel"
          pattern={phonePattern(field)}
          placeholder={phonePlaceholder(field)}
          title={`Gebruik een internationaal nummer met ${phoneCountryLabels(field)}.`}
          value={asFormString(value)}
          required={field.required}
          onChange={(event) => onChange(event.target.value)}
        />
      </label>
    );
  }

  if (field.type === 'flight_time') {
    return (
      <FlightTimeField
        label={label}
        value={value}
        required={field.required}
        onChange={onChange}
      />
    );
  }

  if (field.type === 'select') {
    return (
      <label className={className}>
        {label}
        <select value={asFormString(value)} required={field.required} onChange={(event) => onChange(event.target.value)}>
          <option value="">Selecteer</option>
          {(field.options ?? []).map((option) => <option value={option.value} key={option.value}>{option.label}</option>)}
        </select>
      </label>
    );
  }

  if (field.type === 'radio') {
    return (
      <div className="form-grid__wide">
        <span className="field-label">{label}</span>
        <div className="checkbox-grid">
          {(field.options ?? []).map((option) => (
            <label className="checkbox-card" key={option.value}>
              <input type="radio" name={field.key} checked={asFormString(value) === option.value} required={field.required} onChange={() => onChange(option.value)} />
              <span><strong>{option.label}</strong></span>
            </label>
          ))}
        </div>
      </div>
    );
  }

  if (field.type === 'checkbox') {
    return (
      <label className="checkbox-card form-grid__wide">
        <input type="checkbox" checked={value === true} onChange={(event) => onChange(event.target.checked)} />
        <span><strong>{label}</strong></span>
      </label>
    );
  }

  return (
    <label className={className}>
      {label}
      <input value={asFormString(value)} required={field.required} onChange={(event) => onChange(event.target.value)} />
    </label>
  );
}

function FlightTimeField(props: {
  label: string;
  value: unknown;
  required: boolean;
  onChange: (value: unknown) => void;
}) {
  const value = flightTimeValue(props.value);
  const update = (part: 'start' | 'end', nextValue: string) => {
    props.onChange({
      ...value,
      [part]: normalizeTimeInput(nextValue),
    });
  };

  return (
    <div className="form-grid__wide">
      <span className="field-label">{props.label}</span>
      <div className="form-grid">
        <TimePartFields label="Start" value={value.start} required={props.required} onChange={(next) => update('start', next)} />
        <TimePartFields label="Eind" value={value.end} required={props.required} onChange={(next) => update('end', next)} />
      </div>
      <small>{flightTimeSummary(value)}</small>
    </div>
  );
}

function TimePartFields(props: {
  label: string;
  value: string;
  required: boolean;
  onChange: (value: string) => void;
}) {
  const [hours, minutes] = timeParts(props.value);
  const update = (nextHours: number, nextMinutes: number) => {
    props.onChange(formatTimeParts(nextHours, nextMinutes));
  };

  return (
    <div className="form-grid">
      <label>
        {props.label} uur
        <input
          type="number"
          min="0"
          max="24"
          value={hours}
          required={props.required}
          onChange={(event) => update(Number(event.target.value || 0), minutes)}
        />
      </label>
      <label>
        {props.label} min
        <input
          type="number"
          min="0"
          max="59"
          value={minutes}
          required={props.required}
          onChange={(event) => update(hours, Number(event.target.value || 0))}
        />
      </label>
    </div>
  );
}

function flightTimeValue(value: unknown): { start: string; end: string } {
  if (typeof value === 'object' && value !== null) {
    const record = value as Record<string, unknown>;
    return {
      start: normalizeTimeInput(record.start),
      end: normalizeTimeInput(record.end),
    };
  }

  return { start: '', end: '' };
}

function normalizeTimeInput(value: unknown): string {
  const time = typeof value === 'string' ? value : '';
  return /^([01]\d|2[0-4]):[0-5]\d$/.test(time) ? time : '';
}

function flightTimeSummary(value: { start: string; end: string }): string {
  const duration = flightDurationMinutes(value.start, value.end);
  if (duration === null) {
    return 'Vul start- en eindtijd in.';
  }

  const hours = Math.floor(duration / 60);
  const minutes = duration % 60;
  return `Duur: ${hours} uur ${minutes} minuten.`;
}

function flightDurationMinutes(start: string, end: string): number | null {
  if (!normalizeTimeInput(start) || !normalizeTimeInput(end)) {
    return null;
  }

  const [startHour, startMinute] = start.split(':').map(Number);
  const [endHour, endMinute] = end.split(':').map(Number);
  const startTotal = startHour * 60 + startMinute;
  let endTotal = endHour * 60 + endMinute;
  if (endTotal < startTotal) {
    endTotal += 24 * 60;
  }

  return endTotal - startTotal;
}

function timeParts(value: string): [number, number] {
  const normalized = normalizeTimeInput(value);
  if (normalized === '') {
    return [0, 0];
  }

  const [hours, minutes] = normalized.split(':').map(Number);
  return [hours, minutes];
}

function formatTimeParts(hours: number, minutes: number): string {
  return `${clampTimePart(hours, 0, 24).toString().padStart(2, '0')}:${clampTimePart(minutes, 0, 59).toString().padStart(2, '0')}`;
}

function clampTimePart(value: number, min: number, max: number): number {
  return Number.isFinite(value) ? Math.min(max, Math.max(min, Math.floor(value))) : min;
}

function FlightInfoCard({ icon, title, items }: { icon: ReactNode; title: string; items: Array<[string, string]> }) {
  return (
    <article className="drone-flight-card">
      <h4>{icon}{title}</h4>
      <dl>
        {items.map(([label, value]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd>{value}</dd>
          </div>
        ))}
      </dl>
    </article>
  );
}

function updateCustomField(
  setForm: (updater: (current: IncidentFormState) => IncidentFormState) => void,
  key: string,
  value: unknown,
) {
  setForm((current) => ({
    ...current,
    customFields: {
      ...current.customFields,
      [key]: value,
    },
  }));
}

function asFormString(value: unknown): string {
  if (typeof value === 'string') {
    return value;
  }

  if (typeof value === 'number') {
    return String(value);
  }

  return '';
}

function phoneCountries(field: ConfigurableFormField): string[] {
  const supported = ['31', '32'];
  const values = (field.phone_countries ?? []).filter((country) => supported.includes(country));
  return values.length > 0 ? values : supported;
}

function phonePattern(field: ConfigurableFormField): string {
  return `^\\+(${phoneCountries(field).join('|')})[\\s-]?[1-9](?:[\\s-]?[0-9]){7,11}$`;
}

function phonePlaceholder(field: ConfigurableFormField): string {
  return phoneCountries(field).includes('31') ? '+31612345678' : '+32470123456';
}

function phoneCountryLabels(field: ConfigurableFormField): string {
  return phoneCountries(field).map((country) => `+${country}`).join(' of ');
}

function LocationPicker(props: {
  form: IncidentFormState;
  suggestions: LocationSuggestion[];
  onChange: (updater: (current: IncidentFormState) => IncidentFormState) => void;
}) {
  const { form, suggestions, onChange } = props;

  return (
    <div className="location-picker form-grid__wide">
      <LocationSearch form={form} suggestions={suggestions} onChange={onChange} />
      <LocationMap form={form} />
    </div>
  );
}

function LocationSearch(props: {
  form: IncidentFormState;
  suggestions: LocationSuggestion[];
  className?: string;
  required?: boolean;
  onChange: (updater: (current: IncidentFormState) => IncidentFormState) => void;
}) {
  const { form, suggestions, className, required = false, onChange } = props;

  return (
    <div className={`location-picker__search ${className ?? ''}`}>
      <label>
        Opkomstlocatie{required ? ' *' : ''}
        <div className="input-with-icon">
          <Search size={16} />
          <input
            value={form.locationLabel}
            maxLength={255}
            placeholder="Adres, bedrijf, gebouw, gebied of rendez-vous punt"
            autoComplete="off"
            required={required}
            onChange={(event) => updateForm(onChange, 'locationLabel', event.target.value)}
            onBlur={() => void resolveLocation(form, suggestions, onChange)}
          />
        </div>
      </label>
      {suggestions.length > 0 ? (
        <div className="location-picker__results">
          {suggestions.map((suggestion) => (
            <button
              key={suggestion.id}
              type="button"
              onMouseDown={(event) => event.preventDefault()}
              onClick={() => void selectLocationSuggestion(suggestion, onChange)}
            >
              <MapPin size={15} />
              <span>{suggestion.label}</span>
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}

function LocationMap({ form, className }: { form: IncidentFormState; className?: string }) {
  const hasCoordinates = form.latitude.trim() !== '' && form.longitude.trim() !== '';

  return (
    <div className={`location-picker__map ${className ?? ''}`}>
      {hasCoordinates ? (
        <iframe
          title="Geselecteerde locatie"
          src={mapPreviewUrl(form.latitude, form.longitude)}
          loading="lazy"
        />
      ) : (
        <div className="location-picker__empty">
          <MapPin size={28} />
          <span>Zoek een opkomstlocatie om de kaart te tonen.</span>
        </div>
      )}
    </div>
  );
}

async function selectLocationSuggestion(
  suggestion: LocationSuggestion,
  onChange: (updater: (current: IncidentFormState) => IncidentFormState) => void,
): Promise<void> {
  onChange((current) => ({ ...current, locationLabel: suggestion.label }));
  const resolved = await lookupLocationSuggestion(suggestion);
  if (resolved !== null) {
    onChange((current) => ({ ...current, ...resolved }));
  }
}

async function resolveLocation(
  form: IncidentFormState,
  suggestions: LocationSuggestion[],
  onChange: (updater: (current: IncidentFormState) => IncidentFormState) => void,
): Promise<void> {
  const selected = suggestions.find((suggestion) => suggestion.label === form.locationLabel.trim());
  if (selected) {
    const resolved = await lookupLocationSuggestion(selected);
    if (resolved !== null) {
      onChange((current) => ({ ...current, ...resolved }));
      return;
    }
  }

  await geocodeAddress(form, onChange);
}

function mapPreviewUrl(latitude: string, longitude: string): string {
  const lat = Number(latitude);
  const lon = Number(longitude);
  if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
    return 'about:blank';
  }

  const delta = 0.01;
  const params = new URLSearchParams({
    bbox: `${lon - delta},${lat - delta},${lon + delta},${lat + delta}`,
    layer: 'mapnik',
    marker: `${lat},${lon}`,
  });

  return `https://www.openstreetmap.org/export/embed.html?${params.toString()}`;
}

async function geocodeAddress(form: IncidentFormState, onChange: (updater: (current: IncidentFormState) => IncidentFormState) => void): Promise<void> {
  try {
    const resolved = await geocodeAddressLabel(form.locationLabel);
    if (resolved === null) {
      return;
    }

    onChange((current) => ({ ...current, ...resolved }));
  } catch {
    // Manual coordinates remain available when the geocoder cannot be reached.
  }
}

function coordinatePayload(value: string): number | null {
  const trimmed = value.trim().replace(',', '.');
  if (trimmed === '') {
    return null;
  }

  const coordinate = Number(trimmed);

  return Number.isFinite(coordinate) ? Number(coordinate.toFixed(7)) : null;
}

function formatFlightMetric(value: unknown, suffix: string): string {
  if (value === null || value === undefined || value === '') {
    return '-';
  }

  return `${value}${suffix}`;
}

function formatVisibility(value: unknown): string {
  const meters = Number(value);
  if (!Number.isFinite(meters)) {
    return '-';
  }

  return `${(meters / 1000).toFixed(1)} km`;
}

function airspaceStatusLabel(status?: string | null): string {
  switch (status) {
    case 'available':
      return 'Opgehaald';
    case 'not_configured':
      return 'Niet gekoppeld';
    case 'unavailable':
      return 'Niet beschikbaar';
    default:
      return status ?? '-';
  }
}

export function incidentPayload(form: IncidentFormState): Record<string, unknown> {
  const customFields = omitFixedIncidentFields(form.customFields);
  const legacyValue = (key: string, fallback: string): string | null => {
    const value = customFields[key];
    if (typeof value === 'string') {
      return value.trim() === '' ? null : value;
    }

    return fallback.trim() === '' ? null : fallback;
  };

  return {
    title: form.title.trim(),
    description: form.description.trim(),
    reporter_name: form.reporterName.trim() === '' ? null : form.reporterName,
    reporter_phone: form.reporterPhone.trim() === '' ? null : form.reporterPhone,
    requesting_organization: legacyValue('requesting_organization', form.requestingOrganization),
    requesting_unit: legacyValue('requesting_unit', form.requestingUnit),
    on_scene_contact_name: legacyValue('on_scene_contact_name', form.onSceneContactName),
    on_scene_contact_phone: legacyValue('on_scene_contact_phone', form.onSceneContactPhone),
    on_scene_contact_role: legacyValue('on_scene_contact_role', form.onSceneContactRole),
    required_resources: legacyValue('required_resources', form.requiredResources),
    priority: form.priority,
    status: form.status,
    location_label: form.locationLabel.trim() === '' ? null : form.locationLabel,
    latitude: coordinatePayload(form.latitude),
    longitude: coordinatePayload(form.longitude),
    coordinator_id: form.coordinatorId === '' ? null : form.coordinatorId,
    team_id: form.teamIds[0] ?? null,
    team_ids: form.teamIds,
    custom_fields: customFields,
  };
}

function omitFixedIncidentFields(fields: Record<string, unknown>): Record<string, unknown> {
  const { reporter_name: _reporterName, reporter_phone: _reporterPhone, ...remaining } = fields;
  void _reporterName;
  void _reporterPhone;

  return remaining;
}

function incidentTeamsLabel(incident: Incident): string {
  const teams = incident.teams?.length ? incident.teams : incident.team ? [incident.team] : [];

  return teams.map((team) => `${team.code} - ${team.name}`).join(', ') || 'Geen team';
}

function toggleTeam(
  setForm: (updater: (current: IncidentFormState) => IncidentFormState) => void,
  teamId: string,
) {
  setForm((current) => ({
    ...current,
    teamIds: current.teamIds.includes(teamId)
      ? current.teamIds.filter((candidate) => candidate !== teamId)
      : [...current.teamIds, teamId],
  }));
}

function updateForm<K extends keyof IncidentFormState>(
  setForm: (updater: (current: IncidentFormState) => IncidentFormState) => void,
  key: K,
  value: IncidentFormState[K],
) {
  setForm((current) => ({ ...current, [key]: value }));
}
