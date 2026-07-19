import {
  type CSSProperties,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import dynamic from 'next/dynamic.js';
import {
  AlertTriangle,
  BellRing,
  Clock3,
  ExternalLink,
  Expand,
  Loader2,
  LockKeyhole,
  MapPin,
  MonitorUp,
  Newspaper,
  RefreshCw,
  Radio,
  RotateCw,
  Siren,
  UsersRound,
  WifiOff,
} from 'lucide-react';
import { ApiClient, ApiClientError, apiBaseUrl } from '../../lib/apiClient';
import type {
  WallboardControlState,
  WallboardDisplayMode,
  WallboardFocusKind,
  WallboardFocusPilotCounts,
  WallboardFocusResponses,
  WallboardFocusState,
  WallboardMaintenanceNotice,
  WallboardPage,
  WallboardNewsItem,
  WallboardNewsPageState,
  WallboardNewsState,
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
  clampWallboardNewsItemDuration,
  countActiveOperationalWallboardIncidents,
  formatWallboardPilotAvailability,
  normalizeWallboardDisplayProfile,
  normalizeWallboardNewsItemTransition,
  selectRecentWallboardIncidents,
  wallboardConfigurationCopy,
  wallboardFocusKindLabel,
  wallboardMessageContent,
  wallboardPageMapConfiguration,
  wallboardStateIsStale,
  wallboardTickerDurationSeconds,
  wallboardTransientAlertIsActive,
  wallboardVideoEmbedUrl,
} from './wallboardPresentation';
import { WallboardNewsQrCode } from './WallboardNewsQrCode';
import { WallboardRichText } from './WallboardRichText';
import { normalizeWallboardMediaPageStates } from './wallboardMedia';
import { wallboardPhotoCarouselAnchorFromDeadline } from './wallboardPhotoRotation';

const wallboardApi = new ApiClient({ baseUrl: apiBaseUrl, onUnauthenticated: () => undefined });
const WallboardPhotoCarousel = dynamic(
  () => import('./WallboardPhotoCarousel').then((module) => module.WallboardPhotoCarousel),
  { ssr: false },
);
const CONTROL_POLL_MILLISECONDS = 2000;
const DEFAULT_PAIRING_POLL_MILLISECONDS = 2000;
const PAIRING_RETRY_MILLISECONDS = 10000;
export const WALLBOARD_REFRESH_VERSION_STORAGE_KEY = 'dis.wallboard.refresh-version';
const WALLBOARD_TIME_ZONE = 'Europe/Amsterdam';
const WALLBOARD_CLOCK_FORMATTER = new Intl.DateTimeFormat('nl-NL', {
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  hour12: false,
  timeZone: WALLBOARD_TIME_ZONE,
});
const WALLBOARD_DATE_FORMATTER = new Intl.DateTimeFormat('nl-NL', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  year: 'numeric',
  timeZone: WALLBOARD_TIME_ZONE,
});
const RECENT_INCIDENT_TIME_FORMATTER = new Intl.DateTimeFormat('nl-NL', {
  day: '2-digit',
  month: 'short',
  hour: '2-digit',
  minute: '2-digit',
  timeZone: WALLBOARD_TIME_ZONE,
});
const WALLBOARD_NEWS_DATE_FORMATTER = new Intl.DateTimeFormat('nl-NL', {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
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
  const lastControlFocusSignatureRef = useRef<string | undefined>(undefined);
  const refreshVersionRef = useRef<number | null>(null);
  const [sessionStatus, setSessionStatus] = useState<'checking' | 'unpaired' | 'paired'>('checking');
  const [state, setState] = useState<WallboardState | null>(null);
  const [control, setControl] = useState<WallboardControlState | null>(null);
  const [stateError, setStateError] = useState<string | null>(null);
  const [controlError, setControlError] = useState<string | null>(null);
  const [pairingRequest, setPairingRequest] = useState<WallboardPairingRequest | null>(null);
  const [pairingError, setPairingError] = useState<string | null>(null);
  const [pairingStartGeneration, setPairingStartGeneration] = useState(0);
  const [pollGeneration, setPollGeneration] = useState(0);
  const [controlPollGeneration, setControlPollGeneration] = useState(0);
  const [fullscreen, setFullscreen] = useState(false);
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
  const observeRefreshVersion = useCallback((incomingVersion: unknown): boolean => {
    const decision = wallboardRefreshDecision(
      refreshVersionRef.current,
      incomingVersion,
      readPersistedWallboardRefreshVersion(window.sessionStorage),
    );
    const isBaseline = refreshVersionRef.current === null;
    refreshVersionRef.current = decision.version;
    if (isBaseline || decision.reload) persistWallboardRefreshVersion(window.sessionStorage, decision.version);
    if (!decision.reload) return false;
    window.location.reload();
    return true;
  }, []);

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
        if (observeRefreshVersion(nextState.wallboard.refresh_version)) return;
        setState(nextState);
        const nextControl = controlFromState(nextState);
        setControl((current) => current !== null && current.control_version > nextControl.control_version
          ? current
          : stabilizeWallboardRotationDeadline(current, nextControl));
        if (lastControlIncidentActiveRef.current === null) {
          lastControlIncidentActiveRef.current = nextControl.display.incident_active;
        }
        if (lastControlFocusSignatureRef.current === undefined) {
          lastControlFocusSignatureRef.current = wallboardFocusSignature(nextControl.focus);
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
          lastControlFocusSignatureRef.current = undefined;
          refreshVersionRef.current = null;
          clearPersistedWallboardRefreshVersion(window.sessionStorage);
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
  }, [observeRefreshVersion, pollGeneration]);

  useEffect(() => {
    if (!hasPairedState) return undefined;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | null = null;

    const pollControl = async () => {
      try {
        const response = await wallboardApi.get<WallboardControlState>('/wallboard/control');
        if (cancelled) return;
        const nextControl = normalizeWallboardControlState(response.data);
        if (observeRefreshVersion(nextControl.refresh_version)) return;
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
        const nextFocusSignature = wallboardFocusSignature(nextControl.focus);
        if (
          lastControlFocusSignatureRef.current !== undefined
          && lastControlFocusSignatureRef.current !== nextFocusSignature
        ) {
          needsStateRefresh = true;
        }
        lastControlIncidentActiveRef.current = nextControl.display.incident_active;
        lastControlFocusSignatureRef.current = nextFocusSignature;
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
          lastControlFocusSignatureRef.current = undefined;
          refreshVersionRef.current = null;
          clearPersistedWallboardRefreshVersion(window.sessionStorage);
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
  }, [controlPollGeneration, hasPairedState, observeRefreshVersion]);

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
      sentinel.addEventListener('release', () => {
        if (wakeLockRef.current === sentinel) {
          wakeLockRef.current = null;
        }
      });
    } catch {
      // Fullscreen blijft bruikbaar wanneer de browser geen wake lock toestaat.
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

  const requestWallboardFullscreen = useCallback(async (): Promise<boolean> => {
    const root = rootRef.current;
    if (root === null || typeof root.requestFullscreen !== 'function') return false;
    if (document.fullscreenElement === root) {
      await requestWakeLock();
      return true;
    }
    if (document.fullscreenElement !== null) return false;

    try {
      await root.requestFullscreen({ navigationUI: 'hide' });
      await requestWakeLock();
      return document.fullscreenElement === root;
    } catch {
      // Browsers normally require a user gesture. The first interaction below
      // retries while it still carries that activation.
      return false;
    }
  }, [requestWakeLock]);

  useEffect(() => {
    let active = true;

    const detachFallbacks = () => {
      document.removeEventListener('pointerdown', onFirstPointerDown, true);
      document.removeEventListener('keydown', onFirstKeyDown, true);
      document.removeEventListener('fullscreenchange', onFullscreenEntered);
    };
    const attemptFullscreen = () => {
      if (!active) return;
      void requestWallboardFullscreen().then((succeeded) => {
        if (active && succeeded) detachFallbacks();
      });
    };
    function onFirstPointerDown(event: PointerEvent) {
      if (event.target instanceof Element && event.target.closest('[data-wallboard-fullscreen-toggle]') !== null) return;
      attemptFullscreen();
    }
    function onFirstKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') return;
      attemptFullscreen();
    }
    function onFullscreenEntered() {
      if (document.fullscreenElement === rootRef.current) detachFallbacks();
    }

    document.addEventListener('pointerdown', onFirstPointerDown, true);
    document.addEventListener('keydown', onFirstKeyDown, true);
    document.addEventListener('fullscreenchange', onFullscreenEntered);
    attemptFullscreen();

    return () => {
      active = false;
      detachFallbacks();
    };
  }, [requestWallboardFullscreen]);

  const runtimeControl = state === null ? null : (control ?? controlFromState(state));
  const deadlineControl = runtimeControl === null || runtimeControl.focus === undefined
    ? runtimeControl
    : {
        ...runtimeControl,
        focus: hasLiveFeed && !wallboardTransientFocusIsComplete(runtimeControl.focus)
          ? runtimeControl.focus
          : null,
      };
  const deadlinePhase = wallboardControlDeadlinePhase(deadlineControl);
  const deadlineDurationMilliseconds = useMemo(
    () => wallboardDeadlineDurationMilliseconds(deadlinePhase?.deadlineAt ?? null),
    [deadlinePhase?.deadlineAt],
  );
  const refreshExpiredDeadline = useCallback(() => {
    setControlPollGeneration((current) => current + 1);
  }, []);
  const deadlineCountdown = usePausableWallboardDeadline(
    deadlinePhase?.key ?? null,
    deadlineDurationMilliseconds,
    hasLiveFeed,
    refreshExpiredDeadline,
  );

  if (sessionStatus === 'checking' && state === null) {
    return (
      <main className="wallboard-pairing-screen" aria-busy="true" ref={rootRef}>
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
      <main className="wallboard-pairing-screen" ref={rootRef}>
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

        </section>
      </main>
    );
  }

  if (state === null || presentation === null) return null;
  const configuration = state.wallboard.configuration;
  const effectiveControl = runtimeControl ?? controlFromState(state);
  const maintenance = wallboardMaintenanceNoticeIsActive(effectiveControl.maintenance, clock)
    ? normalizeWallboardMaintenanceNotice(effectiveControl.maintenance)
    : null;
  const displayProfile = normalizeWallboardDisplayProfile(effectiveControl.display_profile);
  const display = effectiveControl.display;
  const focusCandidate = hasLiveFeed ? (effectiveControl.focus ?? null) : null;
  const focus = wallboardTransientFocusIsComplete(focusCandidate) ? null : focusCandidate;
  const focusPlaylistPageId = focus !== null && !focus.visible ? focus.playlist_page_id : null;
  const currentPageId = focusPlaylistPageId ?? display.page_id;
  const currentPage = configuration.pages.find((page) => page.id === currentPageId)
    ?? configuration.pages.find((page) => page.id === display.page_id)
    ?? configuration.pages[0];
  const currentPageIndex = configuration.pages.findIndex((page) => page.id === currentPage.id);
  const nextSwitchLabel = wallboardNextSwitchLabel(focus, display, clock, deadlineCountdown);
  const transientAlert = hasLiveFeed && effectiveControl.focus === undefined
    ? effectiveControl.transient_alert
    : null;
  const showTransientAlert = wallboardTransientAlertIsActive(transientAlert, clock)
    && !(transientAlert?.is_test === true && state.operational_summary.active_alarm !== null);
  const showFocus = focus?.visible === true;
  const showTicker = maintenance === null && wallboardTickerIsVisible(
    hasLiveFeed,
    focus,
    showTransientAlert,
    state.operational_summary.active_alarm !== null,
    state.ticker.items.length,
  );

  return (
    <main
      className={`wallboard-display wallboard-display--${configuration.theme} wallboard-display--profile-${displayProfile}`}
      data-display-profile={displayProfile}
      ref={rootRef}
    >
      <header className="wallboard-display__header">
        <div>
          <span className="wallboard-display__titles">
            <h1>{maintenance?.title ?? (showFocus && focus !== null
              ? wallboardFocusKindLabel(focus.kind)
              : currentPage.type === 'message' ? 'Mededeling' : currentPage.name)}</h1>
          </span>
          {showFocus ? null : (
            <span className={`wallboard-display__mode wallboard-display__mode--${maintenance !== null ? 'maintenance' : display.mode}`}>
              {maintenance !== null
                ? <RefreshCw size={14} aria-hidden />
                : display.mode === 'incident_override'
                  ? <LockKeyhole size={14} aria-hidden />
                  : <RotateCw size={14} aria-hidden />}
              {maintenance !== null ? 'Onderhoud' : displayModeLabel(display.mode)}
            </span>
          )}
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
          {!fullscreen ? (
            <button className="wallboard-display__control" type="button" data-wallboard-fullscreen-toggle onClick={() => void requestWallboardFullscreen()} aria-label="Volledig scherm openen">
              <Expand size={18} aria-hidden />
              <span>Volledig scherm</span>
            </button>
          ) : null}
        </div>
      </header>

      {!hasLiveFeed && maintenance === null ? (
        <div className="wallboard-display__connection-warning" role="status" aria-live="polite">
          {connectionError ? <WifiOff size={18} aria-hidden /> : <AlertTriangle size={18} aria-hidden />}
          <span>
            <strong>{connectionError ? 'Offline — laatst bekende informatie' : 'Informatie is verouderd'}</strong>
            <small>{connectionError ?? `Laatste serverupdate ${formatDateTime(state.generated_at)}.`}</small>
          </span>
        </div>
      ) : null}

      {maintenance !== null ? (
        <MaintenanceTakeover notice={maintenance} />
      ) : showFocus && focus !== null ? (
        <FocusTakeover
          focus={focus}
          fallbackPilotAvailability={state.operational_summary.pilot_availability}
          isCurrent={hasLiveFeed}
          showResponseFeed={configuration.focus[focus.kind].show_response_feed}
        />
      ) : showTransientAlert && transientAlert !== null ? (
        <TransientAlertTakeover alert={transientAlert} />
      ) : (
        <section
          className={configuration.page_fade_enabled
            ? 'wallboard-display__page wallboard-display__page--fade'
            : 'wallboard-display__page'}
          key={`${state.wallboard.config_version}:${effectiveControl.control_version}:${focus?.focus_id ?? 'base'}:${focus?.visible ?? false}:${currentPage.id}`}
          aria-label={currentPage.name}
          aria-live="polite"
        >
          <WallboardPageContent
            page={currentPage}
            state={state}
            presentation={presentation}
            hasLiveFeed={hasLiveFeed}
            pageDeadlineAt={focus?.next_change_at ?? display.next_change_at ?? null}
          />
        </section>
      )}

      <footer className="wallboard-display__footer">
        <span>{maintenance !== null
          ? 'Onderhoud actief · het wallboard herstelt automatisch'
          : focus !== null && focus.visible
          ? nextSwitchLabel
          : `Pagina ${Math.max(1, currentPageIndex + 1)} van ${configuration.pages.length} · ${nextSwitchLabel}`}</span>
      </footer>
      {showTicker ? <WallboardTicker items={state.ticker.items} /> : null}
    </main>
  );
}

function MaintenanceTakeover({ notice }: { notice: WallboardMaintenanceNotice }) {
  return (
    <section
      className="wallboard-display__alarm wallboard-display__alarm--maintenance"
      role="status"
      aria-live="assertive"
      aria-atomic="true"
    >
      <span className="wallboard-display__alarm-icon"><RefreshCw className="spin" size={58} aria-hidden /></span>
      <span className="wallboard-display__alarm-eyebrow">
        {notice.kind === 'update' ? 'Systeemupdate' : 'Gepland onderhoud'}
      </span>
      <h2>{notice.title}</h2>
      <p>{notice.message}</p>
      <div className="wallboard-display__alarm-status">
        <i aria-hidden />
        <strong>Automatisch herstel is actief</strong>
        <time dateTime={notice.started_at}>Gestart {formatDateTime(notice.started_at)}</time>
      </div>
    </section>
  );
}

interface WallboardPageContentProps {
  page: WallboardPage;
  state: WallboardState;
  presentation: ReturnType<typeof buildWallboardMapPresentation>;
  hasLiveFeed: boolean;
  pageDeadlineAt: string | null;
}

function WallboardPageContent({
  page,
  state,
  presentation,
  hasLiveFeed,
  pageDeadlineAt,
}: WallboardPageContentProps) {
  const configuration = state.wallboard.configuration.map;
  const pagePresentation = ['incident_list', 'summary'].includes(page.type)
    ? buildWallboardMapPresentation(
      state,
      hasLiveFeed,
      wallboardPageMapConfiguration(state.wallboard.configuration, page),
    )
    : presentation;
  const recentIncidents = selectRecentWallboardIncidents(state.operational_summary.recent_incidents);
  const activeOperationalIncidentCount = countActiveOperationalWallboardIncidents(state.map.incidents);
  if (page.type === 'news') {
    return (
      <WallboardNewsPage
        page={page}
        news={state.news.pages[page.id] ?? { items: [], fallback_used: false, lookback_days: 7 }}
        running={hasLiveFeed}
      />
    );
  }

  if (page.type === 'video') {
    return <WallboardVideoPage page={page} />;
  }

  if (page.type === 'photo_carousel') {
    const media = state.media.photo_pages[page.id];
    return (
      <WallboardPhotoCarousel
        media={media}
        running={hasLiveFeed}
        anchor={wallboardPhotoCarouselAnchorFromDeadline(
          pageDeadlineAt,
          media?.total_duration_seconds ?? page.duration_seconds,
        )}
      />
    );
  }

  if (page.type === 'message') {
    return (
      <div className="wallboard-display__message">
        <WallboardRichText
          content={wallboardMessageContent(page.options)}
          className="wallboard-display__message-content"
          ariaLabel="Mededeling"
        />
      </div>
    );
  }

  if (page.type === 'summary') {
    return (
      <div className="wallboard-display__overview">
        <Summary label="Actieve incidenten" value={activeOperationalIncidentCount} emphasis />
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
          <Summary label="Actieve incidenten" value={activeOperationalIncidentCount} />
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

function WallboardVideoPage({ page }: { page: WallboardPage }) {
  const embedUrl = wallboardVideoEmbedUrl(page.options.url);
  if (embedUrl === null) {
    return (
      <div className="wallboard-display__video wallboard-display__video--invalid" role="status">
        <span className="eyebrow">Video</span>
        <h2>{page.name}</h2>
        <p>Deze video is niet geldig geconfigureerd.</p>
      </div>
    );
  }

  return (
    <div className="wallboard-display__video">
      <iframe
        src={embedUrl}
        title={page.name}
        allow="autoplay; fullscreen; picture-in-picture"
        sandbox="allow-scripts allow-same-origin allow-presentation"
        referrerPolicy="strict-origin-when-cross-origin"
        allowFullScreen
      />
    </div>
  );
}

function WallboardNewsPage({
  page,
  news,
  running,
}: {
  page: WallboardPage;
  news: WallboardNewsPageState;
  running: boolean;
}) {
  const itemDurationSeconds = clampWallboardNewsItemDuration(Number(page.options.item_duration_seconds));
  const itemTransition = normalizeWallboardNewsItemTransition(page.options.item_transition);
  const itemSignature = news.items.map((item) => item.id).join('|');
  const activeIndex = usePausableWallboardCarousel(
    itemSignature,
    news.items.length,
    itemDurationSeconds * 1000,
    running,
  );

  if (news.items.length === 0) {
    return (
      <div className="wallboard-display__news wallboard-display__news--empty">
        <Newspaper size={52} aria-hidden />
        <span className="eyebrow">Dronenieuws</span>
        <h2>{page.name}</h2>
        <p>Er zijn tijdelijk geen nieuwsberichten uit de gekozen bronnen beschikbaar.</p>
      </div>
    );
  }

  const safeIndex = activeIndex % news.items.length;
  const activeItem = news.items[safeIndex];

  return (
    <div className="wallboard-display__news">
      <header className="wallboard-display__news-header">
        <span>
          <Newspaper size={21} aria-hidden />
          <strong>Dronenieuws</strong>
          <small>{news.fallback_used
            ? `Geen nieuws in de afgelopen ${news.lookback_days} dagen · meest recente publicaties`
            : `Gepubliceerd in de afgelopen ${news.lookback_days} dagen`}</small>
        </span>
        <b>{safeIndex + 1} / {news.items.length}</b>
      </header>

      <div
        className={running
          ? 'wallboard-display__news-carousel'
          : 'wallboard-display__news-carousel wallboard-display__news-carousel--paused'}
        style={{ '--wallboard-news-item-duration': `${itemDurationSeconds}s` } as CSSProperties}
      >
        <NewsArticle item={activeItem} transition={itemTransition} key={`${activeItem.id}:${safeIndex}`} />
        <ol className="wallboard-display__news-position" aria-label="Nieuwsberichten in deze carrousel">
          {news.items.map((item, index) => (
            <li
              className={index === safeIndex ? 'wallboard-display__news-position-item wallboard-display__news-position-item--active' : 'wallboard-display__news-position-item'}
              key={item.id}
              aria-current={index === safeIndex ? 'true' : undefined}
              aria-label={`Bericht ${index + 1}: ${item.title}`}
              title={item.title}
            />
          ))}
        </ol>
      </div>
    </div>
  );
}

function NewsArticle({
  item,
  transition,
}: {
  item: WallboardNewsItem;
  transition: ReturnType<typeof normalizeWallboardNewsItemTransition>;
}) {
  const [imageFailed, setImageFailed] = useState(false);
  const showImage = typeof item.image_url === 'string' && !imageFailed;

  useEffect(() => setImageFailed(false), [item.image_url]);

  return (
    <article className={`wallboard-display__news-article wallboard-display__news-article--${item.source} wallboard-display__news-article--transition-${transition} ${showImage ? 'wallboard-display__news-article--with-image' : 'wallboard-display__news-article--without-image'}`}>
      {showImage ? (
        <figure className="wallboard-display__news-image">
          <img
            src={item.image_url ?? undefined}
            alt={`Afbeelding bij ${item.title}`}
            decoding="async"
            referrerPolicy="no-referrer"
            onError={() => setImageFailed(true)}
          />
        </figure>
      ) : null}
      <div className="wallboard-display__news-copy">
        <div className="wallboard-display__news-meta">
          <strong>{item.source_label}</strong>
          <time dateTime={item.published_at}>{formatWallboardNewsDate(item.published_at)}</time>
        </div>
        <h2>{item.title}</h2>
        <p>{item.excerpt || 'Scan de QR-code voor de volledige inhoud van dit bericht.'}</p>
        <footer className="wallboard-display__news-article-footer">
          <span>
            <ExternalLink size={16} aria-hidden />
            Volledig artikel op {item.source_label}
          </span>
          <WallboardNewsQrCode title={item.title} url={item.url} />
        </footer>
      </div>
      <i className="wallboard-display__news-progress" aria-hidden />
    </article>
  );
}

export function usePausableWallboardCarousel(
  itemSignature: string,
  itemCount: number,
  itemDurationMilliseconds: number,
  running: boolean,
): number {
  const safeItemCount = Number.isFinite(itemCount) ? Math.max(0, Math.floor(itemCount)) : 0;
  const safeDurationMilliseconds = Number.isFinite(itemDurationMilliseconds)
    ? Math.max(1, Math.round(itemDurationMilliseconds))
    : 1;
  const phaseKey = `${itemSignature}:${safeItemCount}:${safeDurationMilliseconds}`;
  const remainingMillisecondsRef = useRef(safeDurationMilliseconds);
  const [runtime, setRuntime] = useState(() => ({ phaseKey, activeIndex: 0 }));

  useEffect(() => {
    remainingMillisecondsRef.current = safeDurationMilliseconds;
    setRuntime({ phaseKey, activeIndex: 0 });
  }, [phaseKey, safeDurationMilliseconds]);

  useEffect(() => {
    if (!running || safeItemCount <= 1 || runtime.phaseKey !== phaseKey) return undefined;

    const remainingMilliseconds = remainingMillisecondsRef.current;
    const startedAt = performance.now();
    let completed = false;
    const timeoutId = window.setTimeout(() => {
      completed = true;
      remainingMillisecondsRef.current = safeDurationMilliseconds;
      setRuntime((current) => ({
        phaseKey,
        activeIndex: current.phaseKey === phaseKey
          ? (current.activeIndex + 1) % safeItemCount
          : 0,
      }));
    }, remainingMilliseconds);

    return () => {
      window.clearTimeout(timeoutId);
      if (completed || runtime.phaseKey !== phaseKey) return;
      remainingMillisecondsRef.current = wallboardCarouselRemainingMilliseconds(
        remainingMilliseconds,
        performance.now() - startedAt,
      );
    };
  }, [phaseKey, running, runtime.activeIndex, runtime.phaseKey, safeDurationMilliseconds, safeItemCount]);

  if (safeItemCount <= 0 || runtime.phaseKey !== phaseKey) return 0;
  return runtime.activeIndex % safeItemCount;
}

interface WallboardDeadlineCountdown {
  phaseKey: string | null;
  remainingSeconds: number | null;
  transitionPending: boolean;
}

function usePausableWallboardDeadline(
  phaseKey: string | null,
  durationMilliseconds: number | null,
  running: boolean,
  onDeadline: () => void,
): WallboardDeadlineCountdown {
  const safeDurationMilliseconds = durationMilliseconds === null || !Number.isFinite(durationMilliseconds)
    ? null
    : Math.max(0, Math.ceil(durationMilliseconds));
  const remainingMillisecondsRef = useRef<number | null>(safeDurationMilliseconds);
  const callbackRef = useRef(onDeadline);
  const firedPhaseRef = useRef<string | null>(null);
  const [runtime, setRuntime] = useState<{ phaseKey: string | null; remainingMilliseconds: number | null }>(() => ({
    phaseKey,
    remainingMilliseconds: safeDurationMilliseconds,
  }));

  useEffect(() => {
    callbackRef.current = onDeadline;
  }, [onDeadline]);

  useEffect(() => {
    remainingMillisecondsRef.current = safeDurationMilliseconds;
    firedPhaseRef.current = null;
    setRuntime({ phaseKey, remainingMilliseconds: safeDurationMilliseconds });
  }, [phaseKey, safeDurationMilliseconds]);

  useEffect(() => {
    if (!running) {
      setRuntime((current) => current.phaseKey === phaseKey
        ? { ...current, remainingMilliseconds: remainingMillisecondsRef.current }
        : current);
      return undefined;
    }
    if (phaseKey === null || safeDurationMilliseconds === null) return undefined;

    const remainingMilliseconds = remainingMillisecondsRef.current ?? safeDurationMilliseconds;
    if (remainingMilliseconds <= 0) {
      if (firedPhaseRef.current !== phaseKey) {
        firedPhaseRef.current = phaseKey;
        callbackRef.current();
      }
      return undefined;
    }

    const startedAt = performance.now();
    const tickDelay = wallboardDeadlineTickDelayMilliseconds(remainingMilliseconds);
    let completed = false;
    const timeoutId = window.setTimeout(() => {
      completed = true;
      const nextRemaining = Math.max(0, remainingMilliseconds - (performance.now() - startedAt));
      remainingMillisecondsRef.current = nextRemaining;
      setRuntime({ phaseKey, remainingMilliseconds: nextRemaining });
      if (nextRemaining <= 0 && firedPhaseRef.current !== phaseKey) {
        firedPhaseRef.current = phaseKey;
        callbackRef.current();
      }
    }, tickDelay);

    return () => {
      window.clearTimeout(timeoutId);
      if (completed) return;
      remainingMillisecondsRef.current = wallboardCarouselRemainingMilliseconds(
        remainingMilliseconds,
        performance.now() - startedAt,
      );
    };
  }, [phaseKey, running, runtime.remainingMilliseconds, safeDurationMilliseconds]);

  const visibleRemaining = runtime.phaseKey === phaseKey
    ? runtime.remainingMilliseconds
    : safeDurationMilliseconds;
  return {
    phaseKey,
    remainingSeconds: wallboardDeadlineRemainingSeconds(visibleRemaining),
    transitionPending: phaseKey !== null && visibleRemaining !== null && visibleRemaining <= 0,
  };
}

export function wallboardNewsCarouselIndex(
  itemCount: number,
  itemDurationSeconds: number,
  elapsedMilliseconds: number,
): number {
  if (
    !Number.isFinite(itemCount)
    || itemCount <= 1
    || !Number.isFinite(elapsedMilliseconds)
    || elapsedMilliseconds < 0
  ) return 0;
  const duration = clampWallboardNewsItemDuration(itemDurationSeconds);
  return Math.floor(elapsedMilliseconds / (duration * 1000)) % Math.floor(itemCount);
}

export function wallboardCarouselRemainingMilliseconds(remainingMilliseconds: number, elapsedMilliseconds: number): number {
  const remaining = Number.isFinite(remainingMilliseconds) ? Math.max(1, remainingMilliseconds) : 1;
  const elapsed = Number.isFinite(elapsedMilliseconds) ? Math.max(0, elapsedMilliseconds) : 0;
  return Math.max(1, Math.ceil(remaining - elapsed));
}

export function wallboardDeadlineDurationMilliseconds(
  deadlineAt: string | null,
  now: number = Date.now(),
): number | null {
  if (deadlineAt === null || !Number.isFinite(now)) return null;
  const deadline = Date.parse(deadlineAt);
  return Number.isFinite(deadline) ? Math.max(0, deadline - now) : null;
}

export function wallboardDeadlineRemainingSeconds(remainingMilliseconds: number | null): number | null {
  if (remainingMilliseconds === null || !Number.isFinite(remainingMilliseconds) || remainingMilliseconds <= 0) return null;
  return Math.max(1, Math.ceil(remainingMilliseconds / 1000));
}

export function wallboardDeadlineTickDelayMilliseconds(remainingMilliseconds: number): number {
  if (!Number.isFinite(remainingMilliseconds) || remainingMilliseconds <= 0) return 0;
  const visibleSeconds = Math.max(1, Math.ceil(remainingMilliseconds / 1000));
  return Math.max(1, Math.ceil(remainingMilliseconds - ((visibleSeconds - 1) * 1000)));
}

export function wallboardTransientFocusIsComplete(
  focus: WallboardFocusState | null | undefined,
): boolean {
  if (
    focus === null
    || focus === undefined
    || focus.is_preview === true
    || focus.kind === 'real_alarm'
  ) return false;

  const counts = focus.responses?.counts;
  if (
    counts === null
    || counts === undefined
    || !isWallboardCount(counts.targeted)
    || !isWallboardCount(counts.pending)
  ) return false;

  return Math.trunc(counts.targeted) > 0 && Math.trunc(counts.pending) === 0;
}

function FocusTakeover({
  focus,
  fallbackPilotAvailability,
  isCurrent,
  showResponseFeed,
}: {
  focus: WallboardFocusState;
  fallbackPilotAvailability: WallboardPilotAvailability;
  isCurrent: boolean;
  showResponseFeed: boolean;
}) {
  const Icon = focus.kind === 'preannouncement' ? BellRing : focus.kind === 'test_alarm' ? Radio : Siren;
  const label = wallboardFocusKindLabel(focus.kind);
  const focusClass = focus.kind.replace('_', '-');
  const pilotCounts = focus.kind === 'preannouncement'
    ? wallboardFocusPilotCounts(focus.pilot_counts, fallbackPilotAvailability, isCurrent)
    : null;
  const inlineResponseKind = focus.kind === 'preannouncement' || focus.kind === 'test_alarm'
    ? focus.kind
    : null;
  const showInlineResponseSummary = inlineResponseKind !== null
    && showResponseFeed
    && focus.responses !== null
    && focus.responses !== undefined;
  const showRealAlarmResponseFeed = focus.kind === 'real_alarm'
    && showResponseFeed
    && focus.responses !== null
    && focus.responses !== undefined;

  return (
    <section
      className={`wallboard-display__alarm wallboard-display__alarm--focus wallboard-display__alarm--${focusClass}${showRealAlarmResponseFeed ? ' wallboard-display__alarm--with-feed' : ''}`}
      key={focus.focus_id}
      role="alert"
      aria-label={`${label} ${focus.reference}: ${focus.title}`}
    >
      <div className="wallboard-display__alarm-main">
        <span className="wallboard-display__alarm-icon" aria-hidden><Icon size={54} strokeWidth={1.8} /></span>
        <div className="wallboard-display__alarm-meta">
          <strong>{focus.reference}</strong>
          <span>{priorityLabel(focus.priority)}</span>
          {focus.kind === 'test_alarm' ? <b>TEST</b> : null}
        </div>
        <h2>{focus.title}</h2>
        {showInlineResponseSummary ? (
          <WallboardFocusResponseSummary
            kind={inlineResponseKind}
            pilotCounts={pilotCounts}
            responses={focus.responses ?? null}
          />
        ) : pilotCounts !== null ? <FocusPilotAvailability counts={pilotCounts} /> : null}
        {focus.kind !== 'test_alarm' ? (
          <p><MapPin size={24} aria-hidden /> {focus.location_label?.trim() || 'Locatie nog niet bekend'}</p>
        ) : null}
        <div className="wallboard-display__alarm-status">
          <i aria-hidden />
          <strong>{focusStatusLabel(focus.kind)}</strong>
          <time dateTime={focus.started_at}>Gestart {formatDateTime(focus.started_at)}</time>
        </div>
      </div>
      {showRealAlarmResponseFeed ? (
        <WallboardFocusResponseFeed
          kind={focus.kind}
          pilotCounts={pilotCounts}
          responses={focus.responses ?? null}
        />
      ) : null}
    </section>
  );
}

function WallboardFocusResponseSummary({
  kind,
  pilotCounts,
  responses,
}: {
  kind: WallboardFocusState['kind'];
  pilotCounts: WallboardFocusPilotCounts | null;
  responses: WallboardFocusResponses | null;
}) {
  const counts = responses?.counts;
  const contacted = wallboardResponseCount(counts?.contacted ?? counts?.targeted);
  const accepted = kind === 'preannouncement'
    ? wallboardResponseCount(pilotCounts?.available)
    : wallboardResponseCount(counts?.accepted);

  return (
    <dl
      className="wallboard-display__response-counts wallboard-display__response-counts--inline"
      aria-label={kind === 'preannouncement'
        ? `${contacted} piloten gealarmeerd, ${accepted} beschikbaar gemeld`
        : `${contacted} proefalarmen verstuurd, ${accepted} bevestigd`}
    >
      <div><dt>{kind === 'test_alarm' ? 'Verstuurd' : 'Gealarmeerd'}</dt><dd>{contacted}</dd></div>
      <div><dt>{kind === 'test_alarm' ? 'Bevestigd' : 'Beschikbaar'}</dt><dd>{accepted}</dd></div>
    </dl>
  );
}

function WallboardFocusResponseFeed({
  kind,
  pilotCounts,
  responses,
}: {
  kind: WallboardFocusState['kind'];
  pilotCounts: WallboardFocusPilotCounts | null;
  responses: WallboardFocusResponses | null;
}) {
  const counts = responses?.counts;
  const contacted = wallboardResponseCount(counts?.contacted ?? counts?.targeted);
  const accepted = wallboardResponseCount(counts?.accepted);
  const coming = kind === 'real_alarm' ? (responses?.coming ?? []) : [];
  const heading = kind === 'real_alarm'
    ? 'Piloten onderweg'
    : kind === 'preannouncement'
      ? 'Bereik vooraankondiging'
      : 'Bereik proefalarm';
  const primaryLabel = kind === 'test_alarm' ? 'Verstuurd' : 'Gealarmeerd';
  const secondaryLabel = kind === 'preannouncement' ? 'Beschikbaar' : kind === 'real_alarm' ? 'Komen' : 'Bevestigd';
  const secondaryCount = kind === 'preannouncement'
    ? wallboardResponseCount(pilotCounts?.available)
    : accepted;

  return (
    <aside
      className="wallboard-display__responses"
      aria-labelledby="wallboard-focus-responses-title"
      aria-live="polite"
      aria-atomic="false"
      aria-relevant="additions text"
    >
      <header>
        <span><UsersRound size={24} aria-hidden /></span>
        <div>
          <small>Live stand</small>
          <h3 id="wallboard-focus-responses-title">{heading}</h3>
        </div>
      </header>
      <dl className="wallboard-display__response-counts">
        <div><dt>{primaryLabel}</dt><dd>{contacted}</dd></div>
        <div><dt>{secondaryLabel}</dt><dd>{secondaryCount}</dd></div>
      </dl>
      {kind === 'real_alarm' && coming.length === 0 ? (
        <p className="wallboard-display__responses-empty">Nog niemand heeft bevestigd te komen.</p>
      ) : kind === 'real_alarm' ? (
        <ol className="wallboard-display__response-list">
          {coming.map((item, index) => (
            <li className={`wallboard-display__response wallboard-display__response--${item.response_status}`} key={`${item.name}-${item.responded_at ?? item.response_status}-${index}`}>
              <i aria-hidden />
              <span><strong>{item.name}</strong><small>Komt naar de inzet</small></span>
              <time>{wallboardFocusEtaLabel(item.eta_minutes)}</time>
            </li>
          ))}
        </ol>
      ) : null}
    </aside>
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
      {!alert.is_test ? (
        <p><MapPin size={24} aria-hidden /> {alert.location_label?.trim() || 'Locatie nog niet bekend'}</p>
      ) : null}
      <div className="wallboard-display__alarm-status">
        <i aria-hidden />
        <strong>{alert.is_test ? 'Proefalarm ontvangen' : 'Piloten worden gealarmeerd'}</strong>
        <time dateTime={alert.received_at}>Binnengekomen {formatDateTime(alert.received_at)}</time>
      </div>
    </section>
  );
}

function FocusPilotAvailability({ counts }: { counts: WallboardFocusPilotCounts }) {
  const pilotLabel = counts.relevant === 1 ? 'piloot' : 'piloten';
  const contactedLabel = counts.contacted === 1 ? 'piloot bereikt' : 'piloten bereikt';

  return (
    <section
      className="wallboard-display__focus-availability"
      aria-label={`${counts.available} van ${counts.relevant} geselecteerde ${pilotLabel} hebben zich beschikbaar gemeld`}
    >
      <span><UsersRound size={22} aria-hidden /> Beschikbaar gemeld</span>
      <strong>
        <b>{counts.available}</b>
        <span>van {counts.relevant} geselecteerde {pilotLabel}</span>
      </strong>
      {counts.contacted > 0 ? <small>{counts.contacted} {contactedLabel}</small> : null}
    </section>
  );
}

export function wallboardFocusPilotCounts(
  focusCounts: WallboardFocusPilotCounts | null | undefined,
  fallbackAvailability: WallboardPilotAvailability | null | undefined,
  isCurrent = true,
): WallboardFocusPilotCounts | null {
  if (!isCurrent) return null;

  if (
    focusCounts !== null
    && focusCounts !== undefined
    && isWallboardCount(focusCounts.available)
    && isWallboardCount(focusCounts.relevant)
    && isWallboardCount(focusCounts.contacted)
  ) {
    const relevant = Math.trunc(focusCounts.relevant);
    return {
      available: Math.min(relevant, Math.trunc(focusCounts.available)),
      relevant,
      contacted: Math.trunc(focusCounts.contacted),
    };
  }

  if (
    fallbackAvailability !== null
    && fallbackAvailability !== undefined
    && isWallboardCount(fallbackAvailability.available)
    && isWallboardCount(fallbackAvailability.total)
  ) {
    const relevant = Math.trunc(fallbackAvailability.total);
    return {
      available: Math.min(relevant, Math.trunc(fallbackAvailability.available)),
      relevant,
      contacted: 0,
    };
  }

  return null;
}

function isWallboardCount(value: number): boolean {
  return Number.isFinite(value) && value >= 0;
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

export function wallboardRefreshDecision(
  currentVersion: number | null,
  incomingVersion: unknown,
  persistedVersion: unknown = null,
): { version: number; reload: boolean } {
  const incoming = nonNegativeRefreshVersion(incomingVersion);
  if (currentVersion === null) {
    const persisted = optionalNonNegativeRefreshVersion(persistedVersion);
    if (persisted === null) return { version: incoming, reload: false };
    return incoming > persisted
      ? { version: incoming, reload: true }
      : { version: Math.max(incoming, persisted), reload: false };
  }
  const current = nonNegativeRefreshVersion(currentVersion);
  return incoming > current
    ? { version: incoming, reload: true }
    : { version: current, reload: false };
}

function persistWallboardRefreshVersion(storage: Pick<Storage, 'setItem'>, version: number): void {
  try {
    storage.setItem(WALLBOARD_REFRESH_VERSION_STORAGE_KEY, String(version));
  } catch {
    // A wallboard remains controllable when kiosk storage is unavailable. The
    // in-memory baseline still prevents repeated refreshes in the active page.
  }
}

function readPersistedWallboardRefreshVersion(storage: Pick<Storage, 'getItem'>): number | null {
  try {
    return optionalNonNegativeRefreshVersion(storage.getItem(WALLBOARD_REFRESH_VERSION_STORAGE_KEY));
  } catch {
    return null;
  }
}

function clearPersistedWallboardRefreshVersion(storage: Pick<Storage, 'removeItem'>): void {
  try {
    storage.removeItem(WALLBOARD_REFRESH_VERSION_STORAGE_KEY);
  } catch {
    // Pairing remains functional when kiosk storage is unavailable.
  }
}

function nonNegativeRefreshVersion(value: unknown): number {
  return typeof value === 'number' && Number.isFinite(value)
    ? Math.max(0, Math.trunc(value))
    : 0;
}

function optionalNonNegativeRefreshVersion(value: unknown): number | null {
  if (typeof value === 'number' && Number.isFinite(value)) return Math.max(0, Math.trunc(value));
  if (typeof value !== 'string' || !/^\d+$/.test(value)) return null;
  const parsed = Number(value);
  return Number.isSafeInteger(parsed) ? parsed : null;
}

export function normalizeWallboardState(state: WallboardState): WallboardState {
  const configuration = wallboardConfigurationCopy(state.wallboard.configuration);
  const rawState = state as WallboardState & {
    operational_summary?: WallboardState['operational_summary'];
    maintenance?: unknown;
    ticker?: WallboardState['ticker'];
    news?: unknown;
    media?: unknown;
  };
  const operationalSummary = rawState.operational_summary;
  const hasFocusContract = operationalSummary !== undefined
    && Object.prototype.hasOwnProperty.call(operationalSummary, 'focus');
  const rawWallboard = state.wallboard as WallboardState['wallboard'] & {
    control_version?: number;
    refresh_version?: unknown;
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
    maintenance: normalizeWallboardMaintenanceNotice(rawState.maintenance),
    operational_summary: {
      pilot_availability: operationalSummary?.pilot_availability
        ?? { available: Number.NaN, total: Number.NaN },
      active_alarm: operationalSummary?.active_alarm ?? null,
      recent_incidents: operationalSummary?.recent_incidents ?? [],
      transient_alert: operationalSummary?.transient_alert ?? null,
      ...(hasFocusContract ? { focus: normalizeWallboardFocusState(operationalSummary?.focus) } : {}),
    },
    ticker: rawState.ticker ?? { items: [] },
    news: normalizeWallboardNewsState(rawState.news),
    media: {
      photo_pages: normalizeWallboardMediaPageStates(
        isRecord(rawState.media) ? rawState.media.photo_pages : undefined,
      ),
    },
    wallboard: {
      ...state.wallboard,
      display_profile: normalizeWallboardDisplayProfile(rawWallboard.display_profile),
      configuration,
      control_version: rawWallboard.control_version ?? 1,
      refresh_version: nonNegativeRefreshVersion(rawWallboard.refresh_version),
      display: { ...display, page_id: pageId },
    },
  };
}

export function normalizeWallboardMaintenanceNotice(value: unknown): WallboardMaintenanceNotice | null {
  if (!isRecord(value) || value.active !== true) return null;
  if (!['update', 'maintenance'].includes(String(value.kind))) return null;
  if (
    typeof value.title !== 'string'
    || typeof value.message !== 'string'
    || typeof value.started_at !== 'string'
    || typeof value.expires_at !== 'string'
  ) return null;

  const title = value.title.trim();
  const message = value.message.trim();
  const startedAt = Date.parse(value.started_at);
  const expiresAt = Date.parse(value.expires_at);
  const maximumLifetimeMilliseconds = 6 * 60 * 60 * 1000;
  if (
    title === ''
    || message === ''
    || !Number.isFinite(startedAt)
    || !Number.isFinite(expiresAt)
    || expiresAt <= startedAt
    || expiresAt - startedAt > maximumLifetimeMilliseconds
  ) return null;

  return {
    active: true,
    kind: value.kind as WallboardMaintenanceNotice['kind'],
    title: title.slice(0, 120),
    message: message.slice(0, 500),
    started_at: value.started_at,
    expires_at: value.expires_at,
  };
}

export function wallboardMaintenanceNoticeIsActive(value: unknown, now: number = Date.now()): boolean {
  const notice = normalizeWallboardMaintenanceNotice(value);
  if (notice === null) return false;
  const expiresAt = Date.parse(notice.expires_at);

  return Number.isFinite(now) && expiresAt > now;
}

export function normalizeWallboardNewsState(value: unknown): WallboardNewsState {
  if (!isRecord(value) || !isRecord(value.pages)) return { pages: {}, generated_at: '' };

  const pages = Object.fromEntries(
    Object.entries(value.pages)
      .flatMap(([pageId, page]) => pageId.trim() !== '' && isRecord(page) ? [[pageId, page] as const] : [])
      .slice(0, 20)
      .map(([pageId, page]) => {
        const items = Array.isArray(page.items)
          ? page.items.flatMap((item) => normalizeWallboardNewsItem(item)).slice(0, 12)
          : [];
        return [pageId, {
          items,
          fallback_used: page.fallback_used === true,
          lookback_days: 7 as const,
        }];
      }),
  );

  return {
    pages,
    generated_at: typeof value.generated_at === 'string' ? value.generated_at : '',
  };
}

function normalizeWallboardNewsItem(value: unknown): WallboardNewsItem[] {
  if (!isRecord(value) || !['ndt', 'dronewatch', 'custom'].includes(String(value.source))) return [];
  if (
    typeof value.id !== 'string'
    || (value.source_id !== undefined && typeof value.source_id !== 'string')
    || typeof value.source_label !== 'string'
    || typeof value.title !== 'string'
    || typeof value.excerpt !== 'string'
    || typeof value.url !== 'string'
    || typeof value.published_at !== 'string'
  ) return [];

  const title = value.title.trim();
  const source = value.source as WallboardNewsItem['source'];
  if (source === 'custom' && typeof value.source_id !== 'string') return [];
  const sourceId = typeof value.source_id === 'string' ? value.source_id.trim() : source;
  const sourceLabel = value.source_label.trim();
  const url = safeWallboardNewsUrl(value.url);
  const imageUrl = value.image_url === null || value.image_url === undefined
    ? null
    : typeof value.image_url === 'string' ? safeWallboardNewsImageUrl(value.image_url) : null;
  if (
    title === ''
    || sourceLabel === ''
    || !/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/.test(sourceId)
    || url === null
    || !Number.isFinite(Date.parse(value.published_at))
  ) return [];

  return [{
    id: value.id.slice(0, 180),
    source,
    source_id: sourceId,
    source_label: sourceLabel.slice(0, 80),
    title: title.slice(0, 240),
    excerpt: value.excerpt.trim().slice(0, 1200),
    url,
    image_url: imageUrl,
    published_at: value.published_at,
  }];
}

function safeWallboardNewsUrl(value: string): string | null {
  try {
    const url = new URL(value);
    return url.protocol === 'https:' ? url.toString() : null;
  } catch {
    return null;
  }
}

function safeWallboardNewsImageUrl(value: string): string | null {
  const trimmed = value.trim();
  return /^\/api\/wallboard\/news-images\/[a-f0-9]{64}$/.test(trimmed) ? trimmed : null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function controlFromState(state: WallboardState): WallboardControlState {
  const fallbackMode: WallboardDisplayMode = !state.wallboard.configuration.rotation_enabled
    || state.wallboard.configuration.pages.length <= 1
    ? 'static'
    : 'rotation';

  return {
    generated_at: state.generated_at,
    maintenance: normalizeWallboardMaintenanceNotice(state.maintenance),
    config_version: state.wallboard.config_version,
    control_version: state.wallboard.control_version ?? 1,
    refresh_version: state.wallboard.refresh_version,
    display_profile: normalizeWallboardDisplayProfile(state.wallboard.display_profile),
    transient_alert: state.operational_summary.transient_alert,
    ...(state.operational_summary.focus === undefined
      ? {}
      : { focus: normalizeWallboardFocusState(state.operational_summary.focus) }),
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
    maintenance?: unknown;
    refresh_version?: unknown;
    display_profile?: unknown;
    transient_alert?: WallboardTransientAlert | null;
    focus?: WallboardFocusState | null;
  };
  const hasFocusContract = Object.prototype.hasOwnProperty.call(legacyState, 'focus');
  return {
    ...state,
    maintenance: normalizeWallboardMaintenanceNotice(legacyState.maintenance),
    display_profile: normalizeWallboardDisplayProfile(legacyState.display_profile),
    refresh_version: nonNegativeRefreshVersion(legacyState.refresh_version),
    transient_alert: legacyState.transient_alert ?? null,
    ...(hasFocusContract ? { focus: normalizeWallboardFocusState(legacyState.focus) } : {}),
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
    return stabilizeWallboardControlState(current, next, now);
  }

  const currentDeadline = current.display.next_change_at;
  const nextDeadline = next.display.next_change_at;
  if (currentDeadline === null || currentDeadline === undefined || nextDeadline === null || nextDeadline === undefined) {
    return stabilizeWallboardControlState(current, next, now);
  }

  const currentDeadlineMilliseconds = Date.parse(currentDeadline);
  const nextDeadlineMilliseconds = Date.parse(nextDeadline);
  if (
    !Number.isFinite(currentDeadlineMilliseconds)
    || !Number.isFinite(nextDeadlineMilliseconds)
    || currentDeadlineMilliseconds <= now
    || nextDeadlineMilliseconds <= currentDeadlineMilliseconds
  ) {
    return stabilizeWallboardControlState(current, next, now);
  }

  return stabilizeWallboardControlState(current, {
    ...next,
    display: {
      ...next.display,
      next_change_at: currentDeadline,
    },
  }, now);
}

function stabilizeWallboardControlState(
  current: WallboardControlState | null,
  next: WallboardControlState,
  now: number,
): WallboardControlState {
  const maintenanceStabilized = stabilizeWallboardMaintenance(current, next);
  const focusStabilized = stabilizeWallboardFocus(current, maintenanceStabilized, now);
  return stabilizeWallboardTransientAlert(current, focusStabilized, now);
}

function stabilizeWallboardMaintenance(
  current: WallboardControlState | null,
  next: WallboardControlState,
): WallboardControlState {
  if (current === null || !wallboardControlIsOlder(next, current)) return next;

  return { ...next, maintenance: current.maintenance ?? null };
}

function stabilizeWallboardFocus(
  current: WallboardControlState | null,
  next: WallboardControlState,
  now: number,
): WallboardControlState {
  if (current === null || current.focus === undefined) return next;
  if (wallboardControlIsOlder(next, current)) return { ...next, focus: current.focus };
  if (current.focus === null || next.focus === null || next.focus === undefined) return next;
  if (
    current.config_version !== next.config_version
    || current.control_version !== next.control_version
    || wallboardFocusSignature(current.focus) !== wallboardFocusSignature(next.focus)
  ) return next;

  const nextChangeAt = stabilizePendingDeadline(
    current.focus.next_change_at,
    next.focus.next_change_at,
    now,
  );
  const expiresAt = stabilizePendingDeadline(
    current.focus.expires_at,
    next.focus.expires_at,
    now,
  );

  return {
    ...next,
    focus: {
      ...next.focus,
      next_change_at: nextChangeAt,
      expires_at: expiresAt,
    },
  };
}

function stabilizePendingDeadline(
  currentValue: string | null | undefined,
  nextValue: string | null | undefined,
  now: number,
): string | null | undefined {
  if (!currentValue || !nextValue) return nextValue;
  const currentDeadline = Date.parse(currentValue);
  const nextDeadline = Date.parse(nextValue);
  if (
    !Number.isFinite(currentDeadline)
    || !Number.isFinite(nextDeadline)
    || currentDeadline <= now
    || nextDeadline <= currentDeadline
  ) return nextValue;
  return currentValue;
}

function wallboardControlIsOlder(next: WallboardControlState, current: WallboardControlState): boolean {
  if (!next.generated_at || !current.generated_at) return false;
  const nextGeneratedAt = Date.parse(next.generated_at);
  const currentGeneratedAt = Date.parse(current.generated_at);
  return Number.isFinite(nextGeneratedAt)
    && Number.isFinite(currentGeneratedAt)
    && nextGeneratedAt < currentGeneratedAt;
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

function normalizeWallboardFocusState(
  focus: WallboardFocusState | null | undefined,
): WallboardFocusState | null | undefined {
  if (focus === undefined || focus === null) return focus;
  if (!['preannouncement', 'real_alarm', 'test_alarm'].includes(focus.kind)) return null;
  if (
    typeof focus.focus_id !== 'string' || focus.focus_id === ''
    || typeof focus.dispatch_id !== 'string' || focus.dispatch_id === ''
    || typeof focus.incident_id !== 'string' || focus.incident_id === ''
    || typeof focus.reference !== 'string'
    || typeof focus.title !== 'string'
    || typeof focus.started_at !== 'string'
  ) return null;

  return {
    ...focus,
    is_preview: focus.is_preview === true,
    visible: focus.visible === true,
    expires_at: typeof focus.expires_at === 'string' ? focus.expires_at : null,
    playlist_page_id: typeof focus.playlist_page_id === 'string' && focus.playlist_page_id !== ''
      ? focus.playlist_page_id
      : null,
    next_change_at: typeof focus.next_change_at === 'string' ? focus.next_change_at : null,
    pilot_counts: wallboardFocusPilotCounts(focus.pilot_counts, null),
    responses: normalizeWallboardFocusResponses(focus.responses),
  };
}

function normalizeWallboardFocusResponses(
  responses: WallboardFocusResponses | null | undefined,
): WallboardFocusResponses | null {
  if (responses === null || responses === undefined) return null;
  const responseStatuses = new Set(['pending', 'accepted', 'declined', 'no_response']);
  return {
    counts: {
      contacted: wallboardResponseCount(responses.counts?.contacted ?? responses.counts?.targeted),
      targeted: wallboardResponseCount(responses.counts?.targeted),
      pending: wallboardResponseCount(responses.counts?.pending),
      accepted: wallboardResponseCount(responses.counts?.accepted),
      declined: wallboardResponseCount(responses.counts?.declined),
      no_response: wallboardResponseCount(responses.counts?.no_response),
    },
    items: Array.isArray(responses.items)
      ? responses.items.filter((item) => (
        typeof item.name === 'string'
        && item.name.trim() !== ''
        && responseStatuses.has(item.response_status)
      )).map((item) => ({
        name: item.name.trim(),
        response_status: item.response_status,
        responded_at: typeof item.responded_at === 'string' ? item.responded_at : null,
      }))
      : [],
    coming: Array.isArray(responses.coming)
      ? responses.coming.filter((item) => (
        typeof item.name === 'string'
        && item.name.trim() !== ''
        && item.response_status === 'accepted'
      )).map((item) => ({
        name: item.name.trim(),
        response_status: 'accepted' as const,
        responded_at: typeof item.responded_at === 'string' ? item.responded_at : null,
        eta_minutes: Number.isFinite(item.eta_minutes) && (item.eta_minutes ?? 0) > 0
          ? Math.trunc(item.eta_minutes ?? 0)
          : null,
        eta_source: typeof item.eta_source === 'string' && item.eta_source.trim() !== ''
          ? item.eta_source.trim()
          : null,
      }))
      : [],
  };
}

export function wallboardTickerIsVisible(
  hasLiveFeed: boolean,
  focus: WallboardFocusState | null,
  showTransientAlert: boolean,
  hasActiveAlarm: boolean,
  tickerItemCount: number,
): boolean {
  return hasLiveFeed
    && focus?.visible !== true
    && !showTransientAlert
    // Keep the established legacy alarm takeover behaviour when no focus
    // cycle is active. During a real-alarm playlist phase, however, the
    // ticker is part of the administrator-configured playlist presentation.
    && !(focus === null && hasActiveAlarm)
    && tickerItemCount > 0;
}

function wallboardFocusSignature(focus: WallboardFocusState | null | undefined): string {
  if (focus === undefined) return 'legacy';
  if (focus === null) return 'none';
  return [focus.kind, focus.focus_id, focus.visible ? 'focus' : 'playlist', focus.playlist_page_id ?? 'none'].join(':');
}

function wallboardControlDeadlinePhase(control: WallboardControlState | null): { key: string; deadlineAt: string } | null {
  if (control === null) return null;
  if (control.focus !== undefined && control.focus !== null) {
    const deadlineAt = control.focus.next_change_at ?? control.focus.expires_at;
    if (!deadlineAt) return null;
    return {
      key: `focus:${control.config_version}:${control.control_version}:${wallboardFocusSignature(control.focus)}:${deadlineAt}`,
      deadlineAt,
    };
  }
  if (control.display.mode !== 'rotation' || !control.display.next_change_at) return null;
  return {
    key: `display:${control.config_version}:${control.control_version}:${control.display.page_id}:${control.display.next_change_at}`,
    deadlineAt: control.display.next_change_at,
  };
}

function wallboardNextSwitchLabel(
  focus: WallboardFocusState | null,
  display: WallboardControlState['display'],
  now: number,
  countdown: WallboardDeadlineCountdown,
): string {
  if (focus !== null) {
    const deadline = focus.next_change_at ?? focus.expires_at;
    if (!deadline) return focus.visible ? 'Focusscherm actief' : 'Alarmplaylist actief';
    return countdown.transitionPending
      ? `${focus.visible ? 'Playlist' : 'Focusscherm'} wisselen...`
      : `${focus.visible ? 'Playlist' : 'Focusscherm'} over ${formatDeadlineCountdown(countdown, deadline, now)}`;
  }
  return display.mode === 'rotation' && display.next_change_at
    ? countdown.transitionPending
      ? 'Volgende pagina laden...'
      : `Volgende pagina over ${formatDeadlineCountdown(countdown, display.next_change_at, now)}`
    : displayModeLabel(display.mode);
}

function formatDeadlineCountdown(countdown: WallboardDeadlineCountdown, fallbackDeadline: string, now: number): string {
  const remainingSeconds = countdown.remainingSeconds;
  if (remainingSeconds === null) return formatRemaining(fallbackDeadline, now);
  if (remainingSeconds < 60) return `${remainingSeconds} sec.`;
  const minutes = Math.floor(remainingSeconds / 60);
  const seconds = remainingSeconds % 60;
  return `${minutes}:${seconds.toString().padStart(2, '0')} min.`;
}

function focusStatusLabel(kind: WallboardFocusKind): string {
  switch (kind) {
    case 'preannouncement': return 'Piloten worden voorbereid';
    case 'real_alarm': return 'Piloten worden gealarmeerd';
    case 'test_alarm': return 'Bereikbaarheid wordt getest';
  }
}

function wallboardResponseCount(value: number | undefined): number {
  return Number.isFinite(value) ? Math.max(0, Math.trunc(value ?? 0)) : 0;
}

function wallboardFocusEtaLabel(value: number | null | undefined): string {
  if (!Number.isFinite(value) || (value ?? 0) <= 0) return 'ETA onbekend';
  return `ETA ${Math.trunc(value ?? 0)} min.`;
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

export function formatWallboardDate(value: number): string {
  const date = new Date(value);
  return Number.isFinite(date.getTime()) ? WALLBOARD_DATE_FORMATTER.format(date) : 'Datum onbekend';
}

function formatWallboardNewsDate(value: string): string {
  const date = new Date(value);
  return Number.isFinite(date.getTime()) ? WALLBOARD_NEWS_DATE_FORMATTER.format(date) : 'Datum onbekend';
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
