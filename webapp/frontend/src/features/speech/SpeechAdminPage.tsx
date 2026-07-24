import {
  AlertTriangle,
  AudioLines,
  ChevronLeft,
  ChevronRight,
  CheckCircle2,
  Cpu,
  Database,
  Download,
  HardDrive,
  ListMusic,
  Mic,
  RefreshCw,
  Search,
  ShieldCheck,
  Square,
  Trash2,
  Upload,
  Volume2,
  X,
} from 'lucide-react';
import {
  useEffect,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
  type MouseEvent as ReactMouseEvent,
} from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError, apiBaseUrl } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type {
  PaginationMeta,
  SpeechAdminStatus,
  SpeechCacheEntryCategory,
  SpeechCacheEntryStatus,
  SpeechCacheEntrySummary,
  SpeechCacheRegenerationScope,
  SpeechModel,
  SpeechModelInstallStarted,
  SpeechPhase,
  SpeechPreview,
  SpeechSettings,
  SpeechTemplateDefinition,
  SpeechVoiceProfile,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import styles from './SpeechAdminPage.module.css';
import { SpeechPreparationLibrary } from './SpeechPreparationLibrary';
import {
  fixedSpeechCacheAudioPath,
  fixedSpeechPreviewAudioPath,
  formatSpeechBytes,
  formatSpeechDuration,
  formatSpeechParameterCount,
  formatSpeechSynthesisDuration,
  insertSpeechToken,
  microphoneRecordingError,
  microphoneRequestIsCurrent,
  normalizeSpeechProgress,
  normalizeSpeechToken,
  renderSpeechTemplate,
  semanticSpeechLines,
  SPEECH_POLL_INTERVAL_MS,
  speechCacheHitRate,
  speechCacheUsagePercentage,
  speechConfigurationIssue,
  speechStatusLabel,
  speechStatusTone,
  speechTemplateTokens,
  speechTokenLabel,
  speechVoiceProfileIsReadyForModel,
  speechWorkIsActive,
} from './speechPresentation';

type SpeechDataMutator = (
  next: SpeechAdminStatus | null | ((current: SpeechAdminStatus | null) => SpeechAdminStatus | null),
) => void;

interface SpeechSettingsDraft {
  enabled: boolean;
  model_id: string | null;
  voice_profile_id: string | null;
  speed: number;
  pre_generate_on_save: boolean;
  templates: Record<SpeechPhase, string>;
}

export function SpeechAdminPage() {
  const { hasPermission } = useAuth();
  const canManage = hasPermission('settings.manage');
  const canViewCacheContent = hasPermission('incidents.view');
  const canViewPreparations = hasPermission('speech.cache.view');
  const canManagePreparations = hasPermission('speech.cache.manage');
  const resource = useApiResource<SpeechAdminStatus>('/admin/speech', canManage);
  const reloadSilently = resource.silentReload;
  const activeWork = resource.data !== null && hasActiveSpeechWork(resource.data);

  useEffect(() => {
    if (!activeWork) return undefined;

    let requestInFlight = false;
    const intervalId = window.setInterval(() => {
      if (requestInFlight || document.visibilityState !== 'visible') return;
      requestInFlight = true;
      void reloadSilently().finally(() => {
        requestInFlight = false;
      });
    }, SPEECH_POLL_INTERVAL_MS);

    return () => window.clearInterval(intervalId);
  }, [activeWork, reloadSilently]);

  return (
    <div className={`page-stack ${styles.page}`}>
      <header className={styles.hero}>
        <span className={styles.heroIcon} aria-hidden><AudioLines size={28} /></span>
        <div>
          <p className={styles.eyebrow}>Beheer · centrale spraakservice</p>
          <h1>Spraakregie</h1>
          <p>Beheer servermodellen, stemmen, semantische meldingsregels en de lokale audiocache vanuit één plek.</p>
        </div>
        <StatusPill
          value={resource.data?.settings.enabled ? 'Serverstem actief' : 'Serverstem uit'}
          tone={resource.data?.settings.enabled ? 'good' : 'neutral'}
        />
      </header>

      {resource.data === null ? (
        <Panel title="Spraakbeheer">
          <ResourceState loading={resource.loading} error={resource.error} empty={!resource.loading && resource.error === null}>
            {null}
          </ResourceState>
        </Panel>
      ) : (
        <>
          {resource.error ? (
            <p className={styles.staleWarning} role="status">
              De actuele spraakstatus kon tijdelijk niet worden opgehaald. De laatst bekende gegevens blijven zichtbaar.
            </p>
          ) : null}
          <SpeechWorkspace
            data={resource.data}
            canManage={canManage}
            canViewCacheContent={canViewCacheContent}
            canViewPreparations={canViewPreparations}
            canManagePreparations={canManagePreparations}
            mutateData={resource.mutate}
            reloadData={resource.reload}
          />
        </>
      )}
    </div>
  );
}

function SpeechWorkspace({
  data,
  canManage,
  canViewCacheContent,
  canViewPreparations,
  canManagePreparations,
  mutateData,
  reloadData,
}: {
  data: SpeechAdminStatus;
  canManage: boolean;
  canViewCacheContent: boolean;
  canViewPreparations: boolean;
  canManagePreparations: boolean;
  mutateData: SpeechDataMutator;
  reloadData: () => Promise<void>;
}) {
  const { api } = useAuth();
  const [draft, setDraft] = useState<SpeechSettingsDraft>(() => speechSettingsToDraft(data.settings));
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saveMessage, setSaveMessage] = useState<string | null>(null);
  const [selectedPhase, setSelectedPhase] = useState<SpeechPhase>(
    data.template_definitions[0]?.phase ?? 'availability',
  );
  const [preview, setPreview] = useState<SpeechPreview | null>(null);
  const [previewStarting, setPreviewStarting] = useState(false);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const previousServerSettingsRef = useRef(data.settings);
  const settingsPayload = speechDraftToSettings(draft);
  const settingsDirty = !speechSettingsEqual(settingsPayload, data.settings);
  const selectedModel = data.models.find((model) => model.id === draft.model_id) ?? null;
  const selectedVoiceProfile = data.voice_profiles.find((profile) => profile.id === draft.voice_profile_id) ?? null;
  const configurationIssue = speechConfigurationIssue({
    enabled: draft.enabled,
    model: selectedModel,
    voiceProfileId: draft.voice_profile_id,
    voiceProfile: selectedVoiceProfile,
  });
  const previewActive = speechWorkIsActive(preview?.status);
  const previewId = preview?.id ?? null;

  useEffect(() => {
    const previousSettings = previousServerSettingsRef.current;
    if (speechSettingsEqual(previousSettings, data.settings)) return;

    setDraft((current) => speechSettingsEqual(speechDraftToSettings(current), previousSettings)
      ? speechSettingsToDraft(data.settings)
      : current);
    previousServerSettingsRef.current = data.settings;
  }, [data.settings]);

  useEffect(() => {
    if (previewId === null || !previewActive) return undefined;

    let disposed = false;
    let requestInFlight = false;
    const intervalId = window.setInterval(() => {
      if (requestInFlight || document.visibilityState !== 'visible') return;
      requestInFlight = true;
      void api.get<SpeechPreview>(`/admin/speech/previews/${encodeURIComponent(previewId)}`)
        .then((response) => {
          if (!disposed) setPreview(response.data);
        })
        .catch((error) => {
          if (!disposed) setPreviewError(apiErrorMessage(error, 'De proefmelding kon niet worden gevolgd.'));
        })
        .finally(() => {
          requestInFlight = false;
        });
    }, SPEECH_POLL_INTERVAL_MS);

    return () => {
      disposed = true;
      window.clearInterval(intervalId);
    };
  }, [api, previewActive, previewId]);

  function updateDraft(next: SpeechSettingsDraft) {
    setDraft(next);
    setSaveError(null);
    setSaveMessage(null);
  }

  async function saveSettings() {
    if (configurationIssue !== null) {
      setSaveError(configurationIssue.message);
      setSaveMessage(null);
      return;
    }

    setSaving(true);
    setSaveError(null);
    setSaveMessage(null);
    try {
      const response = await api.patch<SpeechAdminStatus>('/admin/speech/settings', settingsPayload);
      mutateData(response.data);
      setDraft(speechSettingsToDraft(response.data.settings));
      setSaveMessage('Spraakinstellingen en sjablonen zijn opgeslagen.');
    } catch (error) {
      setSaveError(apiErrorMessage(error, 'Spraakinstellingen opslaan is mislukt.'));
    } finally {
      setSaving(false);
    }
  }

  async function startPreview() {
    setPreviewStarting(true);
    setPreviewError(null);
    setPreview(null);
    try {
      const response = await api.post<SpeechPreview>('/admin/speech/previews', { phase: selectedPhase });
      setPreview(response.data);
    } catch (error) {
      setPreviewError(apiErrorMessage(error, 'De proefmelding kon niet worden gestart.'));
    } finally {
      setPreviewStarting(false);
    }
  }

  return (
    <>
      <PreviewPanel
        definitions={data.template_definitions}
        draft={draft}
        selectedPhase={selectedPhase}
        preview={preview}
        previewStarting={previewStarting}
        previewError={previewError}
        settingsDirty={settingsDirty}
        canManage={canManage}
        onPhaseChange={setSelectedPhase}
        onStartPreview={() => void startPreview()}
      />

      <ServerVoicePanel
        data={data}
        draft={draft}
        saving={saving}
        saveError={saveError}
        saveMessage={saveMessage}
        settingsDirty={settingsDirty}
        blockedReason={configurationIssue?.message ?? null}
        canManage={canManage}
        onDraftChange={updateDraft}
        onSave={() => void saveSettings()}
      />

      <ModelCatalogPanel data={data} canManage={canManage} mutateData={mutateData} />

      <VoiceProfilesPanel
        profiles={data.voice_profiles}
        models={data.models}
        activeProfileId={data.settings.voice_profile_id}
        canManage={canManage}
        reloadData={reloadData}
      />

      <TemplatePanel
        definitions={data.template_definitions}
        draft={draft}
        saving={saving}
        saveError={saveError}
        saveMessage={saveMessage}
        settingsDirty={settingsDirty}
        blockedReason={configurationIssue?.message ?? null}
        canManage={canManage}
        onDraftChange={updateDraft}
        onSave={() => void saveSettings()}
      />

      <SpeechPreparationLibrary
        canView={canViewPreparations}
        canManage={canManage && canManagePreparations}
      />

      <CachePanel
        data={data}
        canManage={canManage}
        canViewContent={canViewCacheContent}
        reloadData={reloadData}
      />
    </>
  );
}

function PreviewPanel({
  definitions,
  draft,
  selectedPhase,
  preview,
  previewStarting,
  previewError,
  settingsDirty,
  canManage,
  onPhaseChange,
  onStartPreview,
}: {
  definitions: SpeechTemplateDefinition[];
  draft: SpeechSettingsDraft;
  selectedPhase: SpeechPhase;
  preview: SpeechPreview | null;
  previewStarting: boolean;
  previewError: string | null;
  settingsDirty: boolean;
  canManage: boolean;
  onPhaseChange: (phase: SpeechPhase) => void;
  onStartPreview: () => void;
}) {
  const definition = definitions.find((candidate) => candidate.phase === selectedPhase) ?? null;
  const previewForPhase = preview?.phase === selectedPhase ? preview : null;
  const lines = previewForPhase?.rendered_lines.length
    ? previewForPhase.rendered_lines
    : definition?.example_rendered_lines ?? [];
  const rendered = renderSpeechTemplate(lines.join('\n'), {});
  const draftTokens = speechTemplateTokens(draft.templates[selectedPhase]);
  const allowedTokens = new Set((definition?.allowed_tokens ?? []).map(normalizeSpeechToken));
  const unsupportedTokens = draftTokens.filter((token) => !allowedTokens.has(token));
  const previewProgress = normalizeSpeechProgress(previewForPhase?.progress_percent);
  const previewPending = previewStarting || speechWorkIsActive(previewForPhase?.status);
  const audioSource = previewForPhase?.status === 'ready'
    ? `${apiBaseUrl.replace(/\/$/, '')}${fixedSpeechPreviewAudioPath(previewForPhase.id)}`
    : null;

  return (
    <Panel
      title="Proefmelding"
      action={previewForPhase
        ? <StatusPill value={speechStatusLabel(previewForPhase.status)} tone={speechStatusTone(previewForPhase.status)} />
        : <StatusPill value="Vast voorbeeld" />}
    >
      <div className={styles.sectionBody}>
        <div className={styles.sectionIntro}>
          <div>
            <strong>Hoor precies wat de centrale server uitspreekt</strong>
            <p>De server gebruikt vaste, niet-operationele voorbeeldgegevens. Vrije meldingstekst wordt nooit naar deze proefroute gestuurd.</p>
          </div>
          <ShieldCheck aria-hidden size={22} />
        </div>

        <div className={styles.phaseTabs} role="group" aria-label="Kies een meldingsfase">
          {definitions.map((candidate) => (
            <button
              key={candidate.phase}
              type="button"
              className={candidate.phase === selectedPhase ? styles.phaseTabActive : styles.phaseTab}
              aria-pressed={candidate.phase === selectedPhase}
              onClick={() => onPhaseChange(candidate.phase)}
            >
              {candidate.label}
            </button>
          ))}
        </div>

        {definition ? (
          <div className={styles.exampleHeader}>
            <div>
              <span>Vaste voorbeeldvelden</span>
              <div className={styles.tokenSummary}>
                {definition.allowed_tokens.map((token) => (
                  <span key={token}>{speechTokenLabel(token)}</span>
                ))}
              </div>
            </div>
            <small>{lines.length} semantische {lines.length === 1 ? 'regel' : 'regels'}</small>
          </div>
        ) : null}

        <ol className={styles.segmentBand} aria-label="Uitgesproken segmenten">
          {rendered.segments.length ? rendered.segments.map((segment) => (
            <li key={`${segment.index}-${segment.source}`}>
              <span aria-hidden>{String(segment.index).padStart(2, '0')}</span>
              <p>{segment.rendered}</p>
            </li>
          )) : (
            <li className={styles.emptySegment}>
              <AlertTriangle aria-hidden size={18} />
              <p>Voor deze fase zijn nog geen voorbeeldregels beschikbaar.</p>
            </li>
          )}
        </ol>

        {unsupportedTokens.length ? (
          <p className={styles.validationWarning} role="alert">
            <AlertTriangle aria-hidden size={18} />
            Niet beschikbare variabelen in het concept: {unsupportedTokens.map((token) => `{${token}}`).join(', ')}.
          </p>
        ) : null}
        {rendered.missingTokens.length ? (
          <p className={styles.validationWarning} role="alert">
            <AlertTriangle aria-hidden size={18} />
            De voorbeeldgegevens missen: {rendered.missingTokens.map((token) => speechTokenLabel(token)).join(', ')}.
          </p>
        ) : null}
        {settingsDirty ? (
          <p className={styles.inlineNotice} role="status">
            Sla het concept eerst op. Een proefmelding gebruikt altijd het actuele serversjabloon.
          </p>
        ) : null}
        {previewError ? <p className="form-error" role="alert">{previewError}</p> : null}
        {previewForPhase?.status === 'failed' ? (
          <p className="form-error" role="alert">
            Genereren is mislukt{previewForPhase.error_code ? ` (${previewForPhase.error_code})` : ''}.
          </p>
        ) : null}

        {previewPending ? (
          <ProgressBlock
            label={previewStarting ? 'Proefmelding aanvragen' : 'Serveraudio genereren'}
            value={previewStarting ? 0 : previewProgress}
          />
        ) : null}

        <div className={styles.actionsRow}>
          {audioSource ? (
            <SpeechPreviewAudioPlayer key={audioSource} source={audioSource} />
          ) : <span className={styles.actionHint}>Audio verschijnt hier zodra de server klaar is.</span>}
          <button
            className="primary-button"
            type="button"
            disabled={!canManage || settingsDirty || previewPending || definition === null}
            onClick={onStartPreview}
          >
            <Volume2 aria-hidden size={18} />
            {previewPending ? 'Genereren...' : 'Proefmelding genereren'}
          </button>
        </div>
      </div>
    </Panel>
  );
}

function SpeechPreviewAudioPlayer({ source }: { source: string }) {
  const [loadFailed, setLoadFailed] = useState(false);

  return (
    <div className={styles.audioPreview}>
      <audio
        aria-label="Proefmelding afspelen"
        className={styles.audioPlayer}
        controls
        preload="metadata"
        src={source}
        onCanPlay={() => setLoadFailed(false)}
        onError={() => setLoadFailed(true)}
      >
        Uw browser ondersteunt geen audio-afspelen.
      </audio>
      {loadFailed ? (
        <p className={styles.audioError} role="alert">
          <AlertTriangle aria-hidden size={17} />
          De audio kon niet worden geladen. Genereer de proefmelding opnieuw of vernieuw deze pagina.
        </p>
      ) : null}
    </div>
  );
}

function ServerVoicePanel({
  data,
  draft,
  saving,
  saveError,
  saveMessage,
  settingsDirty,
  blockedReason,
  canManage,
  onDraftChange,
  onSave,
}: {
  data: SpeechAdminStatus;
  draft: SpeechSettingsDraft;
  saving: boolean;
  saveError: string | null;
  saveMessage: string | null;
  settingsDirty: boolean;
  blockedReason: string | null;
  canManage: boolean;
  onDraftChange: (next: SpeechSettingsDraft) => void;
  onSave: () => void;
}) {
  const selectedModel = data.models.find((model) => model.id === draft.model_id) ?? null;
  const selectedProfile = data.voice_profiles.find((profile) => profile.id === draft.voice_profile_id) ?? null;
  const selectedProfileReady = selectedModel !== null
    && selectedProfile !== null
    && speechVoiceProfileIsReadyForModel(selectedProfile, selectedModel.id);
  const readyCompatibleProfiles = selectedModel === null ? [] : data.voice_profiles.filter(
    (profile) => speechVoiceProfileIsReadyForModel(profile, selectedModel.id),
  );
  const builtInVoiceModel = data.models.find(
    (model) => model.built_in_voice_available && model.status === 'installed',
  ) ?? data.models.find((model) => model.built_in_voice_available) ?? null;
  const profileRequired = selectedModel !== null && !selectedModel.built_in_voice_available;
  const modelSelectionInvalid = draft.enabled
    && (selectedModel === null || selectedModel.status !== 'installed');
  const selectedProfileInvalid = draft.voice_profile_id !== null
    && (selectedProfile === null
      || selectedProfile.status !== 'ready'
      || (selectedModel !== null && !selectedProfile.compatible_model_ids.includes(selectedModel.id)));
  const voiceSelectionInvalid = selectedProfileInvalid
    || (draft.enabled && selectedModel?.status === 'installed'
      && draft.voice_profile_id === null && profileRequired);
  const voiceHelpId = 'speech-active-voice-help';
  const voiceHelpText = (() => {
    if (selectedModel === null) return 'Kies eerst een geïnstalleerd servermodel.';
    if (draft.voice_profile_id !== null && selectedProfile === null) {
      return 'Het gekozen stemprofiel is niet meer beschikbaar. Kies een ander gereed stemprofiel.';
    }
    if (selectedProfile !== null && selectedProfile.status !== 'ready') {
      return `${selectedProfile.name} is nog niet gereed. Kies een gereed stemprofiel of wacht tot de verwerking klaar is.`;
    }
    if (selectedProfile !== null && !selectedProfile.compatible_model_ids.includes(selectedModel.id)) {
      return `${selectedProfile.name} is niet geschikt voor ${selectedModel.name}. Kies een compatibel stemprofiel.`;
    }
    if (selectedProfileReady) return `${selectedProfile.name} is gereed en geschikt voor ${selectedModel.name}.`;
    if (selectedModel.built_in_voice_available) {
      return 'Zonder eigen profiel gebruikt dit model zijn ingebouwde serverstem.';
    }
    if (readyCompatibleProfiles.length > 0) {
      return `${selectedModel.name} heeft geen ingebouwde stem. Kies een stemprofiel met status Gereed.`;
    }
    if (builtInVoiceModel?.status === 'installed') {
      return `${selectedModel.name} heeft geen ingebouwde stem. Kies ${builtInVoiceModel.name} als servermodel, of maak onder ‘Eigen serverstemmen’ een profiel aan.`;
    }
    if (builtInVoiceModel !== null) {
      return `${selectedModel.name} heeft geen ingebouwde stem. Installeer ${builtInVoiceModel.name} onder ‘Servermodellen’ en selecteer het daarna, of maak een eigen stemprofiel aan.`;
    }

    return `${selectedModel.name} heeft geen ingebouwde stem. Maak onder ‘Eigen serverstemmen’ eerst een profiel aan en wacht op Gereed.`;
  })();

  function changeModel(modelId: string | null) {
    const currentProfile = data.voice_profiles.find((profile) => profile.id === draft.voice_profile_id) ?? null;
    const keepCurrentProfile = modelId !== null
      && currentProfile !== null
      && speechVoiceProfileIsReadyForModel(currentProfile, modelId);
    onDraftChange({
      ...draft,
      model_id: modelId,
      voice_profile_id: keepCurrentProfile ? currentProfile.id : null,
    });
  }

  return (
    <Panel title="Centrale serverstem" action={<StatusPill value={draft.enabled ? 'Ingeschakeld' : 'Uitgeschakeld'} tone={draft.enabled ? 'good' : 'neutral'} />}>
      <div className={styles.sectionBody}>
        <div className={styles.emergencyNotice}>
          <Volume2 aria-hidden size={22} />
          <div>
            <strong>Serverstem en telefoonnoodstem zijn bewust gescheiden</strong>
            <p>Model, stemprofiel en tempo hieronder gelden alleen voor de centrale serverstem. Bij een timeout gebruikt de telefoon een vaste Nederlandse <b>nl-NL</b>-apparaatstem. Er is geen Vlaamse of andere toestelstem te kiezen; operators kunnen TTS alleen aan- of uitzetten.</p>
          </div>
        </div>

        <div className={styles.settingsGrid}>
          <label className={styles.checkboxCard}>
            <input
              type="checkbox"
              checked={draft.enabled}
              disabled={saving}
              onChange={(event) => onDraftChange({ ...draft, enabled: event.target.checked })}
            />
            <span>
              <strong>Centrale serverstem gebruiken</strong>
              <small>De operationele fallback op toestellen blijft onafhankelijk beschikbaar.</small>
            </span>
          </label>

          <label>
            Actief servermodel
            <select
              value={draft.model_id ?? ''}
              disabled={saving}
              aria-invalid={modelSelectionInvalid || undefined}
              onChange={(event) => changeModel(event.target.value || null)}
            >
              <option value="">Geen servermodel actief</option>
              {draft.model_id !== null && selectedModel === null ? (
                <option value={draft.model_id} disabled>Niet meer beschikbaar model</option>
              ) : null}
              {data.models.map((model) => (
                <option
                  key={model.id}
                  value={model.id}
                  disabled={model.status !== 'installed' && model.id !== draft.model_id}
                >
                  {model.name} · {speechStatusLabel(model.status)}
                </option>
              ))}
            </select>
          </label>

          <label>
            Actief stemprofiel
            <select
              value={draft.voice_profile_id ?? ''}
              disabled={saving || selectedModel === null}
              aria-invalid={voiceSelectionInvalid || undefined}
              aria-describedby={voiceHelpId}
              onChange={(event) => onDraftChange({ ...draft, voice_profile_id: event.target.value || null })}
            >
              <option value="">
                {selectedModel === null
                  ? 'Kies eerst een servermodel'
                  : profileRequired
                    ? 'Kies een gereed stemprofiel'
                    : 'Ingebouwde stem van dit servermodel'}
              </option>
              {draft.voice_profile_id !== null && selectedProfile === null ? (
                <option value={draft.voice_profile_id} disabled>Niet meer beschikbaar stemprofiel</option>
              ) : null}
              {data.voice_profiles.map((profile) => {
                const compatible = selectedModel !== null
                  && profile.compatible_model_ids.includes(selectedModel.id);
                const available = compatible && profile.status === 'ready';
                const availabilityLabel = !compatible && selectedModel !== null
                  ? 'Niet compatibel'
                  : speechStatusLabel(profile.status);
                return (
                  <option key={profile.id} value={profile.id} disabled={!available}>
                    {profile.name} · {profile.locale} · {availabilityLabel}
                  </option>
                );
              })}
            </select>
            <small
              id={voiceHelpId}
              className={voiceSelectionInvalid ? styles.fieldWarning : styles.fieldHelp}
              role={voiceSelectionInvalid ? 'status' : undefined}
              aria-live={voiceSelectionInvalid ? 'polite' : undefined}
            >
              {voiceHelpText}
            </small>
          </label>

          <label className={styles.speedControl}>
            <span>Spreeksnelheid <output>{draft.speed.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}×</output></span>
            <input
              type="range"
              min="0.85"
              max="1.15"
              step="0.01"
              value={draft.speed}
              disabled={saving}
              onChange={(event) => onDraftChange({ ...draft, speed: Number(event.target.value) })}
            />
            <span className={styles.rangeLabels}><small>Rustiger · 0,85×</small><small>Sneller · 1,15×</small></span>
          </label>

          <label className={styles.checkboxCard}>
            <input
              type="checkbox"
              checked={draft.pre_generate_on_save}
              disabled={saving}
              onChange={(event) => onDraftChange({ ...draft, pre_generate_on_save: event.target.checked })}
            />
            <span>
              <strong>Cache voorverwarmen na opslaan</strong>
              <small>Laat veelgebruikte semantische segmenten vooraf op schijf genereren.</small>
            </span>
          </label>
        </div>

        <SettingsSaveFooter
          dirty={settingsDirty}
          saving={saving}
          error={saveError}
          message={saveMessage}
          blockedReason={blockedReason}
          canManage={canManage}
          onSave={onSave}
        />
      </div>
    </Panel>
  );
}

function ModelCatalogPanel({
  data,
  canManage,
  mutateData,
}: {
  data: SpeechAdminStatus;
  canManage: boolean;
  mutateData: SpeechDataMutator;
}) {
  const { api } = useAuth();
  const [confirmedLicenses, setConfirmedLicenses] = useState<Record<string, boolean>>({});
  const [installingId, setInstallingId] = useState<string | null>(null);
  const [installError, setInstallError] = useState<string | null>(null);

  async function installModel(model: SpeechModel) {
    setInstallingId(model.id);
    setInstallError(null);
    try {
      const response = await api.post<SpeechModelInstallStarted>(
        `/admin/speech/models/${encodeURIComponent(model.id)}/install`,
        { license_confirmed: true },
      );
      mutateData((current) => current === null ? current : {
        ...current,
        models: current.models.map((candidate) => candidate.id === response.data.model.id ? response.data.model : candidate),
      });
    } catch (error) {
      setInstallError(apiErrorMessage(error, `${model.name} installeren is mislukt.`));
    } finally {
      setInstallingId(null);
    }
  }

  return (
    <Panel title="Servermodellen" action={<span className={styles.panelCount}>{data.models.length} uit servercatalogus</span>}>
      <div className={styles.sectionBody}>
        <p className={styles.sectionLead}>Alleen modellen uit de servercatalogus verschijnen hier. Omvang, licentiebeleid en CPU-geschiktheid blijven daardoor centraal beheerd.</p>
        {installError ? <p className="form-error" role="alert">{installError}</p> : null}
        <div className={styles.modelGrid}>
          {data.models.map((model) => {
            const active = speechWorkIsActive(model.status);
            const installed = model.status === 'installed';
            const licenseConfirmed = confirmedLicenses[model.id] === true;
            return (
              <article key={model.id} className={installed ? `${styles.modelCard} ${styles.modelCardReady}` : styles.modelCard}>
                <header>
                  <div>
                    <span className={styles.qualityLabel}>{model.quality_tier}</span>
                    <h3>{model.name}</h3>
                  </div>
                  <StatusPill value={speechStatusLabel(model.status)} tone={speechStatusTone(model.status)} />
                </header>
                <p>{model.description}</p>

                <dl className={styles.modelFacts}>
                  <div><dt>Parameters</dt><dd>{formatSpeechParameterCount(model.parameter_count)}</dd></div>
                  <div><dt>Download</dt><dd>{formatSpeechBytes(model.download_bytes)}</dd></div>
                  <div><dt>Licentie</dt><dd>{model.license_spdx}</dd></div>
                  <div><dt>Commercieel</dt><dd>{model.commercial_use ? 'Toegestaan' : 'Niet toegestaan'}</dd></div>
                </dl>

                <div className={styles.cpuNote}>
                  <Cpu aria-hidden size={19} />
                  <span>
                    <strong>{model.cpu.supported ? 'CPU ondersteund' : 'CPU niet ondersteund'} · aanbevolen RAM {formatSpeechBytes(model.cpu.recommended_ram_bytes)}</strong>
                    {model.cpu.note}
                  </span>
                </div>

                <div className={styles.tagRows}>
                  <div><span>Talen</span><p>{model.supported_languages.join(' · ') || '-'}</p></div>
                  <div><span>Functies</span><p>{speechModelCapabilityLabels(model.capabilities).join(' · ') || '-'}</p></div>
                </div>

                {active ? <ProgressBlock label={speechStatusLabel(model.status)} value={normalizeSpeechProgress(model.progress_percent)} /> : null}
                {model.status === 'failed' ? (
                  <p className="form-error" role="alert">Installatie mislukt{model.error_code ? ` (${model.error_code})` : ''}.</p>
                ) : null}
                {model.installed_revision ? <small className={styles.revision}>Revisie {model.installed_revision}</small> : null}

                {!installed && !active ? (
                  <label className={styles.licenseCheck}>
                    <input
                      type="checkbox"
                      checked={licenseConfirmed}
                      onChange={(event) => setConfirmedLicenses((current) => ({ ...current, [model.id]: event.target.checked }))}
                    />
                    <span>Ik heb licentie {model.license_spdx} en de gebruiksvoorwaarden beoordeeld.</span>
                  </label>
                ) : null}

                <button
                  className={installed ? 'secondary-button' : 'primary-button'}
                  type="button"
                  disabled={!canManage || installed || active || installingId !== null || !licenseConfirmed}
                  onClick={() => void installModel(model)}
                >
                  {installed ? <CheckCircle2 aria-hidden size={18} /> : <Download aria-hidden size={18} />}
                  {installed ? 'Geïnstalleerd' : installingId === model.id ? 'Installatie starten...' : 'Model installeren'}
                </button>
              </article>
            );
          })}
        </div>
      </div>
    </Panel>
  );
}

function VoiceProfilesPanel({
  profiles,
  models,
  activeProfileId,
  canManage,
  reloadData,
}: {
  profiles: SpeechVoiceProfile[];
  models: SpeechModel[];
  activeProfileId: string | null;
  canManage: boolean;
  reloadData: () => Promise<void>;
}) {
  const { api } = useAuth();
  const [name, setName] = useState('');
  const locale = 'nl-NL';
  const [transcript, setTranscript] = useState('');
  const [consentConfirmed, setConsentConfirmed] = useState(false);
  const [audioFile, setAudioFile] = useState<File | null>(null);
  const [audioUrl, setAudioUrl] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [uploadProgress, setUploadProgress] = useState<number | null>(null);
  const [profileError, setProfileError] = useState<string | null>(null);
  const [profileMessage, setProfileMessage] = useState<string | null>(null);
  const [deletingId, setDeletingId] = useState<string | null>(null);
  const [recording, setRecording] = useState(false);
  const [requestingMicrophone, setRequestingMicrophone] = useState(false);
  const [recordingSeconds, setRecordingSeconds] = useState(0);
  const recorderRef = useRef<MediaRecorder | null>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const recordingChunksRef = useRef<Blob[]>([]);
  const recordingTimerRef = useRef<number | null>(null);
  const microphoneRequestGenerationRef = useRef(0);
  const microphoneRequestPendingRef = useRef(false);
  const mountedRef = useRef(false);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    if (audioFile === null) {
      setAudioUrl(null);
      return undefined;
    }

    const nextUrl = URL.createObjectURL(audioFile);
    setAudioUrl(nextUrl);
    return () => URL.revokeObjectURL(nextUrl);
  }, [audioFile]);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      microphoneRequestGenerationRef.current += 1;
      microphoneRequestPendingRef.current = false;
      if (recordingTimerRef.current !== null) window.clearInterval(recordingTimerRef.current);
      const recorder = recorderRef.current;
      if (recorder !== null && recorder.state !== 'inactive') {
        recorder.ondataavailable = null;
        recorder.onstop = null;
        recorder.stop();
      }
      stopMediaStream(streamRef.current);
    };
  }, []);

  async function startRecording() {
    setProfileError(null);
    if (microphoneRequestPendingRef.current) return;
    if (!window.isSecureContext) {
      setProfileError(microphoneRecordingError(null, false));
      return;
    }
    if (typeof MediaRecorder === 'undefined' || navigator.mediaDevices?.getUserMedia === undefined) {
      setProfileError('Deze browser ondersteunt geen microfoonopname. Upload in plaats daarvan een audiobestand.');
      return;
    }

    microphoneRequestPendingRef.current = true;
    const requestGeneration = microphoneRequestGenerationRef.current + 1;
    microphoneRequestGenerationRef.current = requestGeneration;
    setRequestingMicrophone(true);
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      if (!microphoneRequestIsCurrent(
        mountedRef.current,
        microphoneRequestGenerationRef.current,
        requestGeneration,
      )) {
        stopMediaStream(stream);
        return;
      }
      streamRef.current = stream;
      const mimeType = preferredRecordingMimeType();
      const recorder = mimeType === null ? new MediaRecorder(stream) : new MediaRecorder(stream, { mimeType });
      recorderRef.current = recorder;
      recordingChunksRef.current = [];
      setRecordingSeconds(0);
      recorder.ondataavailable = (event) => {
        if (event.data.size > 0) recordingChunksRef.current.push(event.data);
      };
      recorder.onstop = () => {
        if (recordingTimerRef.current !== null) {
          window.clearInterval(recordingTimerRef.current);
          recordingTimerRef.current = null;
        }
        const recordingType = recorder.mimeType || mimeType || 'audio/webm';
        const blob = new Blob(recordingChunksRef.current, { type: recordingType });
        if (blob.size > 0) {
          setAudioFile(new File([blob], `stemopname-${Date.now()}.${recordingExtension(recordingType)}`, { type: recordingType }));
        }
        stopMediaStream(streamRef.current);
        streamRef.current = null;
        recorderRef.current = null;
        setRecording(false);
      };
      recorder.start();
      setRecording(true);
      recordingTimerRef.current = window.setInterval(() => {
        setRecordingSeconds((seconds) => seconds + 1);
      }, 1_000);
    } catch (error) {
      stopMediaStream(streamRef.current);
      streamRef.current = null;
      if (microphoneRequestIsCurrent(
        mountedRef.current,
        microphoneRequestGenerationRef.current,
        requestGeneration,
      )) {
        setProfileError(microphoneRecordingError(error, window.isSecureContext));
      }
    } finally {
      if (microphoneRequestGenerationRef.current === requestGeneration) {
        microphoneRequestPendingRef.current = false;
        if (mountedRef.current) setRequestingMicrophone(false);
      }
    }
  }

  function stopRecording() {
    if (recordingTimerRef.current !== null) {
      window.clearInterval(recordingTimerRef.current);
      recordingTimerRef.current = null;
    }
    if (recorderRef.current?.state === 'recording') recorderRef.current.stop();
  }

  async function uploadProfile() {
    if (audioFile === null) {
      setProfileError('Kies een audiobestand of neem een stemvoorbeeld op.');
      return;
    }
    if (!consentConfirmed) {
      setProfileError('Bevestig de toestemming voor het stemvoorbeeld.');
      return;
    }

    const payload = new FormData();
    payload.append('name', name.trim());
    payload.append('locale', locale.trim());
    payload.append('transcript', transcript.trim());
    payload.append('consent_confirmed', '1');
    payload.append('audio', audioFile);

    setUploading(true);
    setUploadProgress(0);
    setProfileError(null);
    setProfileMessage(null);
    try {
      await api.postForm<SpeechVoiceProfile>('/admin/speech/voice-profiles', payload, (progress) => {
        setUploadProgress(progress.percentage);
      });
      setUploadProgress(100);
      setName('');
      setTranscript('');
      setConsentConfirmed(false);
      setAudioFile(null);
      if (fileInputRef.current !== null) fileInputRef.current.value = '';
      setProfileMessage('Stemvoorbeeld geüpload. De server verwerkt het profiel op de achtergrond.');
      await reloadData();
    } catch (error) {
      setProfileError(apiErrorMessage(error, 'Stemprofiel uploaden is mislukt.'));
    } finally {
      setUploading(false);
    }
  }

  async function deleteProfile(profile: SpeechVoiceProfile) {
    if (!window.confirm(`Stemprofiel “${profile.name}” verwijderen?`)) return;
    setDeletingId(profile.id);
    setProfileError(null);
    setProfileMessage(null);
    try {
      await api.delete<void>(`/admin/speech/voice-profiles/${encodeURIComponent(profile.id)}`);
      setProfileMessage(`${profile.name} is verwijderd.`);
      await reloadData();
    } catch (error) {
      setProfileError(apiErrorMessage(error, 'Stemprofiel verwijderen is mislukt.'));
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <Panel title="Eigen serverstemmen" action={<span className={styles.panelCount}>{profiles.length} profielen</span>}>
      <div className={styles.sectionBody}>
        <p className={styles.sectionLead}>Een stemprofiel is alleen voor de centrale servergenerator. Het verandert nooit de vaste nl-NL-noodstem op een telefoon.</p>

        {profiles.length ? (
          <div className={styles.profileGrid}>
            {profiles.map((profile) => {
              const compatibleNames = models
                .filter((model) => profile.compatible_model_ids.includes(model.id))
                .map((model) => model.name);
              return (
                <article key={profile.id} className={styles.profileCard}>
                  <header>
                    <div>
                      <h3>{profile.name}</h3>
                      <span>{profile.locale} · {profile.reference_duration_seconds.toLocaleString('nl-NL')} sec.</span>
                    </div>
                    <StatusPill value={profile.id === activeProfileId ? 'Actief' : speechStatusLabel(profile.status)} tone={profile.id === activeProfileId || profile.status === 'ready' ? 'good' : speechStatusTone(profile.status)} />
                  </header>
                  <p>Compatibel met: {compatibleNames.join(', ') || 'nog niet vastgesteld'}</p>
                  <small>Aangemaakt {formatDateTime(profile.created_at)}</small>
                  {speechWorkIsActive(profile.status) ? <ProgressBlock label={speechStatusLabel(profile.status)} value={0} /> : null}
                  <button
                    className="secondary-button"
                    type="button"
                    disabled={!canManage || deletingId !== null}
                    onClick={() => void deleteProfile(profile)}
                  >
                    <Trash2 aria-hidden size={17} />
                    {deletingId === profile.id ? 'Verwijderen...' : 'Verwijderen'}
                  </button>
                </article>
              );
            })}
          </div>
        ) : (
          <p className={styles.emptyState}>
            Nog geen eigen stemprofielen. Alleen een model met een ingebouwde serverstem kan zonder gereed profiel worden gebruikt.
          </p>
        )}

        <div className={styles.uploadStudio}>
          <div className={styles.uploadHeading}>
            <span className={styles.studioIcon} aria-hidden><Mic size={22} /></span>
            <div><h3>Nieuw stemprofiel</h3><p>Upload een duidelijk fragment of neem direct in de browser op.</p></div>
          </div>

          <div className={styles.uploadGrid}>
            <label>
              Naam
              <input value={name} onChange={(event) => setName(event.target.value)} maxLength={100} required />
            </label>
            <label>
              Taalcode
              <input value={locale} readOnly aria-readonly="true" />
            </label>
            <label className={styles.wideField}>
              Exact transcript van het fragment
              <textarea value={transcript} onChange={(event) => setTranscript(event.target.value)} rows={3} required />
            </label>
          </div>

          <div className={styles.captureRow}>
            <label className={styles.filePicker}>
              <Upload aria-hidden size={18} />
              <span>{audioFile ? 'Ander bestand kiezen' : 'Audiobestand kiezen'}</span>
              <input
                ref={fileInputRef}
                type="file"
                accept="audio/wav,audio/x-wav,audio/mpeg,audio/mp4,audio/webm,audio/ogg"
                onChange={(event) => setAudioFile(event.target.files?.[0] ?? null)}
              />
            </label>
            <span className={styles.captureDivider}>of</span>
            <button
              className={recording ? styles.recordButtonActive : styles.recordButton}
              type="button"
              disabled={!canManage || uploading || requestingMicrophone}
              onClick={() => recording ? stopRecording() : void startRecording()}
            >
              {recording ? <Square aria-hidden size={16} /> : <Mic aria-hidden size={18} />}
              {recording
                ? `Stop opname · ${recordingSeconds} sec.`
                : requestingMicrophone ? 'Toestemming aanvragen…' : 'Opname starten'}
            </button>
          </div>

          {audioFile ? (
            <div className={styles.selectedAudio}>
              <div><strong>{audioFile.name}</strong><span>{formatSpeechBytes(audioFile.size)}</span></div>
              {audioUrl ? <audio controls preload="metadata" src={audioUrl}>Uw browser ondersteunt geen audio-afspelen.</audio> : null}
            </div>
          ) : null}

          <label className={styles.consentCard}>
            <input type="checkbox" checked={consentConfirmed} onChange={(event) => setConsentConfirmed(event.target.checked)} />
            <span><strong>Toestemming vastgelegd</strong>Ik bevestig dat de spreker expliciet toestemming heeft gegeven om dit fragment als serverstem in D.I.S. te verwerken en te gebruiken.</span>
          </label>

          {uploading ? <ProgressBlock label="Stemvoorbeeld uploaden" value={uploadProgress ?? 0} /> : null}
          {profileError ? <p className="form-error" role="alert">{profileError}</p> : null}
          {profileMessage ? <p className="success-text" role="status">{profileMessage}</p> : null}
          <div className={styles.actionsRow}>
            <span className={styles.actionHint}>De server controleert formaat, duur en modelcompatibiliteit.</span>
            <button
              className="primary-button"
              type="button"
              disabled={!canManage || uploading || recording || !name.trim() || !locale.trim() || !transcript.trim() || audioFile === null || !consentConfirmed}
              onClick={() => void uploadProfile()}
            >
              <Upload aria-hidden size={18} />
              {uploading ? 'Uploaden...' : 'Stemprofiel uploaden'}
            </button>
          </div>
        </div>
      </div>
    </Panel>
  );
}

function TemplatePanel({
  definitions,
  draft,
  saving,
  saveError,
  saveMessage,
  settingsDirty,
  blockedReason,
  canManage,
  onDraftChange,
  onSave,
}: {
  definitions: SpeechTemplateDefinition[];
  draft: SpeechSettingsDraft;
  saving: boolean;
  saveError: string | null;
  saveMessage: string | null;
  settingsDirty: boolean;
  blockedReason: string | null;
  canManage: boolean;
  onDraftChange: (next: SpeechSettingsDraft) => void;
  onSave: () => void;
}) {
  const textareas = useRef<Partial<Record<SpeechPhase, HTMLTextAreaElement | null>>>({});

  function updateTemplate(phase: SpeechPhase, value: string) {
    if (saving) return;
    onDraftChange({ ...draft, templates: { ...draft.templates, [phase]: value } });
  }

  function insertToken(definition: SpeechTemplateDefinition, rawToken: string) {
    if (saving) return;
    const token = normalizeSpeechToken(rawToken);
    const textarea = textareas.current[definition.phase] ?? null;
    const result = insertSpeechToken(
      draft.templates[definition.phase],
      token,
      textarea?.selectionStart ?? null,
      textarea?.selectionEnd ?? null,
    );
    updateTemplate(definition.phase, result.value);
    window.requestAnimationFrame(() => {
      const nextTextarea = textareas.current[definition.phase];
      nextTextarea?.focus();
      nextTextarea?.setSelectionRange(result.cursor, result.cursor);
    });
  }

  return (
    <Panel title="Meldingssjablonen" action={<span className={styles.panelCount}>1 regel = 1 spraaksegment</span>}>
      <div className={styles.sectionBody}>
        <div className={styles.sectionIntro}>
          <div>
            <strong>Regels met een vaste betekenis en volgorde</strong>
            <p>Iedere niet-lege regel wordt afzonderlijk gegenereerd en in deze volgorde samengevoegd. Lege regels worden niet opgeslagen.</p>
          </div>
          <Database aria-hidden size={22} />
        </div>

        <div className={styles.templateGrid}>
          {definitions.map((definition) => {
            const value = draft.templates[definition.phase];
            const segments = semanticSpeechLines(value);
            const allowed = new Set(definition.allowed_tokens.map(normalizeSpeechToken));
            const unsupported = speechTemplateTokens(value).filter((token) => !allowed.has(token));
            return (
              <article key={definition.phase} className={styles.templateCard}>
                <header>
                  <div><span>Fase</span><h3>{definition.label}</h3></div>
                  <span>{segments.length} {segments.length === 1 ? 'segment' : 'segmenten'}</span>
                </header>

                <label>
                  Semantische regels
                  <textarea
                    ref={(node) => { textareas.current[definition.phase] = node; }}
                    value={value}
                    rows={Math.max(5, Math.min(9, segments.length + 2))}
                    disabled={saving}
                    onChange={(event) => updateTemplate(definition.phase, event.target.value)}
                    spellCheck
                  />
                </label>

                <div className={styles.tokenBank}>
                  <span>Variabelen</span>
                  <div>
                    {definition.allowed_tokens.map((token) => {
                      const normalized = normalizeSpeechToken(token);
                      return (
                        <button key={token} type="button" disabled={saving} onClick={() => insertToken(definition, token)}>
                          <span>{speechTokenLabel(normalized)}</span>
                          <code>{`{${normalized}}`}</code>
                        </button>
                      );
                    })}
                  </div>
                </div>

                <ol className={styles.compactSegments} aria-label={`Segmentvolgorde voor ${definition.label}`}>
                  {segments.map((line, index) => (
                    <li key={`${index}-${line}`}><span>{index + 1}</span><p>{line}</p></li>
                  ))}
                </ol>

                {segments.length === 0 ? (
                  <p className={styles.validationWarning} role="alert"><AlertTriangle aria-hidden size={17} />Voeg minimaal één niet-lege regel toe.</p>
                ) : null}
                {unsupported.length ? (
                  <p className={styles.validationWarning} role="alert">
                    <AlertTriangle aria-hidden size={17} />Niet toegestaan in deze fase: {unsupported.map((token) => `{${token}}`).join(', ')}.
                  </p>
                ) : null}
              </article>
            );
          })}
        </div>

        <SettingsSaveFooter
          dirty={settingsDirty}
          saving={saving}
          error={saveError}
          message={saveMessage}
          blockedReason={blockedReason}
          canManage={canManage}
          onSave={onSave}
        />
      </div>
    </Panel>
  );
}

function CachePanel({
  data,
  canManage,
  canViewContent,
  reloadData,
}: {
  data: SpeechAdminStatus;
  canManage: boolean;
  canViewContent: boolean;
  reloadData: () => Promise<void>;
}) {
  const { api } = useAuth();
  const [scope, setScope] = useState<SpeechCacheRegenerationScope>('all');
  const [starting, setStarting] = useState(false);
  const [cacheError, setCacheError] = useState<string | null>(null);
  const [cacheMessage, setCacheMessage] = useState<string | null>(null);
  const [cacheBrowserOpen, setCacheBrowserOpen] = useState(false);
  const cache = data.cache;
  const job = cache.active_job ?? null;
  const jobActive = speechWorkIsActive(job?.status);
  const usage = speechCacheUsagePercentage(cache.disk_bytes, cache.quota_bytes);

  async function regenerateCache() {
    setStarting(true);
    setCacheError(null);
    setCacheMessage(null);
    try {
      await api.post<unknown>('/admin/speech/cache/regenerate', { scope });
      setCacheMessage('Cachetaak gestart. Segmenten worden op schijf opgebouwd en voorverwarmd.');
      await reloadData();
    } catch (error) {
      setCacheError(apiErrorMessage(error, 'De cachetaak kon niet worden gestart.'));
    } finally {
      setStarting(false);
    }
  }

  return (
    <>
      <Panel title="Audiocache" action={<StatusPill value={jobActive ? speechStatusLabel(job?.status) : 'Beschikbaar'} tone={jobActive ? 'warn' : 'good'} />}>
        <div className={styles.sectionBody}>
          <div className={styles.cacheIntro}>
            <HardDrive aria-hidden size={24} />
            <div>
              <strong>Audio op schijf, alleen metadata in de database</strong>
              <p>De cache kan snel groeien. De quota-indicator maakt zichtbaar hoeveel lokale schijfruimte de unieke segmenten en samengestelde meldingen gebruiken.</p>
            </div>
          </div>

          <div className={styles.diskUsage}>
            <div><span>Schijfgebruik</span><strong>{formatSpeechBytes(cache.disk_bytes)} van {formatSpeechBytes(cache.quota_bytes)}</strong></div>
            <progress max={100} value={usage} aria-label={`Audiocache gebruikt ${usage} procent`} />
            <small>{usage}% van het ingestelde quotum</small>
          </div>

          <dl className={styles.cacheFacts}>
            <div><dt>Unieke fragmenten</dt><dd>{cache.segment_count.toLocaleString('nl-NL')}</dd></div>
            <div><dt>Samengestelde meldingen</dt><dd>{cache.composite_count.toLocaleString('nl-NL')}</dd></div>
            <div><dt>Cachetreffers</dt><dd>{cache.hit_count.toLocaleString('nl-NL')} · {speechCacheHitRate(cache.hit_count, cache.miss_count)}</dd></div>
            <div><dt>Gemist</dt><dd>{cache.miss_count.toLocaleString('nl-NL')}</dd></div>
            <div><dt>In wachtrij</dt><dd>{cache.pending_count.toLocaleString('nl-NL')}</dd></div>
            <div><dt>Mislukt</dt><dd>{cache.failed_count.toLocaleString('nl-NL')}</dd></div>
            <div><dt>Laatste opschoning</dt><dd>{formatDateTime(cache.last_pruned_at)}</dd></div>
          </dl>

          {job ? (
            <div className={styles.cacheJob}>
              <div><strong>Cachetaak · {cacheScopeLabel(job.scope)}</strong><StatusPill value={speechStatusLabel(job.status)} tone={speechStatusTone(job.status)} /></div>
              <ProgressBlock label="Regenereren en voorverwarmen" value={normalizeSpeechProgress(job.progress_percent)} />
              {job.status === 'failed' ? <p className="form-error" role="alert">Cachetaak mislukt{job.error_code ? ` (${job.error_code})` : ''}.</p> : null}
            </div>
          ) : null}

          {cacheError ? <p className="form-error" role="alert">{cacheError}</p> : null}
          {cacheMessage ? <p className="success-text" role="status">{cacheMessage}</p> : null}
          <div className={styles.cacheActions}>
            <label>
              Bereik
              <select value={scope} onChange={(event) => setScope(event.target.value as SpeechCacheRegenerationScope)}>
                <option value="all">Alles</option>
                <option value="segments">Alleen unieke segmenten</option>
                <option value="composites">Alleen samengestelde meldingen</option>
                <option value="failed">Alleen mislukte items</option>
              </select>
            </label>
            <div className={styles.cacheActionButtons}>
              {canViewContent ? (
                <button
                  className="secondary-button"
                  type="button"
                  disabled={!canManage}
                  aria-haspopup="dialog"
                  onClick={() => setCacheBrowserOpen(true)}
                >
                  <ListMusic aria-hidden size={18} />
                  Cache-inhoud bekijken
                </button>
              ) : null}
              <button className="primary-button" type="button" disabled={!canManage || starting || jobActive} onClick={() => void regenerateCache()}>
                <RefreshCw aria-hidden size={18} />
                {starting || jobActive ? 'Cachetaak actief...' : 'Regenereren en voorverwarmen'}
              </button>
            </div>
          </div>
        </div>
      </Panel>
      {cacheBrowserOpen ? <SpeechCacheEntriesModal onClose={() => setCacheBrowserOpen(false)} /> : null}
    </>
  );
}

type SpeechCacheCategoryFilter = 'all' | SpeechCacheEntryCategory;
type SpeechCacheStatusFilter = 'all' | SpeechCacheEntryStatus;

const EMPTY_SPEECH_CACHE_PAGINATION: PaginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 0,
};

const MODAL_FOCUSABLE_SELECTOR = [
  'a[href]',
  'audio[controls]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

function SpeechCacheEntriesModal({ onClose }: { onClose: () => void }) {
  const { api } = useAuth();
  const dialogRef = useRef<HTMLElement | null>(null);
  const closeButtonRef = useRef<HTMLButtonElement | null>(null);
  const previousFocusRef = useRef<HTMLElement | null>(null);
  const requestGenerationRef = useRef(0);
  const [search, setSearch] = useState('');
  const [deferredSearch, setDeferredSearch] = useState('');
  const [category, setCategory] = useState<SpeechCacheCategoryFilter>('all');
  const [status, setStatus] = useState<SpeechCacheStatusFilter>('all');
  const [page, setPage] = useState(1);
  const [entries, setEntries] = useState<SpeechCacheEntrySummary[]>([]);
  const [pagination, setPagination] = useState<PaginationMeta>(EMPTY_SPEECH_CACHE_PAGINATION);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [reloadGeneration, setReloadGeneration] = useState(0);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setPage(1);
      setDeferredSearch(search.trim());
    }, 250);

    return () => window.clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    const requestGeneration = requestGenerationRef.current + 1;
    requestGenerationRef.current = requestGeneration;
    const parameters = new URLSearchParams({
      page: String(page),
      per_page: String(EMPTY_SPEECH_CACHE_PAGINATION.per_page),
    });
    if (deferredSearch !== '') parameters.set('search', deferredSearch);
    if (category !== 'all') parameters.set('category', category);
    if (status !== 'all') parameters.set('status', status);

    setLoading(true);
    setLoadError(null);
    const requestTimer = window.setTimeout(() => {
      void api.get<SpeechCacheEntrySummary[]>(`/admin/speech/cache/entries?${parameters.toString()}`)
        .then((response) => {
          if (requestGeneration !== requestGenerationRef.current) return;
          setEntries(response.data);
          setPagination(speechCachePagination(response.meta));
        })
        .catch((error) => {
          if (requestGeneration !== requestGenerationRef.current) return;
          setEntries([]);
          setPagination(EMPTY_SPEECH_CACHE_PAGINATION);
          setLoadError(apiErrorMessage(error, 'De cache-inhoud kon niet worden geladen.'));
        })
        .finally(() => {
          if (requestGeneration === requestGenerationRef.current) setLoading(false);
        });
    }, 0);

    return () => {
      window.clearTimeout(requestTimer);
      if (requestGenerationRef.current === requestGeneration) {
        requestGenerationRef.current += 1;
      }
    };
  }, [api, category, deferredSearch, page, reloadGeneration, status]);

  useEffect(() => {
    previousFocusRef.current = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    const bodyWasLocked = document.body.classList.contains(styles.cacheModalBodyLock);
    document.body.classList.add(styles.cacheModalBodyLock);
    const frame = window.requestAnimationFrame(() => closeButtonRef.current?.focus());

    return () => {
      window.cancelAnimationFrame(frame);
      if (!bodyWasLocked) document.body.classList.remove(styles.cacheModalBodyLock);
      previousFocusRef.current?.focus();
    };
  }, []);

  function handleDialogKeyDown(event: ReactKeyboardEvent<HTMLElement>) {
    if (event.key === 'Escape') {
      event.preventDefault();
      onClose();
      return;
    }
    if (event.key !== 'Tab') return;

    const focusable = Array.from(
      dialogRef.current?.querySelectorAll<HTMLElement>(MODAL_FOCUSABLE_SELECTOR) ?? [],
    ).filter((element) => !element.hasAttribute('hidden'));
    if (focusable.length === 0) {
      event.preventDefault();
      dialogRef.current?.focus();
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (first === undefined || last === undefined) return;
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function handleBackdropMouseDown(event: ReactMouseEvent<HTMLDivElement>) {
    if (event.target === event.currentTarget) onClose();
  }

  const hasFilters = deferredSearch !== '' || category !== 'all' || status !== 'all';
  const firstResult = pagination.total === 0
    ? 0
    : ((pagination.current_page - 1) * pagination.per_page) + 1;
  const lastResult = Math.min(pagination.total, pagination.current_page * pagination.per_page);

  return (
    <div
      className={styles.cacheModalBackdrop}
      role="presentation"
      onMouseDown={handleBackdropMouseDown}
    >
      <section
        ref={dialogRef}
        className={styles.cacheModal}
        role="dialog"
        aria-modal="true"
        aria-labelledby="speech-cache-modal-title"
        aria-describedby="speech-cache-modal-description"
        tabIndex={-1}
        onKeyDown={handleDialogKeyDown}
      >
        <header className={styles.cacheModalHeader}>
          <div>
            <span><Database aria-hidden size={16} /> Lokale audiobibliotheek</span>
            <h2 id="speech-cache-modal-title">Inhoud van de audiocache</h2>
            <p id="speech-cache-modal-description">
              Bekijk en beluister de opgeslagen tekstfragmenten. Genereertijd meet alleen het stemmodel;
              wachtrij en audio-omzetting tellen niet mee. Technische sleutels en opslagpaden blijven verborgen.
            </p>
          </div>
          <button
            ref={closeButtonRef}
            className={styles.cacheModalClose}
            type="button"
            aria-label="Cache-inhoud sluiten"
            onClick={onClose}
          >
            <X aria-hidden size={21} />
          </button>
        </header>

        <div className={styles.cacheModalBody}>
          <div className={styles.cacheFilters} aria-label="Cache-inhoud filteren">
            <label className={styles.cacheSearch}>
              Zoeken in tekst
              <span>
                <Search aria-hidden size={17} />
                <input
                  type="search"
                  value={search}
                  placeholder="Bijvoorbeeld straat, plaats of melding"
                  onChange={(event) => setSearch(event.target.value)}
                />
              </span>
            </label>
            <label>
              Categorie
              <select
                value={category}
                onChange={(event) => {
                  setPage(1);
                  setCategory(event.target.value as SpeechCacheCategoryFilter);
                }}
              >
                <option value="all">Alle categorieën</option>
                <option value="segment">Tekstfragmenten</option>
                <option value="composite">Samengestelde meldingen</option>
              </select>
            </label>
            <label>
              Status
              <select
                value={status}
                onChange={(event) => {
                  setPage(1);
                  setStatus(event.target.value as SpeechCacheStatusFilter);
                }}
              >
                <option value="all">Alle statussen</option>
                <option value="ready">Gereed</option>
                <option value="queued">In wachtrij</option>
                <option value="processing">In verwerking</option>
                <option value="failed">Mislukt</option>
                <option value="expired">Verlopen</option>
              </select>
            </label>
          </div>

          {loading ? (
            <div className={styles.cacheModalState} role="status">
              <RefreshCw className={styles.cacheLoadingIcon} aria-hidden size={22} />
              <strong>Cache-inhoud laden</strong>
              <span>De opgeslagen fragmenten worden veilig opgehaald.</span>
            </div>
          ) : loadError ? (
            <div className={styles.cacheModalState} role="alert">
              <AlertTriangle aria-hidden size={22} />
              <strong>Laden mislukt</strong>
              <span>{loadError}</span>
              <button className="secondary-button" type="button" onClick={() => setReloadGeneration((value) => value + 1)}>
                Opnieuw proberen
              </button>
            </div>
          ) : entries.length === 0 ? (
            <div className={styles.cacheModalState} role="status">
              <ListMusic aria-hidden size={23} />
              <strong>{hasFilters ? 'Geen overeenkomende fragmenten' : 'De audiocache is nog leeg'}</strong>
              <span>
                {hasFilters
                  ? 'Pas de zoekterm of filters aan.'
                  : 'Gegenereerde tekstfragmenten en meldingen verschijnen hier zodra ze zijn opgeslagen.'}
              </span>
            </div>
          ) : (
            <>
              <div className={styles.cacheResultSummary} role="status" aria-live="polite">
                <strong>{firstResult}–{lastResult}</strong> van {pagination.total.toLocaleString('nl-NL')} cache-items
              </div>
              <ol className={styles.cacheEntryList}>
                {entries.map((entry) => <SpeechCacheEntryCard key={entry.id} entry={entry} />)}
              </ol>
              <nav className={styles.cachePagination} aria-label="Pagina's met cache-inhoud">
                <button
                  className="secondary-button"
                  type="button"
                  disabled={pagination.current_page <= 1}
                  onClick={() => setPage((current) => Math.max(1, current - 1))}
                >
                  <ChevronLeft aria-hidden size={17} /> Vorige
                </button>
                <span>Pagina <strong>{pagination.current_page}</strong> van <strong>{Math.max(1, pagination.last_page)}</strong></span>
                <button
                  className="secondary-button"
                  type="button"
                  disabled={pagination.current_page >= pagination.last_page}
                  onClick={() => setPage((current) => Math.min(pagination.last_page, current + 1))}
                >
                  Volgende <ChevronRight aria-hidden size={17} />
                </button>
              </nav>
            </>
          )}
        </div>
      </section>
    </div>
  );
}

function SpeechCacheEntryCard({ entry }: { entry: SpeechCacheEntrySummary }) {
  const displayText = entry.text_available && entry.text?.trim()
    ? entry.text.trim()
    : 'Tekst niet beschikbaar voor dit oudere cache-item.';
  const synthesisDuration = entry.category === 'composite'
    ? 'Niet van toepassing'
    : formatSpeechSynthesisDuration(entry.synthesis_duration_ms);
  const audioSource = entry.audio_available && entry.status === 'ready'
    ? `${apiBaseUrl.replace(/\/$/, '')}${fixedSpeechCacheAudioPath(entry.id)}`
    : null;
  const optionalFacts = [
    entry.model_name ? { label: 'Model', value: entry.model_name } : null,
    entry.voice_name
      ? { label: 'Stem', value: entry.voice_name }
      : entry.voice_type === 'built_in'
        ? { label: 'Stem', value: 'Ingebouwde serverstem' }
        : null,
    entry.locale ? { label: 'Taal', value: entry.locale } : null,
    typeof entry.speed === 'number' && Number.isFinite(entry.speed)
      ? { label: 'Snelheid', value: `${entry.speed.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}×` }
      : null,
  ].filter((fact): fact is { label: string; value: string } => fact !== null);

  return (
    <li>
      <article className={`${styles.cacheEntry} ${styles[`cacheEntry_${entry.status}`]}`}>
        <header>
          <span className={styles.cacheEntryCategory}>{speechCacheCategoryLabel(entry.category)}</span>
          <StatusPill value={speechStatusLabel(entry.status)} tone={speechStatusTone(entry.status)} />
        </header>
        <p className={entry.text_available ? styles.cacheEntryText : styles.cacheEntryTextUnavailable}>
          {displayText}
        </p>

        {optionalFacts.length > 0 ? (
          <dl className={styles.cacheEntryIdentity}>
            {optionalFacts.map((fact) => <div key={fact.label}><dt>{fact.label}</dt><dd>{fact.value}</dd></div>)}
          </dl>
        ) : null}

        <dl className={styles.cacheEntryFacts}>
          <div><dt>Audiolengte</dt><dd>{formatSpeechDuration(entry.duration_ms)}</dd></div>
          <div><dt>Genereertijd</dt><dd>{synthesisDuration}</dd></div>
          <div><dt>Grootte</dt><dd>{formatSpeechBytes(entry.byte_size)}</dd></div>
          <div><dt>Gebruikt</dt><dd>{entry.hit_count.toLocaleString('nl-NL')} keer</dd></div>
          <div><dt>Aangemaakt</dt><dd>{formatDateTime(entry.created_at)}</dd></div>
          <div><dt>Laatst gebruikt</dt><dd>{formatDateTime(entry.last_used_at ?? null)}</dd></div>
        </dl>

        {audioSource ? (
          <SpeechCacheAudioPlayer source={audioSource} text={displayText} />
        ) : (
          <p className={styles.cacheAudioUnavailable}>
            {entry.status === 'ready'
              ? 'Voor dit item is geen afspeelbaar audiobestand beschikbaar.'
              : 'Audio wordt beschikbaar zodra dit item gereed is.'}
          </p>
        )}
      </article>
    </li>
  );
}

function SpeechCacheAudioPlayer({ source, text }: { source: string; text: string }) {
  const [loadFailed, setLoadFailed] = useState(false);

  return (
    <div className={styles.cacheAudio}>
      <audio
        controls
        preload="none"
        src={source}
        aria-label={`Cachefragment afspelen: ${text}`}
        onCanPlay={() => setLoadFailed(false)}
        onError={() => setLoadFailed(true)}
      >
        Uw browser ondersteunt geen audio-afspelen.
      </audio>
      {loadFailed ? (
        <p role="alert">
          <AlertTriangle aria-hidden size={16} />
          Dit fragment kon niet worden afgespeeld. Vernieuw de lijst en probeer opnieuw.
        </p>
      ) : null}
    </div>
  );
}

function speechCachePagination(meta: unknown): PaginationMeta {
  if (meta === null || typeof meta !== 'object') return EMPTY_SPEECH_CACHE_PAGINATION;
  const candidate = meta as Partial<PaginationMeta>;
  const currentPage = Number(candidate.current_page);
  const lastPage = Number(candidate.last_page);
  const perPage = Number(candidate.per_page);
  const total = Number(candidate.total);
  if (!Number.isInteger(currentPage) || currentPage < 1
    || !Number.isInteger(lastPage) || lastPage < 1
    || !Number.isInteger(perPage) || perPage < 1
    || !Number.isInteger(total) || total < 0) {
    return EMPTY_SPEECH_CACHE_PAGINATION;
  }

  return {
    current_page: Math.min(currentPage, lastPage),
    last_page: lastPage,
    per_page: perPage,
    total,
  };
}

function speechCacheCategoryLabel(category: SpeechCacheEntryCategory): string {
  return category === 'composite' ? 'Samengestelde melding' : 'Tekstfragment';
}

function SettingsSaveFooter({
  dirty,
  saving,
  error,
  message,
  blockedReason,
  canManage,
  onSave,
}: {
  dirty: boolean;
  saving: boolean;
  error: string | null;
  message: string | null;
  blockedReason: string | null;
  canManage: boolean;
  onSave: () => void;
}) {
  return (
    <div className={styles.saveFooter}>
      <div>
        {blockedReason ? (
          <p className={styles.saveBlocked} role="status">
            <AlertTriangle aria-hidden size={17} />
            {blockedReason}
          </p>
        ) : error ? <p className="form-error" role="alert">{error}</p> : null}
        {!blockedReason && message && !dirty ? <p className="success-text" role="status">{message}</p> : null}
        {!blockedReason && !error && (dirty || !message) ? <span>{dirty ? 'Er zijn niet-opgeslagen wijzigingen.' : 'Alle instellingen zijn opgeslagen.'}</span> : null}
      </div>
      <button
        className="primary-button"
        type="button"
        disabled={!canManage || saving || !dirty || blockedReason !== null}
        onClick={onSave}
      >
        {saving ? 'Opslaan...' : 'Spraakinstellingen opslaan'}
      </button>
    </div>
  );
}

function ProgressBlock({ label, value }: { label: string; value: number }) {
  const normalized = normalizeSpeechProgress(value);
  return (
    <div className={styles.progressBlock} role="status" aria-live="polite">
      <div><span>{label}</span><strong>{normalized}%</strong></div>
      <progress max={100} value={normalized} aria-label={`${label}: ${normalized} procent`} />
    </div>
  );
}

function speechSettingsToDraft(settings: SpeechSettings): SpeechSettingsDraft {
  return {
    ...settings,
    templates: {
      availability: settings.templates.availability.join('\n'),
      attendance: settings.templates.attendance.join('\n'),
      test_ack: settings.templates.test_ack.join('\n'),
    },
  };
}

function speechDraftToSettings(draft: SpeechSettingsDraft): SpeechSettings {
  return {
    enabled: draft.enabled,
    model_id: draft.model_id,
    voice_profile_id: draft.voice_profile_id,
    speed: draft.speed,
    pre_generate_on_save: draft.pre_generate_on_save,
    templates: {
      availability: semanticSpeechLines(draft.templates.availability),
      attendance: semanticSpeechLines(draft.templates.attendance),
      test_ack: semanticSpeechLines(draft.templates.test_ack),
    },
  };
}

function speechSettingsEqual(left: SpeechSettings, right: SpeechSettings): boolean {
  return JSON.stringify(left) === JSON.stringify(right);
}

function speechModelCapabilityLabels(capabilities: SpeechModel['capabilities']): string[] {
  return [
    capabilities.voice_design ? 'Vaste stemontwerpstem' : null,
    capabilities.voice_clone ? 'Eigen stemprofiel' : null,
    capabilities.speed_control ? 'Modeltempo' : 'Tempo via serveraudio',
  ].filter((label): label is string => label !== null);
}

function hasActiveSpeechWork(data: SpeechAdminStatus): boolean {
  return data.models.some((model) => speechWorkIsActive(model.status))
    || data.voice_profiles.some((profile) => speechWorkIsActive(profile.status))
    || speechWorkIsActive(data.cache.active_job?.status);
}

function cacheScopeLabel(scope: SpeechCacheRegenerationScope): string {
  const labels: Record<SpeechCacheRegenerationScope, string> = {
    all: 'alles',
    segments: 'segmenten',
    composites: 'samengestelde meldingen',
    failed: 'mislukte items',
  };
  return labels[scope];
}

function apiErrorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiClientError ? error.message : fallback;
}

function preferredRecordingMimeType(): string | null {
  const types = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4'];
  return types.find((type) => MediaRecorder.isTypeSupported(type)) ?? null;
}

function recordingExtension(mimeType: string): string {
  if (mimeType.includes('ogg')) return 'ogg';
  if (mimeType.includes('mp4')) return 'm4a';
  return 'webm';
}

function stopMediaStream(stream: MediaStream | null) {
  stream?.getTracks().forEach((track) => track.stop());
}
