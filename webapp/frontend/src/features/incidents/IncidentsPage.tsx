import { FormEvent, useEffect, useState, type ReactNode } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Archive, Clock, CloudSun, FileText, MapPin, Plane, Plus, RadioTower, Search, Users, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { ConfigurableFormField, DroneFlightContext, Incident, IncidentFormConfig, Team, User } from '../../types/api';
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

interface LocationSuggestion {
  id: string;
  label: string;
}

type IncidentPageMode = 'active' | 'archive';

const activeIncidentStatuses: Incident['status'][] = ['draft', 'active', 'dispatching', 'in_progress'];
const archiveIncidentStatuses: Incident['status'][] = ['resolved', 'cancelled'];

export function IncidentsPage({ mode = 'active' }: { mode?: IncidentPageMode }) {
  const { api, hasPermission } = useAuth();
  const router = useRouter();
  const incidents = useApiResource<Incident[]>(incidentListPath(mode));
  const users = useApiResource<User[]>('/users?per_page=200');
  const teams = useApiResource<Team[]>('/teams');
  const incidentFormConfig = useApiResource<IncidentFormConfig>('/incident-form/config');
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
  usersError?: string | null;
  teamsError?: string | null;
  saving: boolean;
  error?: string | null;
  extraFields?: ReactNode;
  submitLabel: string;
  onCancel: () => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  onChange: (updater: (current: IncidentFormState) => IncidentFormState) => void;
}) {
  const { api } = useAuth();
  const { form, users, teams, customFields = [], usersError, teamsError, saving, error, extraFields, submitLabel, onCancel, onSubmit, onChange } = props;
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

  return (
    <form className="form-grid" onSubmit={onSubmit}>
      <FormSectionTitle title="Incidentgegevens" />
      <label className="form-grid__wide">
        Titel
        <input value={form.title} maxLength={180} onChange={(event) => updateForm(onChange, 'title', event.target.value)} required />
      </label>
      <label className="form-grid__wide">
        Details
        <textarea value={form.description} rows={5} onChange={(event) => updateForm(onChange, 'description', event.target.value)} required />
      </label>
      <FormSectionTitle title="Melder en aanvraag" />
      <label>
        Naam melder
        <input value={form.reporterName} maxLength={180} onChange={(event) => updateForm(onChange, 'reporterName', event.target.value)} />
      </label>
      <label>
        Telefoonnummer melder
        <input value={form.reporterPhone} maxLength={40} inputMode="tel" autoComplete="tel" onChange={(event) => updateForm(onChange, 'reporterPhone', event.target.value)} />
      </label>
      <label>
        Aanvragende organisatie
        <input value={form.requestingOrganization} maxLength={180} onChange={(event) => updateForm(onChange, 'requestingOrganization', event.target.value)} />
      </label>
      <label>
        Dienst / eenheid
        <input value={form.requestingUnit} maxLength={180} onChange={(event) => updateForm(onChange, 'requestingUnit', event.target.value)} />
      </label>
      <label>
        Contact ter plaatse
        <input value={form.onSceneContactName} maxLength={180} onChange={(event) => updateForm(onChange, 'onSceneContactName', event.target.value)} />
      </label>
      <label>
        Telefoon ter plaatse
        <input value={form.onSceneContactPhone} maxLength={40} inputMode="tel" autoComplete="tel" onChange={(event) => updateForm(onChange, 'onSceneContactPhone', event.target.value)} />
      </label>
      <label className="form-grid__wide">
        Functie / rol contactpersoon
        <input value={form.onSceneContactRole} maxLength={120} onChange={(event) => updateForm(onChange, 'onSceneContactRole', event.target.value)} />
      </label>
      <FormSectionTitle title="Prioriteit en teams" />
      <label>
        Prioriteit
        <select value={form.priority} onChange={(event) => updateForm(onChange, 'priority', event.target.value as Incident['priority'])}>
          <option value="low">Laag</option>
          <option value="normal">Normaal</option>
          <option value="high">Hoog</option>
          <option value="critical">Kritiek</option>
        </select>
      </label>
      <label>
        Status
        <select value={form.status} onChange={(event) => updateForm(onChange, 'status', event.target.value as Incident['status'])}>
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
          {teams.map((team) => (
            <label className="checkbox-card" key={team.id}>
              <input
                type="checkbox"
                checked={form.teamIds.includes(team.id)}
                onChange={() => toggleTeam(onChange, team.id)}
              />
              <span>
                <strong>{team.code} - {team.name}</strong>
                <small>{team.type}</small>
              </span>
            </label>
          ))}
        </div>
      </div>
      <FormSectionTitle title="Opkomstlocatie" />
      <LocationPicker
        form={form}
        suggestions={locationSuggestions}
        onChange={onChange}
      />
      <input type="hidden" name="latitude" value={form.latitude} />
      <input type="hidden" name="longitude" value={form.longitude} />
      <label className="form-grid__wide">
        Coordinator
        <select value={form.coordinatorId} onChange={(event) => updateForm(onChange, 'coordinatorId', event.target.value)}>
          <option value="">Niet toegewezen</option>
          {users.map((user) => <option key={user.id} value={user.id}>{user.name} - {user.email}</option>)}
        </select>
      </label>
      <FormSectionTitle title="Middelen en vluchtcheck" />
      <label className="form-grid__wide">
        Benodigde middelen
        <textarea
          value={form.requiredResources}
          rows={4}
          placeholder="Bijvoorbeeld: drone type, warmtebeeld, zoomcamera, verlichting, voertuig, extra piloot of waarnemer."
          onChange={(event) => updateForm(onChange, 'requiredResources', event.target.value)}
        />
      </label>
      {teamsError ? <p className="form-error form-grid__wide">Teams laden mislukt: {teamsError}</p> : null}
      {usersError ? <p className="form-error form-grid__wide">Coordinators laden mislukt: {usersError}</p> : null}
      <DroneFlightContextPanel context={flightContext} loading={flightContextLoading} error={flightContextError} />
      <DynamicIncidentFields fields={customFields} values={form.customFields} onChange={onChange} />
      {extraFields}
      {error ? <p className="form-error form-grid__wide">{error}</p> : null}
      <div className="actions-row form-grid__wide">
        <button className="secondary-button" type="button" onClick={onCancel}>Annuleren</button>
        <button className="primary-button" type="submit" disabled={saving || form.title.trim() === '' || form.description.trim() === ''}>
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

function LocationPicker(props: {
  form: IncidentFormState;
  suggestions: LocationSuggestion[];
  onChange: (updater: (current: IncidentFormState) => IncidentFormState) => void;
}) {
  const { form, suggestions, onChange } = props;
  const hasCoordinates = form.latitude.trim() !== '' && form.longitude.trim() !== '';

  return (
    <div className="location-picker form-grid__wide">
      <div className="location-picker__search">
        <label>
          Opkomstlocatie
          <div className="input-with-icon">
            <Search size={16} />
            <input
              value={form.locationLabel}
              maxLength={255}
              placeholder="Adres, bedrijf, gebouw, gebied of rendez-vous punt"
              autoComplete="off"
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
      <div className="location-picker__map">
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
    </div>
  );
}

async function fetchLocationSuggestions(query: string, signal: AbortSignal): Promise<LocationSuggestion[]> {
  const [pdok, osm] = await Promise.allSettled([
    fetchPdokLocationSuggestions(query, signal),
    fetchPhotonLocationSuggestions(query, signal),
  ]);
  const pdokSuggestions = pdok.status === 'fulfilled' ? pdok.value : [];
  const photonSuggestions = osm.status === 'fulfilled' ? osm.value : [];
  const suggestions = looksLikeAddressQuery(query)
    ? [...pdokSuggestions, ...photonSuggestions]
    : [...photonSuggestions, ...pdokSuggestions];

  return uniqueLocationSuggestions(suggestions).slice(0, 8);
}

async function fetchPdokLocationSuggestions(query: string, signal: AbortSignal): Promise<LocationSuggestion[]> {
  const params = new URLSearchParams({ q: query, rows: '8' });
  const response = await fetch(`https://api.pdok.nl/bzk/locatieserver/search/v3_1/suggest?${params.toString()}`, {
    headers: { Accept: 'application/json' },
    signal,
  });
  if (!response.ok) {
    return [];
  }

  const payload = await response.json() as { response?: { docs?: Array<{ id?: string; weergavenaam?: string }> } };

  return payload.response?.docs
    ?.filter((item): item is { id: string; weergavenaam: string } => typeof item.id === 'string' && typeof item.weergavenaam === 'string')
    .map((item) => ({ id: `pdok:${item.id}`, label: item.weergavenaam }))
    ?? [];
}

async function fetchPhotonLocationSuggestions(query: string, signal: AbortSignal): Promise<LocationSuggestion[]> {
  const params = new URLSearchParams({
    q: query,
    limit: '6',
    lat: '52.1326',
    lon: '5.2913',
  });
  const response = await fetch(`https://photon.komoot.io/api/?${params.toString()}`, {
    headers: { Accept: 'application/json' },
    signal,
  });
  if (!response.ok) {
    return [];
  }

  const payload = await response.json() as {
    features?: Array<{
      geometry?: { coordinates?: [number, number] };
      properties?: {
        osm_id?: number;
        name?: string;
        street?: string;
        housenumber?: string;
        postcode?: string;
        city?: string;
        state?: string;
        country?: string;
      };
    }>;
  };

  return payload.features
    ?.map((feature, index) => {
      const coordinates = feature.geometry?.coordinates;
      const longitude = coordinates?.[0];
      const latitude = coordinates?.[1];
      const label = photonLabel(feature.properties);
      const displayLabel = photonDisplayLabel(query, label);
      if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || label === '') {
        return null;
      }

      return {
        id: `photon:${feature.properties?.osm_id ?? index}:${latitude}:${longitude}:${encodeURIComponent(displayLabel)}`,
        label: displayLabel,
      };
    })
    .filter((item): item is LocationSuggestion => item !== null)
    ?? [];
}

function uniqueLocationSuggestions(suggestions: LocationSuggestion[]): LocationSuggestion[] {
  const seen = new Set<string>();
  return suggestions.filter((suggestion) => {
    const key = suggestion.label.toLocaleLowerCase('nl-NL');
    if (seen.has(key)) {
      return false;
    }
    seen.add(key);
    return true;
  });
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

async function lookupLocationSuggestion(suggestion: LocationSuggestion): Promise<Pick<IncidentFormState, 'locationLabel' | 'latitude' | 'longitude'> | null> {
  if (suggestion.id.startsWith('photon:')) {
    return coordinatesFromPhotonSuggestion(suggestion);
  }

  return lookupPdokLocation(suggestion.id.replace(/^pdok:/, ''));
}

async function lookupPdokLocation(id: string): Promise<Pick<IncidentFormState, 'locationLabel' | 'latitude' | 'longitude'> | null> {
  try {
    const params = new URLSearchParams({ id });
    const response = await fetch(`https://api.pdok.nl/bzk/locatieserver/search/v3_1/lookup?${params.toString()}`, { headers: { Accept: 'application/json' } });
    if (!response.ok) {
      return null;
    }

    const payload = await response.json() as { response?: { docs?: Array<{ centroide_ll?: string; weergavenaam?: string }> } };
    const match = payload.response?.docs?.[0];
    return coordinatesFromPdokMatch(match);
  } catch {
    return null;
  }
}

async function geocodeAddress(form: IncidentFormState, onChange: (updater: (current: IncidentFormState) => IncidentFormState) => void): Promise<void> {
  const query = form.locationLabel.trim();
  if (query.length < 6) {
    return;
  }

  try {
    const resolved = looksLikeAddressQuery(query)
      ? await geocodePdokAddress(query) ?? await geocodePhotonLocation(query)
      : await geocodePhotonLocation(query) ?? await geocodePdokAddress(query);
    if (resolved === null) {
      return;
    }

    onChange((current) => ({ ...current, ...resolved }));
  } catch {
    // Manual coordinates remain available when the geocoder cannot be reached.
  }
}

function looksLikeAddressQuery(query: string): boolean {
  return /\d/.test(query);
}

async function geocodePdokAddress(query: string): Promise<Pick<IncidentFormState, 'locationLabel' | 'latitude' | 'longitude'> | null> {
  const params = new URLSearchParams({ q: query, rows: '1' });
  const response = await fetch(`https://api.pdok.nl/bzk/locatieserver/search/v3_1/free?${params.toString()}`, { headers: { Accept: 'application/json' } });
  if (!response.ok) {
    return null;
  }

  const payload = await response.json() as { response?: { docs?: Array<{ centroide_ll?: string; weergavenaam?: string }> } };
  return coordinatesFromPdokMatch(payload.response?.docs?.[0]);
}

async function geocodePhotonLocation(query: string): Promise<Pick<IncidentFormState, 'locationLabel' | 'latitude' | 'longitude'> | null> {
  const params = new URLSearchParams({
    q: query,
    limit: '1',
    lat: '52.1326',
    lon: '5.2913',
  });
  const response = await fetch(`https://photon.komoot.io/api/?${params.toString()}`, { headers: { Accept: 'application/json' } });
  if (!response.ok) {
    return null;
  }

  const payload = await response.json() as {
    features?: Array<{
      geometry?: { coordinates?: [number, number] };
      properties?: Parameters<typeof photonLabel>[0];
    }>;
  };
  const match = payload.features?.[0];
  const longitude = match?.geometry?.coordinates?.[0];
  const latitude = match?.geometry?.coordinates?.[1];
  const label = photonLabel(match?.properties);
  if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || label === '') {
    return null;
  }

  return coordinatesFromLatLon(label, String(latitude), String(longitude));
}

function coordinatesFromPhotonSuggestion(suggestion: LocationSuggestion): Pick<IncidentFormState, 'locationLabel' | 'latitude' | 'longitude'> | null {
  const [, , latitude, longitude, encodedLabel] = suggestion.id.split(':');
  if (!latitude || !longitude || !encodedLabel) {
    return null;
  }

  return coordinatesFromLatLon(decodeURIComponent(encodedLabel), latitude, longitude);
}

function photonLabel(properties?: {
  name?: string;
  street?: string;
  housenumber?: string;
  postcode?: string;
  city?: string;
  state?: string;
  country?: string;
}): string {
  if (!properties) {
    return '';
  }

  const street = [properties.street, properties.housenumber].filter(Boolean).join(' ');
  return [
    properties.name,
    street,
    properties.postcode,
    properties.city,
    properties.state,
    properties.country,
  ].filter((part): part is string => typeof part === 'string' && part.trim() !== '').join(', ');
}

function photonDisplayLabel(query: string, label: string): string {
  const normalizedQuery = query.trim();
  if (normalizedQuery === '') {
    return label;
  }

  const queryWords = normalizedQuery.toLocaleLowerCase('nl-NL').split(/\s+/).filter((word) => word.length > 2);
  const normalizedLabel = label.toLocaleLowerCase('nl-NL');
  const missingImportantWord = queryWords.some((word) => !normalizedLabel.includes(word));
  return missingImportantWord ? `${normalizedQuery} - ${label}` : label;
}

function coordinatesFromPdokMatch(match?: { centroide_ll?: string; weergavenaam?: string }): Pick<IncidentFormState, 'locationLabel' | 'latitude' | 'longitude'> | null {
  const point = match?.centroide_ll?.match(/^POINT\(([-0-9.]+) ([-0-9.]+)\)$/);
  if (!point) {
    return null;
  }

  const [, longitude, latitude] = point;

  return {
    latitude: formatCoordinate(latitude),
    longitude: formatCoordinate(longitude),
    locationLabel: match?.weergavenaam ?? '',
  };
}

function coordinatesFromLatLon(label: string, latitude: string, longitude: string): Pick<IncidentFormState, 'locationLabel' | 'latitude' | 'longitude'> | null {
  const formattedLatitude = formatCoordinate(latitude);
  const formattedLongitude = formatCoordinate(longitude);
  if (formattedLatitude === '' || formattedLongitude === '') {
    return null;
  }

  return {
    latitude: formattedLatitude,
    longitude: formattedLongitude,
    locationLabel: label,
  };
}

function formatCoordinate(value: string): string {
  const coordinate = Number(value);
  if (!Number.isFinite(coordinate)) {
    return '';
  }

  return coordinate.toFixed(7);
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
  return {
    title: form.title.trim(),
    description: form.description.trim(),
    reporter_name: form.reporterName.trim() === '' ? null : form.reporterName,
    reporter_phone: form.reporterPhone.trim() === '' ? null : form.reporterPhone,
    requesting_organization: form.requestingOrganization.trim() === '' ? null : form.requestingOrganization,
    requesting_unit: form.requestingUnit.trim() === '' ? null : form.requestingUnit,
    on_scene_contact_name: form.onSceneContactName.trim() === '' ? null : form.onSceneContactName,
    on_scene_contact_phone: form.onSceneContactPhone.trim() === '' ? null : form.onSceneContactPhone,
    on_scene_contact_role: form.onSceneContactRole.trim() === '' ? null : form.onSceneContactRole,
    required_resources: form.requiredResources.trim() === '' ? null : form.requiredResources,
    priority: form.priority,
    status: form.status,
    location_label: form.locationLabel.trim() === '' ? null : form.locationLabel,
    latitude: coordinatePayload(form.latitude),
    longitude: coordinatePayload(form.longitude),
    coordinator_id: form.coordinatorId === '' ? null : form.coordinatorId,
    team_id: form.teamIds[0] ?? null,
    team_ids: form.teamIds,
    custom_fields: form.customFields,
  };
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
