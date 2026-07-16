import { AlertTriangle } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type {
  OsrmManagementAction,
  OsrmManagementStatus,
  OsrmOperationFeed,
  OsrmOperationLogLine,
  OsrmOperationRequest,
  OsrmOperationStarted,
  OsrmOperationSummary,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import {
  osrmActionLabel,
  osrmConfirmationTitle,
  mergeOsrmLogLines,
  nextOsrmPollDelay,
  osrmOperationIsActive,
  osrmOperationStageLabel,
  osrmOperationStateLabel,
  osrmOperationTone,
  osrmStateLabel,
  osrmStateTone,
  osrmUpdateGuidance,
  validateOsrmOperationForm,
  type OsrmOperationFormValues,
} from './osrmAdminPresentation';

const initialForm: OsrmOperationFormValues = {
  sourceSha256: '',
  longitude: '',
  latitude: '',
};

export function OsrmAdminPanel({
  enabled,
  canManage,
  realtimeOperation,
}: {
  enabled: boolean;
  canManage: boolean;
  realtimeOperation: OsrmOperationSummary | null;
}) {
  const { api } = useAuth();
  const status = useApiResource<OsrmManagementStatus>('/admin/routing/osrm', enabled);
  const [form, setForm] = useState<OsrmOperationFormValues>(initialForm);
  const [operation, setOperation] = useState<OsrmOperationSummary | null>(null);
  const [logLines, setLogLines] = useState<OsrmOperationLogLine[]>([]);
  const [logHydrated, setLogHydrated] = useState(false);
  const [pendingRequest, setPendingRequest] = useState<OsrmOperationRequest | null>(null);
  const [starting, setStarting] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [pollError, setPollError] = useState<string | null>(null);
  const operationIdRef = useRef<string | null>(null);
  const nextCursorRef = useRef(0);
  const configurationKeyRef = useRef<string | null>(null);
  const logRef = useRef<HTMLPreElement | null>(null);
  const followLogRef = useRef(true);

  const adoptOperation = useCallback((nextOperation: OsrmOperationSummary) => {
    if (operationIdRef.current !== nextOperation.id) {
      operationIdRef.current = nextOperation.id;
      nextCursorRef.current = 0;
      setLogLines([]);
      setLogHydrated(false);
      setPollError(null);
      followLogRef.current = true;
    }
    setOperation(nextOperation);
  }, []);

  const configuration = status.data?.configuration;
  const configurationKey = configuration === undefined
    ? 'empty'
    : `${configuration.source_url}|${configuration.source_sha256 ?? ''}|${configuration.health_coordinate?.longitude ?? ''}|${configuration.health_coordinate?.latitude ?? ''}`;

  useEffect(() => {
    if (configurationKeyRef.current === configurationKey) {
      return;
    }
    configurationKeyRef.current = configurationKey;
    setForm({
      sourceSha256: '',
      longitude: configuration?.health_coordinate === null || configuration?.health_coordinate === undefined ? '' : String(configuration.health_coordinate.longitude),
      latitude: configuration?.health_coordinate === null || configuration?.health_coordinate === undefined ? '' : String(configuration.health_coordinate.latitude),
    });
  }, [configuration, configurationKey]);

  useEffect(() => {
    const latestOperation = status.data?.active_operation ?? status.data?.latest_operation;
    if (latestOperation !== null && latestOperation !== undefined) {
      adoptOperation(latestOperation);
    }
  }, [adoptOperation, status.data?.active_operation, status.data?.latest_operation]);

  const reloadStatusSilently = status.silentReload;

  useEffect(() => {
    if (realtimeOperation === null) {
      return;
    }
    adoptOperation(realtimeOperation);
    if (!osrmOperationIsActive(realtimeOperation.state)) {
      void reloadStatusSilently();
    }
  }, [adoptOperation, realtimeOperation, reloadStatusSilently]);

  const operationActive = operation !== null && osrmOperationIsActive(operation.state);
  const operationId = operation?.id ?? null;

  useEffect(() => {
    if (!enabled || operationActive) {
      return;
    }

    const intervalId = window.setInterval(() => {
      void reloadStatusSilently();
    }, 15000);

    return () => window.clearInterval(intervalId);
  }, [enabled, operationActive, reloadStatusSilently]);

  useEffect(() => {
    if (operationId === null || (!operationActive && logHydrated)) {
      return;
    }

    let cancelled = false;
    let timeoutId: number | undefined;

    const poll = async () => {
      try {
        const response = await api.get<OsrmOperationFeed>(
          `/admin/routing/osrm/operations/${operationId}?after=${nextCursorRef.current}&limit=200`,
        );
        if (cancelled) {
          return;
        }

        adoptOperation(response.data.operation);
        nextCursorRef.current = response.data.next_cursor;
        if (response.data.lines.length > 0) {
          setLogLines((current) => mergeOsrmLogLines(current, response.data.lines));
        }
        setPollError(null);

        const nextDelay = nextOsrmPollDelay(response.data.operation.state, response.data.lines.length);
        if (nextDelay !== null) {
          timeoutId = window.setTimeout(() => void poll(), nextDelay);
        } else {
          setLogHydrated(true);
          await reloadStatusSilently();
        }
      } catch (error) {
        if (cancelled) {
          return;
        }
        setPollError(error instanceof ApiClientError ? error.message : 'Live OSRM-log ophalen mislukt. DIS probeert het opnieuw.');
        timeoutId = window.setTimeout(() => void poll(), 5000);
      }
    };

    void poll();

    return () => {
      cancelled = true;
      if (timeoutId !== undefined) {
        window.clearTimeout(timeoutId);
      }
    };
  }, [adoptOperation, api, logHydrated, operationActive, operationId, reloadStatusSilently]);

  useEffect(() => {
    const node = logRef.current;
    if (node === null || !followLogRef.current) {
      return;
    }
    node.scrollTop = node.scrollHeight;
  }, [logLines]);

  function prepareOperation(action: OsrmManagementAction) {
    setActionError(null);
    const validation = validateOsrmOperationForm(action, form);
    if (!validation.valid) {
      setActionError(validation.message);
      return;
    }

    setPendingRequest(validation.request);
  }

  async function startOperation() {
    if (pendingRequest === null) {
      return;
    }

    setStarting(true);
    setActionError(null);
    try {
      const response = await api.post<OsrmOperationStarted>('/admin/routing/osrm/operations', pendingRequest);
      adoptOperation(response.data.operation);
      setPendingRequest(null);
      setForm((current) => ({ ...current, sourceSha256: '' }));
      await reloadStatusSilently();
    } catch (error) {
      setActionError(error instanceof ApiClientError ? error.message : 'OSRM-bewerking starten mislukt.');
      if (error instanceof ApiClientError && error.status === 409) {
        setPendingRequest(null);
        await reloadStatusSilently();
      }
    } finally {
      setStarting(false);
    }
  }

  const nextAction = status.data?.next_action ?? null;
  const displayedOperation = operation ?? status.data?.active_operation ?? status.data?.latest_operation ?? null;

  return (
    <>
      <Panel
        title="OSRM navigatie-ETA"
        action={(
          <button className="secondary-button" type="button" disabled={status.loading} onClick={() => void status.reload()}>
            Status vernieuwen
          </button>
        )}
      >
        <ResourceState loading={status.loading} error={status.error} empty={!status.data}>
          {status.data ? (
            <>
              <dl className="definition-grid">
                <dt>Status</dt>
                <dd><StatusPill value={osrmStateLabel(status.data.state)} tone={osrmStateTone(status.data.state)} /></dd>
                <dt>Geïnstalleerd</dt>
                <dd>{status.data.installed ? 'Ja' : 'Nee'}</dd>
                <dt>Actief voor DIS</dt>
                <dd>{status.data.enabled ? 'Ja' : 'Nee'}</dd>
                <dt>Gezond</dt>
                <dd>{status.data.healthy ? 'Ja' : 'Nee'}</dd>
                <dt>OSRM-versie</dt>
                <dd>{status.data.package?.version ?? '-'}</dd>
                <dt>Pakket gecontroleerd</dt>
                <dd>{formatDateTime(status.data.package?.verified_at)}</dd>
                <dt>Kaart geïmporteerd</dt>
                <dd>{formatDateTime(status.data.dataset?.imported_at)}</dd>
                <dt>Kaart SHA-256</dt>
                <dd className="mono">{status.data.dataset?.sha256 ?? '-'}</dd>
                <dt>Laatst geverifieerde bron SHA-256</dt>
                <dd className="mono">{status.data.configuration.source_sha256 ?? '-'}</dd>
                <dt>Vaste kaartbron</dt>
                <dd className="mono">{status.data.configuration.source_url || '-'}</dd>
                <dt>Controlepunt</dt>
                <dd>{status.data.configuration.health_coordinate
                  ? `${status.data.configuration.health_coordinate.longitude}, ${status.data.configuration.health_coordinate.latitude}`
                  : '-'}</dd>
              </dl>

              {status.data.blocker ? (
                <div className="metadata-example osrm-management-warning" role="alert">
                  <strong><AlertTriangle aria-hidden size={18} /> Actie geblokkeerd</strong>
                  <p>{status.data.blocker.message}</p>
                </div>
              ) : null}

              {canManage && nextAction !== null ? (
                <form
                  className="form-grid osrm-management-form"
                  aria-busy={starting || operationActive}
                  onSubmit={(event) => {
                    event.preventDefault();
                    prepareOperation(nextAction);
                  }}
                >
                  <p className="muted-text form-grid__wide">
                    {nextAction === 'install_activate'
                      ? 'DIS installeert OSRM, verwerkt de gecontroleerde kaart en activeert routering pas nadat de gezondheidscontrole slaagt.'
                      : osrmUpdateGuidance(status.data.state, status.data.healthy)}
                  </p>
                  <div className="field-display form-grid__wide">
                    <span>Vaste Nederlandse kaartbron</span>
                    <strong className="mono">{status.data.configuration.source_url || '-'}</strong>
                  </div>
                  <label className="form-grid__wide">
                    Onafhankelijk gecontroleerde SHA-256
                    <input
                      className="mono"
                      inputMode="text"
                      autoComplete="off"
                      spellCheck={false}
                      minLength={64}
                      maxLength={64}
                      pattern="[A-Fa-f0-9]{64}"
                      required
                      aria-describedby="osrm-sha256-guidance"
                      value={form.sourceSha256}
                      onChange={(event) => setForm((current) => ({ ...current, sourceSha256: event.target.value }))}
                    />
                  </label>
                  <p id="osrm-sha256-guidance" className="muted-text form-grid__wide">
                    Controleer deze hash via een onafhankelijk beheerkanaal; gebruik niet alleen informatie uit dezelfde download.
                  </p>
                  {nextAction === 'install_activate' ? (
                    <>
                      <label>
                        Controle-lengtegraad
                        <input
                          type="number"
                          inputMode="decimal"
                          min={-180}
                          max={180}
                          step="any"
                          required
                          value={form.longitude}
                          onChange={(event) => setForm((current) => ({ ...current, longitude: event.target.value }))}
                        />
                      </label>
                      <label>
                        Controle-breedtegraad
                        <input
                          type="number"
                          inputMode="decimal"
                          min={-90}
                          max={90}
                          step="any"
                          required
                          value={form.latitude}
                          onChange={(event) => setForm((current) => ({ ...current, latitude: event.target.value }))}
                        />
                      </label>
                    </>
                  ) : null}
                  {actionError ? <p className="form-error form-grid__wide" role="alert">{actionError}</p> : null}
                  <div className="actions-row form-grid__wide">
                    <button
                      className="primary-button"
                      type="submit"
                      disabled={starting || operationActive || nextAction !== status.data.next_action}
                    >
                      {operationActive ? 'OSRM-bewerking draait...' : osrmActionLabel(nextAction)}
                    </button>
                  </div>
                </form>
              ) : null}

              {!canManage && nextAction !== null ? (
                <p className="muted-text osrm-management-note">Je kunt de OSRM-status bekijken, maar hebt geen recht om installatie of updates te starten.</p>
              ) : null}
              {actionError && (!canManage || nextAction === null) ? <p className="form-error" role="alert">{actionError}</p> : null}
            </>
          ) : null}
        </ResourceState>
      </Panel>

      <Panel title="OSRM-bewerking en live log">
        {displayedOperation === null ? (
          <p className="muted-text osrm-management-note">Nog geen OSRM-bewerking gestart in deze sessie.</p>
        ) : (
          <>
            <dl className="definition-grid" aria-live="polite" aria-atomic="true">
              <dt>Bewerking</dt>
              <dd>{osrmActionLabel(displayedOperation.action)}</dd>
              <dt>Status</dt>
              <dd><StatusPill value={osrmOperationStateLabel(displayedOperation.state)} tone={osrmOperationTone(displayedOperation.state)} /></dd>
              <dt>Fase</dt>
              <dd>{osrmOperationStageLabel(displayedOperation.stage)}</dd>
              <dt>Laatste melding</dt>
              <dd>{displayedOperation.message}</dd>
              <dt>Gestart</dt>
              <dd>{formatDateTime(displayedOperation.started_at)}</dd>
              <dt>Afgerond</dt>
              <dd>{formatDateTime(displayedOperation.finished_at)}</dd>
              <dt>Exit code</dt>
              <dd>{displayedOperation.exit_code ?? '-'}</dd>
            </dl>
            {pollError ? <p className="form-error" role="alert">{pollError}</p> : null}
            <div className="metadata-example osrm-live-log-wrap">
              <strong>Live log</strong>
              <pre
                ref={logRef}
                className="osrm-live-log"
                tabIndex={0}
                aria-label="Live OSRM-log"
                onScroll={(event) => {
                  const node = event.currentTarget;
                  followLogRef.current = node.scrollHeight - node.scrollTop - node.clientHeight < 80;
                }}
              >
                {formatLogLines(logLines) || (osrmOperationIsActive(displayedOperation.state)
                  ? 'Wachten op uitvoer...'
                  : 'Geen openbare logregels beschikbaar.')}
              </pre>
            </div>
          </>
        )}
      </Panel>

      {pendingRequest !== null ? (
        <div className="modal-backdrop" role="presentation">
          <section
            className="modal modal--narrow"
            role="dialog"
            aria-modal="true"
            aria-labelledby="osrm-confirmation-title"
            aria-describedby="osrm-confirmation-description"
          >
            <header className="modal__header">
              <div>
                <span className="modal__eyebrow">Navigatie-ETA</span>
                <h2 id="osrm-confirmation-title">{osrmConfirmationTitle(pendingRequest.action)}</h2>
              </div>
            </header>
            <div className="confirm-dialog">
              <p id="osrm-confirmation-description">
                Deze bewerking downloadt en verwerkt een groot kaartbestand en kan lang duren. Sluit of herstart de server niet tijdens activering.
              </p>
              <dl className="definition-grid">
                <dt>Bron</dt>
                <dd>{status.data?.configuration.source_url ?? '-'}</dd>
                <dt>SHA-256</dt>
                <dd className="mono">{pendingRequest.source_sha256}</dd>
                <dt>Controlepunt</dt>
                <dd>{pendingRequest.health_coordinate
                  ? `${pendingRequest.health_coordinate.longitude}, ${pendingRequest.health_coordinate.latitude}`
                  : 'Bestaand gecontroleerd punt'}</dd>
              </dl>
              {actionError ? <p className="form-error" role="alert">{actionError}</p> : null}
              <div className="actions-row">
                <button className="secondary-button" type="button" autoFocus disabled={starting} onClick={() => setPendingRequest(null)}>
                  Annuleren
                </button>
                <button className="primary-button" type="button" disabled={starting} onClick={() => void startOperation()}>
                  {starting ? 'Starten...' : `Ja, ${osrmActionLabel(pendingRequest.action).toLowerCase()}`}
                </button>
              </div>
            </div>
          </section>
        </div>
      ) : null}
    </>
  );
}

function formatLogLines(lines: OsrmOperationLogLine[]): string {
  return lines
    .map((line) => `[${formatDateTime(line.at)}] ${line.level.toUpperCase()} ${line.message}`)
    .join('\n');
}
