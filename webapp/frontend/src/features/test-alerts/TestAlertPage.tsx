import { useState } from 'react';
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

export function TestAlertPage() {
  const { api } = useAuth();
  const testAlert = useApiResource<DispatchRequest>('/test-alert');
  const [sending, setSending] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

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
                  <th>Gebruiker</th>
                  <th>Status</th>
                  <th>Reactietijd</th>
                  <th>Opmerking</th>
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
