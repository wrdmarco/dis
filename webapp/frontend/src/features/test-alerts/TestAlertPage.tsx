import { type FormEvent, useEffect, useState } from 'react';
import { BellRing, Send } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type { DispatchRecipient, DispatchRequest } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

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
  const [sending, setSending] = useState(false);
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

  async function sendTestAlert() {
    setSending(true);
    setMessage(null);
    setError(null);

    try {
      await api.post<DispatchRequest>('/test-alert');
      setMessage('Proefalarmering verzonden. Wacht op ontvangstbevestiging via de Android melding.');
      await testAlert.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Proefalarmering kon niet worden verzonden.');
    } finally {
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
      <RealtimeBridge onOperationalEvent={() => void testAlert.reload()} />

      <Panel
        title="Proefalarmering"
        action={(
          <button className="primary-button" type="button" onClick={sendTestAlert} disabled={sending}>
            <Send size={16} /> {sending ? 'Versturen...' : 'Proefalarmering doen'}
          </button>
        )}
      >
        <div className="test-alert-hero">
          <div className="test-alert-hero__icon"><BellRing size={28} /></div>
          <div>
            <h3>Controleer pushmelding en ontvangstknop</h3>
            <p>De proefalarmering wordt naar de ingelogde gebruiker gestuurd en toont alleen de knop Ontvangen.</p>
          </div>
        </div>
        {error ? <p className="form-error">{error}</p> : null}
        {message ? <p className="success-text">{message}</p> : null}
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
              <SummaryItem label="Verstuurd" value={formatDate(testAlert.data?.sent_at)} />
              <SummaryItem label="Ontvangen" value={String(countResponses(recipients, 'accepted'))} />
              <SummaryItem label="Niet ontvangen" value={String(countResponses(recipients, 'declined'))} />
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
      return 'niet ontvangen';
    case 'no_response':
      return 'geen reactie';
    default:
      return 'wacht op reactie';
  }
}

function formatDate(value?: string | null): string {
  return formatDateTime(value);
}
