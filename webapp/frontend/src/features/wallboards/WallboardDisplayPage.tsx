import {
  type CSSProperties,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import {
  AlertTriangle,
  Clock3,
  Expand,
  Loader2,
  LockKeyhole,
  MapPin,
  MonitorUp,
  RefreshCw,
  RotateCw,
  Siren,
  WifiOff,
} from 'lucide-react';
import { ApiClient, ApiClientError, apiBaseUrl } from '../../lib/apiClient';
import type {
  WallboardControlState,
  WallboardDisplayMode,
  WallboardPage,
  WallboardPairingRequest,
  WallboardPairingStatus,
  WallboardPilotAvailability,
  WallboardState,
  WallboardStateRecentIncident,
  WallboardTickerItem,
  WallboardTransientAlert,
} from '../../types/api';
import { OperationalMapCanvas } from '../incidents/OperationalMapCanvas';
import {
  buildWallboardMapPresentation,
  clampRefreshSeconds,
  formatWallboardPilotAvailability,
  normalizeWallboardDisplayProfile,
  selectRecentWallboardIncidents,
  wallboardConfigurationCopy,
  wallboardPageMapConfiguration,
  wallboardStateIsStale,
  wallboardTickerDurationSeconds,
  wallboardTransientAlertIsActive,
} from './wallboardPresentation';

const wallboardApi = new ApiClient({ baseUrl: apiBaseUrl, onUnauthenticated: () => undefined });
const CONTROL_POLL_MILLISECONDS = 2000;
const DEFAULT_PAIRING_POLL_MILLISECONDS = 2000;
const PAIRING_RETRY_MILLISECONDS = 10000;
const WALLBOARD_TIME_ZONE = 'Europe/Amsterdam';
const WALLBOARD_CLOCK_FORMATTER = new Intl.DateTimeFormat('nl-NL', {
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  hour12: false,
  timeZone: WALLBOARD_TIME_ZONE,
});
const WALLBOARD_DATE_FORMATTER = new Intl.DateTimeFormat('nl-NL', {
  weekday: 'short',
  day: 'numeric',
  month: 'short',
  timeZone: WALLBOARD_TIME_ZONE,
});
const RECENT_INCIDENT_TIME_FORMATTER = new Intl.DateTimeFormat('nl-NL', {
  day: '2-digit',
  month: 'short',
  hour: '2-digit',
  minute: '2-digit',
  timeZone: WALLBOARD_TIME_ZONE,
});
let pairingStartInFlight: Promise<WallboardPairingRequest> | null = null;

export function WallboardDisplayPage() {
  const rootRef = useRef<HTMLElement | null>(null);
  const wakeLockRef = useRef<WakeLockSentinel | null>(null);
  const refreshSecondsRef = useRef(10);
  const configVersionRef = useRef<number | null>(null);
  const pendingConfigVersionRef = useRef<number | null>(null);
  const lastControlIncidentActiveRef = useRef<boolean | null>(null);
  const [sessionStatus, setSessionStatus] = useState<'checking' | 'unpaired' | 'paired'>('checking');
  const [state, setState] = useState<WallboardState | null>(null);
  const [control, setControl] = useState<WallboardControlState | null>(null);
  const [stateError, setStateError] = useState<string | null>(null);
  const [controlError, setControlError] = useState<string | null>(null);
  const [pairingRequest, setPairingRequest] = useState<WallboardPairingRequest | null>(null);
  const [pairingError, setPairingError] = useState<string | null>(null);
  const [pairingStartGeneration, setPairingStartGeneration] = useState(0);
  const [pollGeneration, setPollGeneration] = useState(0);
  const [fullscreen, setFullscreen] = useState(false);
  const [wakeLockActive, setWakeLockActive] = useState(false);
  const [clock, setClock] = useState(() => Date.now());
  const connectionError = controlError ?? stateError;
  const hasPairedState = sessionStatus === 'paired' && state !== null;
  const stale = state !== null && wallboardStateIsStale(state, clock);
  const feedStatus = connectionError !== null ? 'offline' : stale ? 'stale' : 'live';
  const hasLiveFeed = feedStatus === 'live';
  const presentation = useMemo(
    () => state === null ? null : buildWallboardMapPresentation(state, hasLiveFeed),
    [hasLiveFeed, state],
  );

  useEffect(() => {
    if (sessionStatus !== 'unpaired') return;
    let cancelled = false;

    const startPairing = async () => {
      setPairingError(null);
      try {
        const request = await requestWallboardPairing();
        if (cancelled) return;
        setPairingRequest(request);
        setClock(Date.now());
      } catch (error) {
        if (cancelled) return;
        setPairingError(errorMessage(error, 'Er kon geen koppelcode worden gemaakt. De tv probeert het zo opnieuw.'));
      }
    };

    void startPairing();
    return () => {
      cancelled = true;
    };
  }, [pairingStartGeneration, sessionStatus]);

  useEffect(() => {
    if (sessionStatus !== 'unpaired' || pairingRequest !== null || pairingError === null) return undefined;
    const timer = window.setTimeout(() => setPairingStartGeneration((current) => current + 1), PAIRING_RETRY_MILLISECONDS);
    return () => window.clearTimeout(timer);
  }, [pairingError, pairingRequest, sessionStatus]);

  useEffect(() => {
    if (sessionStatus !== 'unpaired' || pairingRequest === null) return undefined;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | null = null;
    const pollMilliseconds = pairingPollMilliseconds(pairingRequest.poll_after_seconds);

    const pollPairing = async () => {
      try {
        const response = await wallboardApi.post<WallboardPairingStatus>('/wallboard/pairing/status');
        if (cancelled) return;
        if (response.data.status === 'paired') {
          setPairingRequest(null);
          setPairingError(null);
          setSessionStatus('checking');
          setPollGeneration((current) => current + 1);
          return;
        }
        setPairingError(null);
        setPairingRequest((current) => {
          if (current === null) return null;
          const expiresAt = response.data.expires_at === undefined ? current.expires_at : response.data.expires_at;
          const pollAfterSeconds = response.data.poll_after_seconds ?? current.poll_after_seconds;
          if (expiresAt === current.expires_at && pollAfterSeconds === current.poll_after_seconds) return current;
          return { ...current, expires_at: expiresAt, poll_after_seconds: pollAfterSeconds };
        });
      } catch (error) {
        if (cancelled) return;
        if (error instanceof ApiClientError && [401, 404, 410, 422].includes(error.status)) {
          setPairingRequest(null);
          setPairingStartGeneration((current) => current + 1);
          return;
        }
        setPairingError(errorMessage(error, 'De goedkeuring kon niet worden gecontroleerd. Er wordt automatisch opnieuw geprobeerd.'));
      }
      timer = setTimeout(() => void pollPairing(), pollMilliseconds);
    };

    timer = setTimeout(() => void pollPairing(), pollMilliseconds);
    return () => {
      cancelled = true;
      if (timer !== null) clearTimeout(timer);
    };
  }, [pairingRequest, sessionStatus]);

  useEffect(() => {
    if (sessionStatus !== 'unpaired' || pairingRequest === null) return undefined;
    const timer = window.setInterval(() => setClock(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, [pairingRequest, sessionStatus]);

  useEffect(() => {
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | null = null;

    const poll = async () => {
      try {
        const response = await wallboardApi.get<WallboardState>('/wallboard/state');
        if (cancelled) return;
        const nextState = normalizeWallboardState(response.data);
        setState(nextState);
        const nextControl = controlFromState(nextState);
        setControl((current) => current !== null && current.control_version > nextControl.control_version
          ? current
          : stabilizeWallboardRotationDeadline(current, nextControl));
        if (lastControlIncidentActiveRef.current === null) {
          lastControlIncidentActiveRef.current = nextControl.display.incident_active;
        }
        configVersionRef.current = nextState.wallboard.config_version;
        if (pendingConfigVersionRef.current === nextState.wallboard.config_version) pendingConfigVersionRef.current = null;
        refreshSecondsRef.current = clampRefreshSeconds(nextState.wallboard.configuration.refresh_seconds);
        setClock(Date.now());
        setSessionStatus('paired');
        setStateError(null);
        timer = setTimeout(() => void poll(), refreshSecondsRef.current * 1000);
      } catch (error) {
        if (cancelled) return;
        if (error instanceof ApiClientError && [401, 403].includes(error.status)) {
          setState(null);
          setControl(null);
          setSessionStatus('unpaired');
          setStateError(null);
          setControlError(null);
          lastControlIncidentActiveRef.current = null;
          return;
        }
        setSessionStatus((current) => current === 'checking' ? 'checking' : 'paired');
        setStateError(errorMessage(error, 'De wallboardfeed is tijdelijk niet bereikbaar.'));
        timer = setTimeout(() => void poll(), refreshSecondsRef.current * 1000);
      }
    };

    void poll();
    return () => {
      cancelled = true;
      if (timer !== null) clearTimeout(timer);
    };
  }, [pollGeneration]);

  useEffect(() => {
    if (!hasPairedState) return undefined;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | null = null;

    const pollControl = async () => {
      try {
        const response = await wallboardApi.get<WallboardControlState>('/wallboard/control');
        if (cancelled) return;
        const nextControl = normalizeWallboardControlState(response.data);
        setControl((current) => stabilizeWallboardRotationDeadline(current, nextControl));
        setClock(Date.now());
        setControlError(null);
        let needsStateRefresh = false;
        if (
          configVersionRef.current !== nextControl.config_version
          && pendingConfigVersionRef.current !== nextControl.config_version
        ) {
          pendingConfigVersionRef.current = nextControl.config_version;
          needsStateRefresh = true;
        }
        if (
          lastControlIncidentActiveRef.current !== null
          && lastControlIncidentActiveRef.current !== nextControl.display.incident_active
        ) {
          needsStateRefresh = true;
        }
        lastControlIncidentActiveRef.current = nextControl.display.incident_active;
        if (needsStateRefresh) setPollGeneration((current) => current + 1);
        timer = setTimeout(() => void pollControl(), CONTROL_POLL_MILLISECONDS);
      } catch (error) {
        if (cancelled) return;
        if (error instanceof ApiClientError && [401, 403].includes(error.status)) {
          setState(null);
          setControl(null);
          setSessionStatus('unpaired');
          setStateError(null);
          setControlError(null);
          lastControlIncidentActiveRef.current = null;
          return;
        }
        setControlError(errorMessage(error, 'De live schermbesturing is tijdelijk niet bereikbaar.'));
        timer = setTimeout(() => void pollControl(), CONTROL_POLL_MILLISECONDS);
      }
    };

    void pollControl();
    return () => {
      cancelled = true;
      if (timer !== null) clearTimeout(timer);
    };
  }, [hasPairedState]);

  useEffect(() => {
    if (!hasPairedState) return undefined;
    setClock(Date.now());
    const timer = window.setInterval(() => setClock(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, [hasPairedState]);

  const wallboardTheme = state?.wallboard.configuration.theme;
  const wallboardName = state?.wallboard.name;

  useEffect(() => {
    if (wallboardTheme === undefined || wallboardName === undefined) return undefined;
    const previousTheme = document.documentElement.dataset.theme;
    const previousTitle = document.title;
    document.documentElement.dataset.theme = wallboardTheme;
    document.title = `${wallboardName} · DIS Wallboard`;
    return () => {
      if (previousTheme === undefined) delete document.documentElement.dataset.theme;
      else document.documentElement.dataset.theme = previousTheme;
      document.title = previousTitle;
    };
  }, [wallboardName, wallboardTheme]);

  const requestWakeLock = useCallback(async () => {
    if (document.visibilityState !== 'visible') return;
    try {
      const sentinel = await navigator.wakeLock.request('screen');
      wakeLockRef.current = sentinel;
      setWakeLockActive(true);
      sentinel.addEventListener('release', () => {
        if (wakeLockRef.current === sentinel) {
          wakeLockRef.current = null;
          setWakeLockActive(false);
        }
      });
    } catch {
      setWakeLockActive(false);
    }
  }, []);

  useEffect(() => {
    const onFullscreenChange = () => setFullscreen(document.fullscreenElement === rootRef.current);
    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible' && fullscreen && wakeLockRef.current === null) void requestWakeLock();
    };
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('visibilitychange', onVisibilityChange);
    return () => {
      document.removeEventListener('fullscreenchange', onFullscreenChange);
      document.removeEventListener('visibilitychange', onVisibilityChange);
      void wakeLockRef.current?.release();
      wakeLockRef.current = null;
    };
  }, [fullscreen, requestWakeLock]);

  async function toggleFullscreen() {
    if (document.fullscreenElement === rootRef.current) {
      await document.exitFullscreen().catch(() => undefined);
      await wakeLockRef.current?.release().catch(() => undefined);
      return;
    }
    await rootRef.current?.requestFullscreen().catch(() => undefined);
    await requestWakeLock();
  }

  if (sessionStatus === 'checking' && state === null) {
    return (
      <main className="wallboard-pairing-screen" aria-busy="true">
        <Loader2 className="spin" size={28} aria-hidden />
        <strong>Wallboard controleren</strong>
        <span>Beveiligde schermkoppeling wordt geladen.</span>
      </main>
    );
  }

  if (sessionStatus === 'unpaired') {
    const codeGroups = pairingRequest === null ? [] : pairingCodeGroups(pairingRequest.code);
    const secondsRemaining = pairingRequest?.expires_at ? pairingSecondsRemaining(pairingRequest.expires_at, clock) : null;
    return (
      <main className="wallboard-pairing-screen">
        <section className="wallboard-pairing-card" aria-labelledby="wallboard-pairing-title" aria-busy={pairingRequest === null && pairingError === null}>
          <span className="wallboard-pairing-card__icon"><MonitorUp size={30} aria-hidden /></span>
          <span className="eyebrow">DIS Wallboard</span>
          <h1 id="wallboard-pairing-title">Koppel deze tv</h1>
          <p>Open als beheerder <strong>Wallboards</strong>, kies het juiste scherm en vul deze code in.</p>

          {pairingRequest ? (
            <>
              <div className="wallboard-pairing-code-display" aria-label={`Koppelcode ${pairingRequest.code}`}>
                {codeGroups.map((group, index) => <span key={`${group}-${index}`}>{group}</span>)}
              </div>
              <div className="wallboard-pairing-waiting" role="status" aria-live="polite">
                <span><i aria-hidden /> Wacht op goedkeuring door beheer</span>
                {pairingRequest.expires_at && secondsRemaining !== null ? (
                  <time dateTime={pairingRequest.expires_at}>Code geldig: {formatPairingCountdown(secondsRemaining)}</time>
                ) : (
                  <span>Geldigheid wordt door de server bewaakt</span>
                )}
              </div>
            </>
          ) : (
            <div className="wallboard-pairing-loading" role="status">
              <Loader2 className="spin" size={30} aria-hidden />
              <strong>{pairingError ? 'Koppelcode tijdelijk niet beschikbaar' : 'Koppelcode maken'}</strong>
              <span>{pairingError ?? 'De beveiligde koppeling wordt voorbereid.'}</span>
            </div>
          )}

          {pairingError ? (
            <button className="secondary-button wallboard-pairing-retry" type="button" onClick={() => setPairingStartGeneration((current) => current + 1)}>
              <RefreshCw size={18} aria-hidden /> Nu opnieuw proberen
            </button>
          ) : null}
          <small>Er is geen toetsenbord nodig. Na goedkeuring opent het wallboard vanzelf.</small>
        </section>
      </main>
    );
  }

  if (state === null || presentation === null) return null;
  const configuration = state.wallboard.configuration;
  const effectiveControl = control ?? controlFromState(state);
  const displayProfile = normalizeWallboardDisplayProfile(effectiveControl.display_profile);
  const display = effectiveControl.display;
  const currentPage = configuration.pages.find((page) => page.id === display.page_id) ?? configuration.pages[0];
  const currentPageIndex = configuration.pages.findIndex((page) => page.id === currentPage.id);
  const nextSwitchLabel = display.mode === 'rotation' && display.next_change_at
    ? `Volgende pagina over ${formatRemaining(display.next_change_at, clock)}`
    : displayModeLabel(display.mode);
  const transientAlert = hasLiveFeed ? effectiveControl.transient_alert : null;
  const showTransientAlert = wallboardTransientAlertIsActive(transientAlert, clock);
  const persistentAlarmFocus = hasLiveFeed && state.operational_summary.active_alarm !== null;
  const showTicker = hasLiveFeed
    && !showTransientAlert
    && !persistentAlarmFocus
    && state.ticker.items.length > 0;

  return (
    <main
      className={`wallboard-display wallboard-display--${configuration.theme} wallboard-display--profile-${displayProfile}`}
      data-display-profile={displayProfile}
      ref={rootRef}
    >
      <header className="wallboard-display__header">
        <div>
          <span className={`wallboard-display__live wallboard-display__live--${feedStatus}`}>
            <i aria-hidden />
            {feedStatus === 'offline' ? 'Offline' : feedStatus === 'stale' ? 'Verouderd' : 'Live'}
          </span>
          <span className="wallboard-display__titles">
            <small>{state.wallboard.name}</small>
            <h1>{currentPage.name}</h1>
          </span>
          <span className={`wallboard-display__mode wallboard-display__mode--${display.mode}`}>
            {display.mode === 'incident_override' ? <LockKeyhole size={14} aria-hidden /> : <RotateCw size={14} aria-hidden />}
            {displayModeLabel(display.mode)}
          </span>
        </div>
        <div className="wallboard-display__controls">
          <time
            className="wallboard-display__clock"
            dateTime={new Date(clock).toISOString()}
            aria-label={`Lokale tijd ${formatWallboardClock(clock)}, ${formatWallboardDate(clock)}`}
          >
            <Clock3 size={20} aria-hidden />
            <span>{formatWallboardClock(clock)}</span>
            <small>{formatWallboardDate(clock)}</small>
          </time>
          <button className="wallboard-display__control" type="button" onClick={() => setPollGeneration((current) => current + 1)} aria-label="Wallboard nu vernieuwen">
            <RefreshCw size={18} aria-hidden />
          </button>
          <button className="wallboard-display__control" type="button" onClick={() => void toggleFullscreen()} aria-label={fullscreen ? 'Volledig scherm verlaten' : 'Volledig scherm openen'}>
            <Expand size={18} aria-hidden />
            <span>{fullscreen ? 'Scherm verlaten' : 'Volledig scherm'}</span>
          </button>
        </div>
      </header>

      {!hasLiveFeed ? (
        <div className="wallboard-display__connection-warning" role="status" aria-live="polite">
          {connectionError ? <WifiOff size={18} aria-hidden /> : <AlertTriangle size={18} aria-hidden />}
          <span>
            <strong>{connectionError ? 'Offline — laatst bekende informatie' : 'Informatie is verouderd'}</strong>
            <small>{connectionError ?? `Laatste serverupdate ${formatDateTime(state.generated_at)}.`}</small>
          </span>
        </div>
      ) : null}

      {showTransientAlert && transientAlert !== null ? (
        <TransientAlertTakeover alert={transientAlert} />
      ) : (
        <section
          className="wallboard-display__page"
          key={`${state.wallboard.config_version}:${effectiveControl.control_version}:${currentPage.id}`}
          aria-label={currentPage.name}
          aria-live="polite"
        >
          <WallboardPageContent
            page={currentPage}
            state={state}
            presentation={presentation}
            hasLiveFeed={hasLiveFeed}
          />
        </section>
      )}

      <footer className="wallboard-display__footer">
        <span>Pagina {Math.max(1, currentPageIndex + 1)} van {configuration.pages.length} · {nextSwitchLabel}</span>
        <span>{wakeLockActive ? 'Scherm blijft actief' : 'Gebruik volledig scherm om slaapstand te voorkomen'}</span>
      </footer>
      {showTicker ? <WallboardTicker items={state.ticker.items} /> : null}
    </main>
  );
}

interface WallboardPageContentProps {
  page: WallboardPage;
  state: WallboardState;
  presentation: ReturnType<typeof buildWallboardMapPresentation>;
  hasLiveFeed: boolean;
}

function WallboardPageContent({ page, state, presentation, hasLiveFeed }: WallboardPageContentProps) {
  const configuration = state.wallboard.configuration.map;
  const pagePresentation = ['incident_list', 'summary'].includes(page.type)
    ? buildWallboardMapPresentation(
      state,
      hasLiveFeed,
      wallboardPageMapConfiguration(state.wallboard.configuration, page),
    )
    : presentation;
  const includeTestIncidents = ['incident_list', 'summary'].includes(page.type)
    ? page.options.show_test_incidents === true
    : configuration.show_test_incidents;
  const recentIncidents = selectRecentWallboardIncidents(
    state.operational_summary.recent_incidents,
    includeTestIncidents,
  );
  if (page.type === 'message') {
    return (
      <div className="wallboard-display__message">
        <span className="eyebrow">Mededeling</span>
        <h2>{page.name}</h2>
        <p>{page.options.body?.trim() || 'Er is geen tekst voor deze mededeling ingesteld.'}</p>
      </div>
    );
  }

  if (page.type === 'summary') {
    return (
      <div className="wallboard-display__overview">
        <Summary label="Actieve incidenten" value={pagePresentation.models.length} emphasis />
        <PilotAvailabilityMetric availability={state.operational_summary.pilot_availability} isCurrent={hasLiveFeed} />
        <Summary label="Meldkamers" value={pagePresentation.layers.commandCenters.length} />
        <Summary label="Laatste meldingen" value={recentIncidents.length} />
        <RecentIncidentList incidents={recentIncidents} />
      </div>
    );
  }

  if (page.type === 'incident_list') {
    return (
      <div className="wallboard-display__list-page">
        <header><span>Actieve meldingen</span><strong>{pagePresentation.models.length}</strong></header>
        <IncidentCards state={state} presentation={pagePresentation} hasLiveFeed={hasLiveFeed} />
      </div>
    );
  }

  return (
    <>
      {configuration.show_summary ? (
        <section className="wallboard-display__summary" aria-label="Operationele samenvatting">
          <Summary label="Actieve incidenten" value={presentation.models.length} />
          <PilotAvailabilityMetric availability={state.operational_summary.pilot_availability} isCurrent={hasLiveFeed} />
          <Summary label="Meldkamers" value={presentation.layers.commandCenters.length} />
          <Summary label="Laatste meldingen" value={recentIncidents.length} />
        </section>
      ) : null}

      <div className={`wallboard-display__content ${configuration.show_incident_list ? 'wallboard-display__content--with-list' : ''}`}>
        <section className="wallboard-display__map" aria-label="Operationele kaart">
          <OperationalMapCanvas
            models={presentation.models}
            layers={presentation.layers}
            layerVisibility={{
              commandCenters: configuration.show_command_centers,
              historicalIncidents: configuration.show_historical_incidents,
              pilotHomes: false,
            }}
            showRoutes={configuration.show_routes && hasLiveFeed}
            showRouteLegend={configuration.show_route_legend && hasLiveFeed}
            autoFit={configuration.auto_fit}
          />
        </section>

        {configuration.show_incident_list ? (
          <aside className="wallboard-display__incidents" aria-label="Actieve incidenten">
            <header><span>Actieve meldingen</span><strong>{presentation.models.length}</strong></header>
            <IncidentCards state={state} presentation={presentation} hasLiveFeed={hasLiveFeed} />
          </aside>
        ) : null}
      </div>
    </>
  );
}

function TransientAlertTakeover({ alert }: { alert: WallboardTransientAlert }) {
  const alertLabel = alert.is_test ? 'Proefalarmering' : 'Nieuwe alarmering';

  return (
    <section
      className={`wallboard-display__alarm ${alert.is_test ? 'wallboard-display__alarm--test' : ''}`}
      key={alert.dispatch_id}
      role="alert"
      aria-label={`${alertLabel} ${alert.reference}: ${alert.title}`}
    >
      <span className="wallboard-display__alarm-icon" aria-hidden><Siren size={54} strokeWidth={1.8} /></span>
      <span className="wallboard-display__alarm-eyebrow">{alertLabel}</span>
      <div className="wallboard-display__alarm-meta">
        <strong>{alert.reference}</strong>
        <span>{priorityLabel(alert.priority)}</span>
        {alert.is_test ? <b>TEST</b> : null}
      </div>
      <h2>{alert.title}</h2>
      <p><MapPin size={24} aria-hidden /> {alert.location_label?.trim() || 'Locatie nog niet bekend'}</p>
      <div className="wallboard-display__alarm-status">
        <i aria-hidden />
        <strong>{alert.is_test ? 'Proefalarm ontvangen' : 'Piloten worden gealarmeerd'}</strong>
        <time dateTime={alert.received_at}>Binnengekomen {formatDateTime(alert.received_at)}</time>
      </div>
    </section>
  );
}

function PilotAvailabilityMetric({
  availability,
  isCurrent,
}: {
  availability: WallboardPilotAvailability;
  isCurrent: boolean;
}) {
  const hasValidCounts = isCurrent
    && Number.isFinite(availability.available)
    && Number.isFinite(availability.total)
    && availability.available >= 0
    && availability.total >= 0;
  const total = hasValidCounts ? Math.max(0, Math.trunc(availability.total)) : null;
  const available = hasValidCounts && total !== null
    ? Math.min(total, Math.max(0, Math.trunc(availability.available)))
    : null;
  const pilotLabel = total === 1 ? 'piloot' : 'piloten';

  return (
    <div
      className="wallboard-display__metric wallboard-display__metric--availability"
      aria-label={formatWallboardPilotAvailability(availability, hasValidCounts)}
    >
      <small>Operationeel beschikbaar</small>
      <strong>
        <b>{available ?? '—'}</b>
        <span>van {total ?? '—'} {pilotLabel}</span>
      </strong>
    </div>
  );
}

function RecentIncidentList({ incidents }: { incidents: WallboardStateRecentIncident[] }) {
  return (
    <section className="wallboard-display__recent" aria-labelledby="wallboard-recent-incidents-title">
      <header>
        <span>
          <small>Recent afgesloten</small>
          <strong id="wallboard-recent-incidents-title">Laatste meldingen</strong>
        </span>
        <b>{incidents.length}</b>
      </header>
      {incidents.length === 0 ? (
        <p>Er zijn nog geen laatste meldingen om te tonen.</p>
      ) : (
        <ul>
          {incidents.map((incident) => (
            <li key={incident.id}>
              <span className="wallboard-display__recent-reference">{incident.reference}</span>
              <strong>{incident.title}</strong>
              <span>
                {incident.is_test ? <b className="wallboard-display__recent-test">TEST</b> : null}
                {incident.location_label?.trim() || 'Locatie niet vastgelegd'}
              </span>
              <time dateTime={incident.closed_at ?? undefined}>{formatRecentIncidentTime(incident.closed_at)}</time>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function WallboardTicker({ items }: { items: WallboardTickerItem[] }) {
  const durationSeconds = wallboardTickerDurationSeconds(items);
  const style = { '--wallboard-ticker-duration': `${durationSeconds}s` } as CSSProperties;

  return (
    <section className="wallboard-display__ticker" aria-labelledby="wallboard-ticker-title">
      <h2 className="sr-only" id="wallboard-ticker-title">Actuele berichten</h2>
      <ul className="sr-only">
        {items.map((item, index) => <li key={`accessible-${item.source_id}-${index}`}>{item.source_label}: {item.text}</li>)}
      </ul>
      <strong className="wallboard-display__ticker-label">Actueel</strong>
      <div className="wallboard-display__ticker-viewport" aria-hidden>
        <div className="wallboard-display__ticker-track" style={style}>
          <WallboardTickerGroup items={items} />
          <WallboardTickerGroup items={items} duplicate />
        </div>
      </div>
    </section>
  );
}

function WallboardTickerGroup({ items, duplicate = false }: { items: WallboardTickerItem[]; duplicate?: boolean }) {
  return (
    <span className="wallboard-display__ticker-group">
      {items.map((item, index) => (
        <span className="wallboard-display__ticker-item" key={`${duplicate ? 'copy' : 'original'}-${item.source_id}-${index}`}>
          <strong>{item.source_label}</strong>
          <span>{item.text}</span>
        </span>
      ))}
    </span>
  );
}

function IncidentCards({
  state,
  presentation,
  hasLiveFeed,
}: Pick<WallboardPageContentProps, 'state' | 'presentation' | 'hasLiveFeed'>) {
  return (
    <div>
      {presentation.models.length === 0 ? <p>Geen actieve incidenten.</p> : presentation.models.map((model) => {
        const incident = state.map.incidents.find((item) => item.id === model.incident.id);
        return (
          <article key={model.incident.id} style={{ '--wallboard-incident-color': model.color } as CSSProperties}>
            <span className="wallboard-display__incident-reference">
              {incident?.reference ?? 'Incident'}
              {incident?.is_test ? <b>TEST</b> : null}
            </span>
            <h2>{model.incident.title}</h2>
            <p>{incident?.location_label ?? 'Locatie onbekend'}</p>
            <footer>
              <span>{priorityLabel(incident?.priority)}</span>
              <span>{hasLiveFeed ? `${model.liveLocations.length} live op kaart` : 'Locatiestatus onbekend'}</span>
            </footer>
          </article>
        );
      })}
    </div>
  );
}

function Summary({ label, value, emphasis = false }: { label: string; value: number | string; emphasis?: boolean }) {
  return <div className={emphasis ? 'wallboard-display__metric wallboard-display__metric--emphasis' : 'wallboard-display__metric'}><small>{label}</small><strong>{value}</strong></div>;
}

export function normalizeWallboardState(state: WallboardState): WallboardState {
  const configuration = wallboardConfigurationCopy(state.wallboard.configuration);
  const rawState = state as WallboardState & {
    operational_summary?: WallboardState['operational_summary'];
    ticker?: WallboardState['ticker'];
  };
  const operationalSummary = rawState.operational_summary;
  const rawWallboard = state.wallboard as WallboardState['wallboard'] & {
    control_version?: number;
    display_profile?: unknown;
    display?: NonNullable<WallboardState['wallboard']['display']>;
  };
  const fallbackMode: WallboardDisplayMode = !configuration.rotation_enabled || configuration.pages.length <= 1 ? 'static' : 'rotation';
  const display = rawWallboard.display ?? {
    mode: fallbackMode,
    page_id: configuration.pages[0].id,
    incident_active: false,
    next_change_at: null,
  };
  const pageId = configuration.pages.some((page) => page.id === display.page_id)
    ? display.page_id
    : configuration.pages[0].id;

  return {
    ...state,
    operational_summary: {
      pilot_availability: operationalSummary?.pilot_availability
        ?? { available: Number.NaN, total: Number.NaN },
      active_alarm: operationalSummary?.active_alarm ?? null,
      recent_incidents: operationalSummary?.recent_incidents ?? [],
      transient_alert: operationalSummary?.transient_alert ?? null,
    },
    ticker: rawState.ticker ?? { items: [] },
    wallboard: {
      ...state.wallboard,
      display_profile: normalizeWallboardDisplayProfile(rawWallboard.display_profile),
      configuration,
      control_version: rawWallboard.control_version ?? 1,
      display: { ...display, page_id: pageId },
    },
  };
}

function controlFromState(state: WallboardState): WallboardControlState {
  const fallbackMode: WallboardDisplayMode = !state.wallboard.configuration.rotation_enabled
    || state.wallboard.configuration.pages.length <= 1
    ? 'static'
    : 'rotation';

  return {
    config_version: state.wallboard.config_version,
    control_version: state.wallboard.control_version ?? 1,
    display_profile: normalizeWallboardDisplayProfile(state.wallboard.display_profile),
    transient_alert: state.operational_summary.transient_alert,
    display: state.wallboard.display ?? {
      mode: fallbackMode,
      page_id: state.wallboard.configuration.pages[0].id,
      incident_active: false,
      next_change_at: null,
    },
  };
}

function normalizeWallboardControlState(state: WallboardControlState): WallboardControlState {
  const legacyState = state as WallboardControlState & {
    display_profile?: unknown;
    transient_alert?: WallboardTransientAlert | null;
  };
  return {
    ...state,
    display_profile: normalizeWallboardDisplayProfile(legacyState.display_profile),
    transient_alert: legacyState.transient_alert ?? null,
  };
}

export function stabilizeWallboardRotationDeadline(
  current: WallboardControlState | null,
  next: WallboardControlState,
  now: number = Date.now(),
): WallboardControlState {
  // A control/config version identifies one server-controlled rotation phase.
  // Polling that phase may never postpone its still-pending page deadline.
  if (
    current === null
    || current.config_version !== next.config_version
    || current.control_version !== next.control_version
    || current.display.mode !== 'rotation'
    || next.display.mode !== 'rotation'
    || current.display.page_id !== next.display.page_id
  ) {
    return stabilizeWallboardTransientAlert(current, next, now);
  }

  const currentDeadline = current.display.next_change_at;
  const nextDeadline = next.display.next_change_at;
  if (currentDeadline === null || currentDeadline === undefined || nextDeadline === null || nextDeadline === undefined) {
    return stabilizeWallboardTransientAlert(current, next, now);
  }

  const currentDeadlineMilliseconds = Date.parse(currentDeadline);
  const nextDeadlineMilliseconds = Date.parse(nextDeadline);
  if (
    !Number.isFinite(currentDeadlineMilliseconds)
    || !Number.isFinite(nextDeadlineMilliseconds)
    || currentDeadlineMilliseconds <= now
    || nextDeadlineMilliseconds <= currentDeadlineMilliseconds
  ) {
    return stabilizeWallboardTransientAlert(current, next, now);
  }

  return stabilizeWallboardTransientAlert(current, {
    ...next,
    display: {
      ...next.display,
      next_change_at: currentDeadline,
    },
  }, now);
}

function stabilizeWallboardTransientAlert(
  current: WallboardControlState | null,
  next: WallboardControlState,
  now: number,
): WallboardControlState {
  const currentAlert = current?.transient_alert ?? null;
  const nextAlert = next.transient_alert;
  if (currentAlert === null || !wallboardTransientAlertIsActive(currentAlert, now)) return next;
  if (nextAlert === null) return { ...next, transient_alert: currentAlert };

  const currentReceivedAt = Date.parse(currentAlert.received_at);
  const nextReceivedAt = Date.parse(nextAlert.received_at);
  if (Number.isFinite(currentReceivedAt) && (!Number.isFinite(nextReceivedAt) || currentReceivedAt > nextReceivedAt)) {
    return { ...next, transient_alert: currentAlert };
  }

  return next;
}

function displayModeLabel(mode: WallboardDisplayMode): string {
  switch (mode) {
    case 'rotation': return 'Automatische rotatie';
    case 'static': return 'Vaste pagina';
    case 'manual': return 'Handmatig gestuurd';
    case 'incident_override': return 'Actief incident';
  }
}

function formatRemaining(value: string, now: number): string {
  const remainingSeconds = Math.max(0, Math.ceil((Date.parse(value) - now) / 1000));
  if (!Number.isFinite(remainingSeconds)) return 'onbekend';
  if (remainingSeconds < 60) return `${remainingSeconds} sec.`;
  const minutes = Math.floor(remainingSeconds / 60);
  const seconds = remainingSeconds % 60;
  return `${minutes}:${seconds.toString().padStart(2, '0')} min.`;
}

function pairingCodeGroups(value: string): string[] {
  const code = value.toUpperCase().replace(/[^A-Z0-9]/g, '');
  if (code === '') return [];
  const groupSize = code.length > 6 ? 4 : 3;
  return code.match(new RegExp(`.{1,${groupSize}}`, 'g')) ?? [code];
}

function requestWallboardPairing(): Promise<WallboardPairingRequest> {
  if (pairingStartInFlight !== null) return pairingStartInFlight;
  const request = wallboardApi
    .post<WallboardPairingRequest>('/wallboard/pairing/start')
    .then((response) => response.data)
    .finally(() => {
      if (pairingStartInFlight === request) pairingStartInFlight = null;
    });
  pairingStartInFlight = request;
  return request;
}

function pairingPollMilliseconds(value: number): number {
  if (!Number.isFinite(value)) return DEFAULT_PAIRING_POLL_MILLISECONDS;
  return Math.min(5000, Math.max(1000, Math.round(value * 1000)));
}

function pairingSecondsRemaining(expiresAt: string, now: number): number {
  const expires = Date.parse(expiresAt);
  return Number.isFinite(expires) ? Math.max(0, Math.ceil((expires - now) / 1000)) : 0;
}

function formatPairingCountdown(totalSeconds: number): string {
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${minutes}:${seconds.toString().padStart(2, '0')}`;
}

export function formatWallboardClock(value: number): string {
  const date = new Date(value);
  return Number.isFinite(date.getTime()) ? WALLBOARD_CLOCK_FORMATTER.format(date) : '--:--:--';
}

function formatWallboardDate(value: number): string {
  const date = new Date(value);
  return Number.isFinite(date.getTime()) ? WALLBOARD_DATE_FORMATTER.format(date) : 'Datum onbekend';
}

function formatRecentIncidentTime(value: string | null | undefined): string {
  if (!value) return 'Tijd niet vastgelegd';
  const date = new Date(value);
  return Number.isFinite(date.getTime()) ? RECENT_INCIDENT_TIME_FORMATTER.format(date) : 'Tijd niet vastgelegd';
}

function formatDateTime(value: string): string {
  const date = new Date(value);
  return Number.isFinite(date.getTime()) ? new Intl.DateTimeFormat('nl-NL', { dateStyle: 'short', timeStyle: 'medium' }).format(date) : 'onbekend';
}

function priorityLabel(priority: WallboardState['map']['incidents'][number]['priority'] | undefined): string {
  switch (priority) {
    case 'critical': return 'Kritiek';
    case 'high': return 'Hoog';
    case 'normal': return 'Normaal';
    case 'low': return 'Laag';
    default: return 'Onbekend';
  }
}

function errorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiClientError ? error.message : fallback;
}
