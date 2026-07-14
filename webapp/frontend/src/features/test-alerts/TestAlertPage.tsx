import { type FormEvent, useEffect, useId, useRef, useState } from 'react';
import { AlertTriangle, BellRing, Send, UsersRound, UserRound } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type { DispatchRecipient, DispatchRequest } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { RealtimeBridge } from '../realtime/RealtimeBridge';
import {
  defaultTestAlertScope,
  readTestAlertSummary,
  testAlertSuccessMessage,
  type TestAlertScope,
  type TestAlertSummary,
} from './testAlertContract';

interface TestAlertSchedule {
  enabled: boolean;
  day_of_week: number;
  time: string;
  message: string;
  last_run_at: string | null;
}

const weekDays = [
  { value: '1', label: 'Maandag' },
  { value: '2', label: 'Dinsdag' },
  { value: '3', label: 'Woensdag' },
  { value: '4', label: 'Donderdag' },
  { value: '5', label: 'Vrijdag' },
  { value: '6', label: 'Zaterdag' },
  { value: '7', label: 'Zondag' },
];

const defaultTestAlertMessage = 'Dit is het wekelijkse proefalarm.';

export function TestAlertPage() {
  const { api } = useAuth();
  const testAlert = useApiResource<DispatchRequest>('/test-alert');
  const schedule = useApiResource<TestAlertSchedule>('/test-alert/schedule');
  const allOnlineDescriptionId = useId();
  const allOnlineConfirmationDescriptionId = useId();
  const sendingRef = useRef(false);
  const [scope, setScope] = useState<TestAlertScope>(defaultTestAlertScope);
  const [sending, setSending] = useState(false);
  const [confirmAllOnline, setConfirmAllOnline] = useState(false);
  const [lastSendSummary, setLastSendSummary] = useState<TestAlertSummary | null>(null);
  const [savingSchedule, setSavingSchedule] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [scheduleMessage, setScheduleMessage] = useState<string | null>(null);
  const [scheduleError, setScheduleError] = useState<string | null>(null);
  const [scheduleForm, setScheduleForm] = useState({ enabled: false, dayOfWeek: '1', time: '09:00', message: defaultTestAlertMessage });

  useEffect(() => {
    if (schedule.data === null) {
      return;
    }

    setScheduleForm({
      enabled: schedule.data.enabled,
      dayOfWeek: String(schedule.data.day_of_week),
      time: schedule.data.time,
      message: schedule.data.message,
    });
  }, [schedule.data]);

  useEffect(() => {
    if (!confirmAllOnline) {
      return undefined;
    }

    function closeOnEscape(event: KeyboardEvent) {
      if (event.key === 'Escape' && !sendingRef.current) {
        setConfirmAllOnline(false);
      }
    }

    window.addEventListener('keydown', closeOnEscape);

    return () => window.removeEventListener('keydown', closeOnEscape);
  }, [confirmAllOnline]);

  function requestTestAlert() {
    setError(null);
    if (scope === 'all_online') {
      setConfirmAllOnline(true);
      return;
    }

    void sendTestAlert('self');
  }

  async function sendTestAlert(requestedScope: TestAlertScope) {
    if (sendingRef.current) {
      return;
    }

    sendingRef.current = true;
    setSending(true);
    setMessage(null);
    setError(null);
    setLastSendSummary(null);

    try {
      const response = await api.post<DispatchRequest>('/test-alert', { scope: requestedScope });
      testAlert.mutate(response.data);
      const summary = readTestAlertSummary(response.meta);
      if (summary === null) {
        setLastSendSummary(null);
        setError('De proefalarmering is gestart, maar het verzendresultaat kon niet worden gelezen. Controleer Live status.');
        setConfirmAllOnline(false);
        return;
      }

      setLastSendSummary(summary);
      setMessage(testAlertSuccessMessage(summary));
      setConfirmAllOnline(false);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Proefalarmering kon niet worden verzonden.');
    } finally {
      sendingRef.current = false;
      setSending(false);
    }
  }

  async function saveSchedule(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSavingSchedule(true);
    setScheduleMessage(null);
    setScheduleError(null);

    try {
      await api.patch<TestAlertSchedule>('/test-alert/schedule', {
        enabled: scheduleForm.enabled,
        day_of_week: Number(scheduleForm.dayOfWeek),
        time: scheduleForm.time,
        message: scheduleForm.message,
      });
      setScheduleMessage('Planning opgeslagen.');
      await schedule.reload();
    } catch (err) {
      setScheduleError(err instanceof ApiClientError ? err.message : 'Planning kon niet worden opgeslagen.');
    } finally {
      setSavingSchedule(false);
    }
  }

  const recipients = testAlert.data?.recipients ?? [];

  return (
    <div className="page-stack">
      <RealtimeBridge onOperationalEvent={() => void testAlert.silentReload()} />

      <Panel
        title="Proefalarmering"
      >
        <div className="test-alert-hero">
          <div className="test-alert-hero__icon"><BellRing size={28} /></div>
          <div>
            <h3>Controleer pushmeldingen en bereikbaarheid</h3>
            <p>Kies een persoonlijke controle of een brede bereikbaarheidstest. Een proefmelding toont alleen de knop Ontvangen.</p>
          </div>
        </div>
        <div className="test-alert-controls">
          <fieldset className="test-alert-scope" disabled={sending}>
            <legend>Wie wil je alarmeren?</legend>
            <div className="checkbox-grid">
              <label className={`checkbox-card test-alert-scope__option ${scope === 'self' ? 'test-alert-scope__option--selected' : ''}`}>
                <input
                  type="radio"
                  name="test-alert-scope"
                  value="self"
                  checked={scope === 'self'}
                  onChange={() => setScope('self')}
                />
                <span>
                  <strong><UserRound aria-hidden size={17} /> Alleen mijzelf</strong>
                  <small>Stuur de proefmelding alleen naar je eigen actieve gekoppelde apps.</small>
                </span>
              </label>
              <label className={`checkbox-card test-alert-scope__option ${scope === 'all_online' ? 'test-alert-scope__option--selected' : ''}`}>
                <input
                  type="radio"
                  name="test-alert-scope"
                  value="all_online"
                  checked={scope === 'all_online'}
                  aria-describedby={allOnlineDescriptionId}
                  onChange={() => setScope('all_online')}
                />
                <span>
                  <strong><UsersRound aria-hidden size={17} /> Alle online operator-apps</strong>
                  <small id={allOnlineDescriptionId}>Voor een extra bereikbaarheidstest. Beschikbaarheid, certificaten en drones tellen hierbij niet mee.</small>
                </span>
              </label>
            </div>
          </fieldset>
          <div className="actions-row test-alert-controls__actions">
            <button className="primary-button" type="button" onClick={requestTestAlert} disabled={sending}>
              <Send aria-hidden size={16} /> {sending ? 'Versturen...' : scope === 'self' ? 'Persoonlijke proefmelding versturen' : 'Bereikbaarheidstest starten'}
            </button>
          </div>
        </div>
        {error && !confirmAllOnline ? <p className="form-error" role="alert">{error}</p> : null}
        {message ? <p className="success-text" role="status" aria-live="polite">{message}</p> : null}
        {lastSendSummary ? <TestAlertSendSummary summary={lastSendSummary} /> : null}
      </Panel>

      <Panel title="Automatisch proefalarm">
        <ResourceState loading={schedule.loading} error={schedule.error} empty={!schedule.data}>
          <form className="form-grid" onSubmit={saveSchedule}>
            <label className="check-label form-grid__wide">
              <input
                type="checkbox"
                checked={scheduleForm.enabled}
                onChange={(event) => setScheduleForm((current) => ({ ...current, enabled: event.target.checked }))}
              />
              Automatische proefalarmering inschakelen
            </label>
            <label>
              Dag
              <select value={scheduleForm.dayOfWeek} onChange={(event) => setScheduleForm((current) => ({ ...current, dayOfWeek: event.target.value }))}>
                {weekDays.map((day) => (
                  <option key={day.value} value={day.value}>{day.label}</option>
                ))}
              </select>
            </label>
            <label>
              Tijd
              <input type="time" value={scheduleForm.time} onChange={(event) => setScheduleForm((current) => ({ ...current, time: event.target.value }))} />
            </label>
            <label className="form-grid__wide">
              Tekst
              <textarea
                maxLength={240}
                required
                rows={3}
                value={scheduleForm.message}
                onChange={(event) => setScheduleForm((current) => ({ ...current, message: event.target.value }))}
              />
            </label>
            <div className="form-grid__wide">
              <span className="field-label">Laatste automatische uitvoering</span>
              <strong>{formatDate(schedule.data?.last_run_at)}</strong>
            </div>
            {scheduleError ? <p className="form-error form-grid__wide">{scheduleError}</p> : null}
            {scheduleMessage ? <p className="success-text form-grid__wide">{scheduleMessage}</p> : null}
            <div className="actions-row form-grid__wide">
              <button className="primary-button" type="submit" disabled={savingSchedule}>
                {savingSchedule ? 'Opslaan...' : 'Planning opslaan'}
              </button>
            </div>
          </form>
        </ResourceState>
      </Panel>

      <Panel title="Live status">
        <ResourceState loading={testAlert.loading} error={testAlert.error} empty={!testAlert.data}>
          <div className="panel-body">
            <div className="summary-grid">
              <SummaryItem label="Referentie" value={testAlert.data?.incident?.reference ?? '-'} />
              <SummaryItem label="Dispatch" value={testAlert.data?.status ?? '-'} />
              <SummaryItem label="Gestart" value={formatDate(testAlert.data?.sent_at)} />
              <SummaryItem label="Ontvangen" value={String(countResponses(recipients, 'accepted'))} />
              <SummaryItem label="Afgewezen" value={String(countResponses(recipients, 'declined'))} />
              <SummaryItem label="Geen reactie / verlopen" value={String(countResponses(recipients, 'no_response'))} />
              <SummaryItem label="Wacht op reactie" value={String(countResponses(recipients, 'pending'))} />
            </div>

            <table className="data-table">
              <thead>
                <tr>
                  <th scope="col">Gebruiker</th>
                  <th scope="col">Status</th>
                  <th scope="col">Reactietijd</th>
                  <th scope="col">Opmerking</th>
                </tr>
              </thead>
              <tbody>
                {recipients.map((recipient) => (
                  <tr key={recipient.id}>
                    <td>{recipient.user?.name ?? recipient.user_id}</td>
                    <td>
                      <StatusPill
                        value={responseLabel(recipient.response_status)}
                        tone={recipient.response_status === 'accepted' ? 'good' : recipient.response_status === 'declined' ? 'bad' : undefined}
                      />
                    </td>
                    <td>{formatDate(recipient.responded_at)}</td>
                    <td>{recipient.response_note ?? '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </ResourceState>
      </Panel>

      {confirmAllOnline ? (
        <div className="modal-backdrop" role="presentation">
          <section
            className="modal modal--narrow"
            role="dialog"
            aria-modal="true"
            aria-labelledby="test-alert-confirmation-title"
            aria-describedby={allOnlineConfirmationDescriptionId}
          >
            <header className="modal__header">
              <div>
                <span className="modal__eyebrow">Bereikbaarheidstest</span>
                <h2 id="test-alert-confirmation-title">Alle online operator-apps alarmeren?</h2>
              </div>
            </header>
            <div className="confirm-dialog">
              <p id={allOnlineConfirmationDescriptionId}>DIS probeert een zichtbare proefmelding klaar te zetten voor alle gebruikers met een online operator-app.</p>
              <div className="test-alert-warning">
                <AlertTriangle aria-hidden size={20} />
                <p><strong>Dit is een brede test.</strong> Er wordt niet gefilterd op beschikbaarheid, certificeringen of toegewezen drones.</p>
              </div>
              {error ? <p className="form-error" role="alert">{error}</p> : null}
              <div className="actions-row">
                <button className="secondary-button" type="button" autoFocus onClick={() => setConfirmAllOnline(false)} disabled={sending}>Annuleren</button>
                <button className="danger-button" type="button" onClick={() => void sendTestAlert('all_online')} disabled={sending}>
                  <UsersRound aria-hidden size={16} /> {sending ? 'Klaarzetten...' : 'Ja, bereikbaarheidstest starten'}
                </button>
              </div>
            </div>
          </section>
        </div>
      ) : null}
    </div>
  );
}

function TestAlertSendSummary({ summary }: { summary: TestAlertSummary }) {
  return (
    <div className="test-alert-send-summary" aria-label="Resultaat van de laatste proefalarmering">
      <div className="summary-grid">
        <SummaryItem label="Klaargezet voor gebruikers" value={String(summary.recipient_count)} />
        <SummaryItem label="Pushmeldingen in wachtrij" value={String(summary.queued_token_count)} />
        <SummaryItem label="Vooraf overgeslagen" value={String(summary.skipped_user_count)} />
        {summary.failed_user_count > 0 ? <SummaryItem label="Niet klaargezet door fout" value={String(summary.failed_user_count)} /> : null}
      </div>
    </div>
  );
}

function SummaryItem({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

function countResponses(recipients: DispatchRecipient[], status: DispatchRecipient['response_status']): number {
  return recipients.filter((recipient) => recipient.response_status === status).length;
}

function responseLabel(value: string): string {
  switch (value) {
    case 'accepted':
      return 'ontvangen';
    case 'declined':
      return 'afgewezen';
    case 'no_response':
      return 'geen reactie / verlopen';
    default:
      return 'wacht op reactie';
  }
}

function formatDate(value?: string | null): string {
  return formatDateTime(value);
}
