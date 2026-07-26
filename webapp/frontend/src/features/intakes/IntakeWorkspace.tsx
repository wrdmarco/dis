'use client';

import {
  AlertTriangle,
  ArrowRight,
  Check,
  CheckCircle2,
  ChevronRight,
  ClipboardCheck,
  CloudOff,
  FileCheck2,
  Info,
  Loader2,
  RefreshCcw,
  Save,
  Search,
  ShieldAlert,
  XCircle,
} from 'lucide-react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type FocusEvent,
  type ReactNode,
} from 'react';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import {
  browserNavigationController,
  type InterceptableNavigationEvent,
} from '../../lib/browserNavigation';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type { Certification, Team } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import {
  bindingLabel,
  conflictDossier,
  intakeBooleanChoice,
  intakeBranchFields,
  intakeCommonFields,
  intakeCompleteness,
  intakeDecisionProfileId,
  intakeDossierStatusLabel,
  intakeDossierStatusTone,
  intakeFieldIsAnswered,
  intakeHasChanges,
  intakePriorityLabel,
  intakePriorityOptions,
  intakePriorityTone,
  intakeSaveLabel,
  makeIntakeMutationId,
  mergeIntakeChanges,
  mergeQueuedIntakeChanges,
  type IntakeChanges,
  type IntakeDeploymentProposal,
  type IntakeDossier,
  type IntakeFieldOption,
  type IntakePriority,
  type IntakePromotion,
  type IntakeSaveState,
  type IntakeSubjectType,
  type IntakeWorkflowBinding,
  type IntakeWorkflowField,
  type IntakeWorkflowRevision,
} from './intakeWorkflow';

interface IntakeWorkspaceProps {
  dossier: IntakeDossier;
  workflow: IntakeWorkflowRevision | null;
  canManage: boolean;
  saveEndpoint?: string;
  compact?: boolean;
  allowPromotedEditing?: boolean;
  onDossierChange: (dossier: IntakeDossier) => void;
  onRefresh: () => Promise<void>;
}

interface QueuedMutation {
  changes: IntakeChanges;
  clientMutationId: string;
  lockVersion: number;
}

interface ConflictState {
  current: IntakeDossier;
  attempted: IntakeChanges;
}

const EMPTY_CHANGES: IntakeChanges = {};
const AUTOSAVE_DELAY_MS = 850;

export function IntakeWorkspace(props: IntakeWorkspaceProps) {
  const {
    dossier,
    workflow,
    canManage,
    saveEndpoint = `/intake-dossiers/${dossier.id}`,
    compact = false,
    allowPromotedEditing = false,
    onDossierChange,
    onRefresh,
  } = props;
  const router = useRouter();
  const { api, hasPermission } = useAuth();
  const canOverride = hasPermission('intakes.priority.override');
  const teams = useApiResource<Team[]>(
    '/teams',
    canManage && canOverride
      && (dossier.status === 'open' || (allowPromotedEditing && dossier.status === 'promoted')),
  );
  const certifications = useApiResource<Certification[]>(
    '/certifications/options',
    canManage && canOverride
      && (dossier.status === 'open' || (allowPromotedEditing && dossier.status === 'promoted')),
  );
  const [draft, setDraft] = useState(dossier);
  const [saveState, setSaveState] = useState<IntakeSaveState>('saved');
  const [saveError, setSaveError] = useState<string | null>(null);
  const [conflict, setConflict] = useState<ConflictState | null>(null);
  const [decisionPriority, setDecisionPriority] = useState<IntakePriority | null>(dossier.decided_priority);
  const [decisionReason, setDecisionReason] = useState(dossier.priority_override_reason ?? '');
  const [deploymentDraft, setDeploymentDraft] = useState(() => deploymentFormFromDossier(dossier));
  const [decisionSaving, setDecisionSaving] = useState(false);
  const [decisionError, setDecisionError] = useState<string | null>(null);
  const [promoting, setPromoting] = useState(false);
  const [promoteError, setPromoteError] = useState<string | null>(null);
  const [closing, setClosing] = useState(false);
  const [closeConfirmOpen, setCloseConfirmOpen] = useState(false);
  const [closeReason, setCloseReason] = useState('');
  const [closeError, setCloseError] = useState<string | null>(null);
  const pendingRef = useRef<IntakeChanges>(EMPTY_CHANGES);
  const retryMutationRef = useRef<QueuedMutation | null>(null);
  const inFlightRef = useRef<Promise<boolean> | null>(null);
  const versionRef = useRef(dossier.lock_version);
  const timerRef = useRef<number | null>(null);
  const flushRef = useRef<() => Promise<boolean>>(async () => true);
  const mountedRef = useRef(true);
  const navigationPendingRef = useRef(false);
  const promoteMutationIdRef = useRef<string | null>(null);
  const decisionMutationRef = useRef<{
    signature: string;
    payload: Record<string, unknown>;
  } | null>(null);
  const closeMutationRef = useRef<{
    signature: string;
    payload: Record<string, unknown>;
  } | null>(null);
  const latestDossierRef = useRef(dossier);

  const configuration = workflow?.configuration ?? null;
  const bindingByField = useMemo(
    () => new Map((configuration?.bindings ?? []).map((binding) => [binding.field_key, binding])),
    [configuration?.bindings],
  );
  const completeness = configuration ? intakeCompleteness(draft, configuration) : null;
  const recommendedPlan = draft.deployment_proposal;
  const selectedPlan = draft.selected_deployment_proposal;
  const planChanged = deploymentDiffers(deploymentDraft, recommendedPlan);
  const priorityChangedFromAdvice = decisionPriority !== null
    && decisionPriority !== draft.triage.recommended_priority;
  const overrideRequired = priorityChangedFromAdvice || planChanged;
  const assessmentBlocked = draft.triage.state === 'incomplete';
  const statusEditable = draft.status === 'open' || (allowPromotedEditing && draft.status === 'promoted');
  const editable = canManage && statusEditable && conflict === null && configuration !== null;

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      if (timerRef.current !== null) window.clearTimeout(timerRef.current);
      if (
        intakeHasChanges(pendingRef.current)
        || retryMutationRef.current !== null
        || inFlightRef.current !== null
      ) {
        void flushRef.current();
      }
    };
  }, []);

  useEffect(() => {
    const saveBeforeInternalNavigation = (event: MouseEvent) => {
      if (
        event.defaultPrevented
        || event.button !== 0
        || event.metaKey
        || event.ctrlKey
        || event.shiftKey
        || event.altKey
      ) {
        return;
      }

      const element = event.target instanceof Element ? event.target : null;
      const anchor = element?.closest<HTMLAnchorElement>('a[href]');
      if (
        anchor === null
        || anchor === undefined
        || anchor.target === '_blank'
        || anchor.hasAttribute('download')
        || !intakeHasUnsavedWork()
      ) {
        return;
      }

      const destination = new URL(anchor.href, window.location.href);
      if (
        destination.origin !== window.location.origin
        || (
          destination.pathname === window.location.pathname
          && destination.search === window.location.search
        )
      ) {
        return;
      }

      event.preventDefault();
      if (navigationPendingRef.current) return;
      navigationPendingRef.current = true;
      void flushRef.current().then((saved) => {
        if (!saved || !mountedRef.current) {
          navigationPendingRef.current = false;
          return;
        }
        router.push(`${destination.pathname}${destination.search}${destination.hash}`);
      });
    };

    document.addEventListener('click', saveBeforeInternalNavigation, true);
    return () => document.removeEventListener('click', saveBeforeInternalNavigation, true);
  }, [router]);

  useEffect(() => {
    const warnBeforeUnload = (event: BeforeUnloadEvent) => {
      if (!intakeHasUnsavedWork()) return;

      event.preventDefault();
      event.returnValue = '';
    };

    window.addEventListener('beforeunload', warnBeforeUnload);
    return () => window.removeEventListener('beforeunload', warnBeforeUnload);
  }, []);

  useEffect(() => {
    const saveBeforeHistoryNavigation = (event: InterceptableNavigationEvent) => {
      if (
        !intakeHasUnsavedWork()
        || navigationPendingRef.current
        || !event.canIntercept
        || !event.cancelable
        || event.downloadRequest !== null
        || event.formData !== null
        || event.hashChange
      ) {
        return;
      }

      const destination = new URL(event.destination.url, window.location.href);
      if (destination.origin !== window.location.origin) return;

      event.intercept({
        precommitHandler: async () => {
          navigationPendingRef.current = true;
          const saved = await flushRef.current();
          navigationPendingRef.current = false;
          if (!saved) {
            throw new DOMException('Navigatie geannuleerd omdat de melding niet kon worden opgeslagen.', 'AbortError');
          }
        },
      });
    };
    const navigation = browserNavigationController();
    navigation?.addEventListener('navigate', saveBeforeHistoryNavigation);
    return () => navigation?.removeEventListener('navigate', saveBeforeHistoryNavigation);
  }, []);

  useEffect(() => {
    if (dossier.lock_version >= latestDossierRef.current.lock_version) {
      latestDossierRef.current = dossier;
    }
    if (
      dossier.lock_version >= versionRef.current
      && !intakeHasChanges(pendingRef.current)
      && retryMutationRef.current === null
      && inFlightRef.current === null
      && conflict === null
    ) {
      versionRef.current = dossier.lock_version;
      setDraft(dossier);
      setDecisionPriority(dossier.decided_priority);
      setDecisionReason(dossier.priority_override_reason ?? '');
      setDeploymentDraft(deploymentFormFromDossier(dossier));
      setSaveState('saved');
      setSaveError(null);
    }
  }, [conflict, dossier]);

  const scheduleSave = useCallback(() => {
    if (timerRef.current !== null) window.clearTimeout(timerRef.current);
    timerRef.current = window.setTimeout(() => {
      timerRef.current = null;
      void flushRef.current();
    }, AUTOSAVE_DELAY_MS);
  }, []);

  const flushSave = useCallback(async (): Promise<boolean> => {
    if (!canManage || !statusEditable) return true;
    if (conflict !== null) return false;
    if (timerRef.current !== null) {
      window.clearTimeout(timerRef.current);
      timerRef.current = null;
    }

    const active = inFlightRef.current;
    if (active !== null) {
      const succeeded = await active;
      return succeeded ? flushRef.current() : false;
    }

    const retry = retryMutationRef.current;
    const changes = retry?.changes ?? pendingRef.current;
    if (!intakeHasChanges(changes)) {
      if (mountedRef.current) setSaveState('saved');
      return true;
    }

    const mutation: QueuedMutation = retry ?? {
      changes,
      clientMutationId: makeIntakeMutationId(),
      lockVersion: versionRef.current,
    };
    if (retry === null) pendingRef.current = EMPTY_CHANGES;
    if (mountedRef.current) {
      setSaveState('saving');
      setSaveError(null);
    }

    const request = api.patch<IntakeDossier>(saveEndpoint, {
      lock_version: mutation.lockVersion,
      client_mutation_id: mutation.clientMutationId,
      changes: mutation.changes,
    })
      .then((response) => {
        retryMutationRef.current = null;
        versionRef.current = response.data.lock_version;
        latestDossierRef.current = response.data;
        const hasPendingChanges = intakeHasChanges(pendingRef.current);
        if (mountedRef.current) {
          onDossierChange(response.data);
          setDraft(mergeIntakeChanges(response.data, pendingRef.current));
          if (!hasPendingChanges) {
            setDecisionPriority(response.data.decided_priority);
            setDecisionReason(response.data.priority_override_reason ?? '');
            setDeploymentDraft(deploymentFormFromDossier(response.data));
          }
          setSaveState(hasPendingChanges ? 'dirty' : 'saved');
          setSaveError(null);
        }
        return true;
      })
      .catch((caught: unknown) => {
        if (
          caught instanceof ApiClientError
          && caught.status === 409
          && ['intake_version_conflict', 'intake_mutation_conflict'].includes(caught.code)
        ) {
          const current = conflictDossier(caught.details);
          if (current !== null) {
            const attempted = mergeQueuedIntakeChanges(mutation.changes, pendingRef.current);
            pendingRef.current = EMPTY_CHANGES;
            retryMutationRef.current = null;
            if (mountedRef.current) {
              setConflict({ current, attempted });
              setSaveState('conflict');
              setSaveError(caught.message);
            }
            return false;
          }
        }

        retryMutationRef.current = mutation;
        if (mountedRef.current) {
          setSaveState(isOfflineError(caught) ? 'offline' : 'error');
          setSaveError(caught instanceof ApiClientError ? caught.message : 'De wijzigingen konden niet worden opgeslagen.');
        }
        return false;
      })
      .finally(() => {
        inFlightRef.current = null;
      });

    inFlightRef.current = request;
    const succeeded = await request;
    if (succeeded && intakeHasChanges(pendingRef.current)) {
      return flushRef.current();
    }

    return succeeded;
  }, [api, canManage, conflict, onDossierChange, saveEndpoint, statusEditable]);

  function intakeHasUnsavedWork(): boolean {
    return intakeHasChanges(pendingRef.current)
      || retryMutationRef.current !== null
      || inFlightRef.current !== null;
  }

  useEffect(() => {
    flushRef.current = flushSave;
  }, [flushSave]);

  useEffect(() => {
    const retryWhenOnline = () => {
      if (retryMutationRef.current !== null || intakeHasChanges(pendingRef.current)) {
        void flushRef.current();
      }
    };
    window.addEventListener('online', retryWhenOnline);
    return () => window.removeEventListener('online', retryWhenOnline);
  }, []);

  const queueChanges = useCallback((changes: IntakeChanges) => {
    setDraft((current) => mergeIntakeChanges(current, changes));
    pendingRef.current = mergeQueuedIntakeChanges(pendingRef.current, changes);
    setSaveState('dirty');
    setSaveError(null);
    scheduleSave();
  }, [scheduleSave]);

  const loadServerConflict = () => {
    if (conflict === null) return;
    pendingRef.current = EMPTY_CHANGES;
    retryMutationRef.current = null;
    versionRef.current = conflict.current.lock_version;
    latestDossierRef.current = conflict.current;
    onDossierChange(conflict.current);
    setDraft(conflict.current);
    setDecisionPriority(conflict.current.decided_priority);
    setDecisionReason(conflict.current.priority_override_reason ?? '');
    setDeploymentDraft(deploymentFormFromDossier(conflict.current));
    setConflict(null);
    setSaveState('saved');
    setSaveError(null);
  };

  const retryConflictOnCurrent = () => {
    if (conflict === null) return;
    versionRef.current = conflict.current.lock_version;
    latestDossierRef.current = conflict.current;
    onDossierChange(conflict.current);
    setDraft(mergeIntakeChanges(conflict.current, conflict.attempted));
    pendingRef.current = conflict.attempted;
    retryMutationRef.current = null;
    setConflict(null);
    setSaveState('dirty');
    setSaveError(null);
    window.setTimeout(() => void flushRef.current(), 0);
  };

  const changeSubject = (subjectType: IntakeSubjectType) => {
    if (!configuration || subjectType === draft.subject_type) return;
    const oldBranchHasAnswers = intakeBranchFields(configuration, draft.subject_type)
      .some((field) => intakeFieldIsAnswered(field, draft.answers[field.key]));
    if (
      oldBranchHasAnswers
      && !window.confirm('Van type wisselen? De eerdere typespecifieke antwoorden blijven in de dossiergeschiedenis bewaard.')
    ) {
      return;
    }

    queueChanges({ subject_type: subjectType });
    const subjectLabel = configuration.subject_types.find((subject) => subject.key === subjectType)?.label;
    if (subjectLabel) {
      setDraft((current) => ({ ...current, subject_type_label: subjectLabel }));
    }
  };

  const saveDecision = async () => {
    setDecisionError(null);
    if (assessmentBlocked) {
      setDecisionError('Vul eerst de ontbrekende kerngegevens in.');
      return;
    }
    if (decisionPriority === null) {
      setDecisionError('Kies eerst de vastgestelde prioriteit.');
      return;
    }
    if (overrideRequired && decisionReason.trim() === '') {
      setDecisionError('Leg uit waarom je afwijkt van het advies of inzetvoorstel.');
      return;
    }
    if (overrideRequired && !canOverride) {
      setDecisionError('Je hebt geen toestemming om van het advies of inzetvoorstel af te wijken.');
      return;
    }
    const hadUnsavedAnswers = intakeHasChanges(pendingRef.current)
      || retryMutationRef.current !== null
      || inFlightRef.current !== null;
    if (!await flushSave()) return;
    if (hadUnsavedAnswers) {
      setDecisionError('De uitvraag is opgeslagen en het advies is opnieuw berekend. Controleer het actuele advies en leg daarna de beoordeling vast.');
      return;
    }

    setDecisionSaving(true);
    try {
      const selectedProfileId = intakeDecisionProfileId(
        decisionPriority,
        draft.decided_priority,
        draft.triage.recommended_priority,
        selectedPlan?.profile_id,
        recommendedPlan?.profile_id,
      );
      const desiredDecision = {
        priority: decisionPriority,
        selected_deployment_profile_id: selectedProfileId,
        ...(planChanged ? {
          deployment_adjustments: {
            team_ids: deploymentDraft.teamIds,
            resources: deploymentDraft.resources,
            recommended_recipient_count: deploymentDraft.recipientCount,
            recommended_dispatch_mode: deploymentDraft.dispatchMode,
            required_certification_type_ids: deploymentDraft.certificationTypeIds,
            notes: deploymentDraft.notes.trim() === '' ? null : deploymentDraft.notes,
          },
        } : {}),
        reason: overrideRequired ? decisionReason.trim() : undefined,
      };
      const decisionSignature = JSON.stringify(desiredDecision);
      if (decisionMutationRef.current?.signature !== decisionSignature) {
        decisionMutationRef.current = {
          signature: decisionSignature,
          payload: {
            lock_version: versionRef.current,
            client_mutation_id: makeIntakeMutationId(),
            ...desiredDecision,
          },
        };
      }
      const response = await api.patch<IntakeDossier>(
        `/intake-dossiers/${draft.id}/priority`,
        decisionMutationRef.current.payload,
      );
      decisionMutationRef.current = null;
      adoptActionResponse(response.data);
      setDecisionError(null);
    } catch (caught) {
      if (adoptActionConflict(caught)) {
        decisionMutationRef.current = null;
        return;
      }
      setDecisionError(caught instanceof ApiClientError ? caught.message : 'De beoordeling kon niet worden opgeslagen.');
    } finally {
      setDecisionSaving(false);
    }
  };

  const promote = async () => {
    setPromoteError(null);
    if (assessmentBlocked) {
      setPromoteError('De uitvraag is nog onvolledig. Vul eerst de ontbrekende kerngegevens in.');
      return;
    }
    if (!await flushSave()) return;
    const currentDossier = latestDossierRef.current;
    if (currentDossier.triage.state === 'incomplete') {
      setPromoteError('De uitvraag is nog onvolledig. Vul eerst de ontbrekende kerngegevens in.');
      return;
    }
    if (currentDossier.decided_priority === null) {
      setPromoteError('Stel eerst de prioriteit en het inzetvoorstel vast.');
      return;
    }
    if (!window.confirm('Conceptincident aanmaken vanuit deze melding? Er wordt nog geen alarm verstuurd.')) return;

    setPromoting(true);
    const promotionMutationId = promoteMutationIdRef.current ?? makeIntakeMutationId();
    promoteMutationIdRef.current = promotionMutationId;
    try {
      const response = await api.post<IntakePromotion>(`/intake-dossiers/${draft.id}/promote`, {
        lock_version: currentDossier.lock_version,
        client_mutation_id: promotionMutationId,
      });
      promoteMutationIdRef.current = null;
      adoptActionResponse(response.data.dossier);
      router.push(`/incidents/${response.data.incident.id}`);
    } catch (caught) {
      if (adoptActionConflict(caught)) {
        promoteMutationIdRef.current = null;
        return;
      }
      setPromoteError(caught instanceof ApiClientError ? caught.message : 'Het incident kon niet worden aangemaakt.');
    } finally {
      setPromoting(false);
    }
  };

  const closeWithoutIncident = async () => {
    if (!await flushSave()) return;
    setClosing(true);
    setCloseError(null);
    try {
      const desiredClose = {
        reason: closeReason.trim() === '' ? undefined : closeReason.trim(),
      };
      const closeSignature = JSON.stringify(desiredClose);
      if (closeMutationRef.current?.signature !== closeSignature) {
        closeMutationRef.current = {
          signature: closeSignature,
          payload: {
            lock_version: versionRef.current,
            client_mutation_id: makeIntakeMutationId(),
            ...desiredClose,
          },
        };
      }
      const response = await api.post<IntakeDossier>(
        `/intake-dossiers/${draft.id}/close`,
        closeMutationRef.current.payload,
      );
      closeMutationRef.current = null;
      adoptActionResponse(response.data);
      setCloseConfirmOpen(false);
    } catch (caught) {
      if (adoptActionConflict(caught)) {
        closeMutationRef.current = null;
        return;
      }
      setCloseError(caught instanceof ApiClientError ? caught.message : 'De melding kon niet worden afgesloten.');
    } finally {
      setClosing(false);
    }
  };

  const adoptActionResponse = (next: IntakeDossier) => {
    versionRef.current = next.lock_version;
    latestDossierRef.current = next;
    pendingRef.current = EMPTY_CHANGES;
    retryMutationRef.current = null;
    setDraft(next);
    onDossierChange(next);
    setDecisionPriority(next.decided_priority);
    setDecisionReason(next.priority_override_reason ?? '');
    setDeploymentDraft(deploymentFormFromDossier(next));
    setSaveState('saved');
  };

  function adoptActionConflict(caught: unknown): boolean {
    if (!(caught instanceof ApiClientError) || caught.status !== 409) return false;
    const current = conflictDossier(caught.details);
    if (current === null) return false;
    setConflict({ current, attempted: EMPTY_CHANGES });
    setSaveState('conflict');
    setSaveError(caught.message);
    return true;
  }

  const handleBlur = (event: FocusEvent<HTMLDivElement>) => {
    const nextTarget = event.relatedTarget;
    if (nextTarget instanceof Node && event.currentTarget.contains(nextTarget)) return;
    void flushSave();
  };

  if (configuration === null) {
    return (
      <ReadonlyIntake
        dossier={draft}
        canRefresh
        onRefresh={onRefresh}
      />
    );
  }

  return (
    <div className={`intake-workspace${compact ? ' intake-workspace--compact' : ''}`}>
      <header className="intake-workspace__topline">
        <div>
          <span className="intake-workspace__eyebrow">Meldingsdossier</span>
          <strong>{draft.subject_type_label}</strong>
          <span>Versie {draft.workflow_revision.version} · bijgewerkt {formatDateTime(draft.updated_at)}</span>
        </div>
        <div className="intake-workspace__top-status">
          <StatusPill
            value={intakeDossierStatusLabel(draft.status)}
            tone={intakeDossierStatusTone(draft.status)}
          />
          <SaveIndicator state={saveState} />
        </div>
      </header>

      {conflict ? (
        <section className="intake-conflict" role="alert">
          <ShieldAlert size={22} aria-hidden />
          <div>
            <strong>Deze melding is intussen door iemand anders bijgewerkt.</strong>
            <p>{saveError ?? 'Kies welke versie je wilt gebruiken voordat je verdergaat.'}</p>
            <div className="actions-row">
              <button className="secondary-button" type="button" onClick={loadServerConflict}>
                <RefreshCcw size={16} /> Serverversie laden
              </button>
              <button className="primary-button" type="button" onClick={retryConflictOnCurrent}>
                <Save size={16} /> Mijn wijzigingen opnieuw toepassen
              </button>
            </div>
          </div>
        </section>
      ) : null}

      {saveState === 'offline' || saveState === 'error' ? (
        <div className="intake-save-warning" role="alert">
          {saveState === 'offline' ? <CloudOff size={18} aria-hidden /> : <AlertTriangle size={18} aria-hidden />}
          <span>{saveError ?? intakeSaveLabel(saveState)}</span>
          <button className="secondary-button" type="button" onClick={() => void flushSave()}>
            Opnieuw opslaan
          </button>
        </div>
      ) : null}

      <div className="intake-workspace__columns">
        <div className="intake-questionnaire" onBlur={handleBlur}>
          <section className="intake-questionnaire__section" aria-labelledby="intake-subject-heading">
            <header>
              <span>01</span>
              <div>
                <h3 id="intake-subject-heading">Wie of wat zoeken we?</h3>
                <p>De uitvraag past zich aan het gekozen onderwerp aan.</p>
              </div>
            </header>
            <div className="intake-subject-selector" role="radiogroup" aria-label="Type zoekonderwerp">
              {configuration.subject_types.map((subject) => (
                <label
                  className={`intake-subject-card${draft.subject_type === subject.key ? ' intake-subject-card--selected' : ''}`}
                  key={subject.key}
                >
                  <input
                    type="radio"
                    name={`intake-subject-${draft.id}`}
                    checked={draft.subject_type === subject.key}
                    disabled={!editable}
                    onChange={() => changeSubject(subject.key)}
                  />
                  <Search size={20} aria-hidden />
                  <span>{subject.label}</span>
                  {draft.subject_type === subject.key ? <Check size={16} aria-hidden /> : null}
                </label>
              ))}
            </div>
          </section>

          <QuestionSection
            number="02"
            title="Gegevens van de melding"
            description="Deze gegevens worden één keer vastgelegd en waar ingesteld overgenomen in het incident."
            fields={intakeCommonFields(configuration)}
            answers={draft.answers}
            bindings={bindingByField}
            disabled={!editable}
            onChange={(key, value) => queueChanges({ answers: { [key]: value } })}
          />

          <QuestionSection
            number="03"
            title={`Uitvraag ${draft.subject_type_label.toLowerCase()}`}
            description="Vul aan wat nu bekend is. Je kunt dit dossier later blijven bijwerken."
            fields={intakeBranchFields(configuration, draft.subject_type)}
            answers={draft.answers}
            bindings={bindingByField}
            disabled={!editable}
            onChange={(key, value) => queueChanges({ answers: { [key]: value } })}
          />
        </div>

        <aside className="intake-assessment" aria-label="Beoordeling melding">
          <AssessmentCard dossier={draft} completeness={completeness} />

          {statusEditable && canManage ? (
            <>
              <DecisionCard
                dossier={draft}
                priority={decisionPriority}
                reason={decisionReason}
                canOverride={canOverride}
                overrideRequired={overrideRequired}
                blocked={assessmentBlocked}
                saving={decisionSaving}
                error={decisionError}
                onPriorityChange={setDecisionPriority}
                onReasonChange={setDecisionReason}
                onSave={() => void saveDecision()}
              />
              <DeploymentCard
                proposal={recommendedPlan}
                selected={selectedPlan}
                teams={teams.data ?? []}
                certifications={certifications.data ?? []}
                canOverride={canOverride}
                draft={deploymentDraft}
                onChange={setDeploymentDraft}
              />
            </>
          ) : (
            <DeploymentSnapshotCard proposal={selectedPlan ?? recommendedPlan} />
          )}

          {draft.status === 'open' && canManage ? (
            <>
              <section className="intake-actions-card">
                <div className="intake-actions-card__notice">
                  <Info size={18} aria-hidden />
                  <span>Incident aanmaken maakt alleen een concept. Er wordt geen alarm verstuurd.</span>
                </div>
                <button
                  className="primary-button intake-promote-button"
                  type="button"
                  disabled={promoting || decisionSaving || assessmentBlocked || draft.decided_priority === null}
                  onClick={() => void promote()}
                >
                  {promoting ? <Loader2 className="spin" size={17} /> : <FileCheck2 size={17} />}
                  Incident aanmaken
                </button>
                {assessmentBlocked ? (
                  <p className="intake-blocked-notice">
                    <AlertTriangle size={15} /> Incident aanmaken wordt beschikbaar zodra de kerngegevens compleet zijn.
                  </p>
                ) : null}
                {promoteError ? <p className="form-error" role="alert">{promoteError}</p> : null}
                <button
                  className="intake-close-trigger"
                  type="button"
                  onClick={() => setCloseConfirmOpen((current) => !current)}
                >
                  <XCircle size={16} /> Afsluiten zonder incident
                </button>
                {closeConfirmOpen ? (
                  <div className="intake-close-confirm">
                    <label>
                      Reden (optioneel)
                      <textarea value={closeReason} maxLength={1000} onChange={(event) => setCloseReason(event.target.value)} />
                    </label>
                    <div className="actions-row">
                      <button className="secondary-button" type="button" onClick={() => setCloseConfirmOpen(false)}>
                        Annuleren
                      </button>
                      <button className="danger-button" type="button" disabled={closing} onClick={() => void closeWithoutIncident()}>
                        {closing ? <Loader2 className="spin" size={16} /> : null}
                        Melding afsluiten
                      </button>
                    </div>
                    {closeError ? <p className="form-error" role="alert">{closeError}</p> : null}
                  </div>
                ) : null}
              </section>
            </>
          ) : null}

          {draft.incident_id && !compact ? (
            <Link className="primary-button intake-incident-link" href={`/incidents/${draft.incident_id}`}>
              Naar gekoppeld incident <ArrowRight size={17} />
            </Link>
          ) : null}
        </aside>
      </div>
    </div>
  );
}

function QuestionSection(props: {
  number: string;
  title: string;
  description: string;
  fields: IntakeWorkflowField[];
  answers: Record<string, unknown>;
  bindings: Map<string, IntakeWorkflowBinding>;
  disabled: boolean;
  onChange: (key: string, value: unknown | null) => void;
}) {
  const { number, title, description, fields, answers, bindings, disabled, onChange } = props;

  return (
    <section className="intake-questionnaire__section">
      <header>
        <span>{number}</span>
        <div>
          <h3>{title}</h3>
          <p>{description}</p>
        </div>
      </header>
      <div className="intake-field-grid">
        {fields.map((field) => (
          field.type === 'section' ? (
            <div className="intake-field-section" key={field.key}>
              <h4>{field.label}</h4>
              {field.help_text ? <p>{field.help_text}</p> : null}
            </div>
          ) : (
            <IntakeField
              field={field}
              value={answers[field.key]}
              binding={bindings.get(field.key)}
              disabled={disabled}
              key={field.key}
              onChange={(value) => onChange(field.key, value)}
            />
          )
        ))}
      </div>
    </section>
  );
}

function IntakeField(props: {
  field: IntakeWorkflowField;
  value: unknown;
  binding?: IntakeWorkflowBinding;
  disabled: boolean;
  onChange: (value: unknown | null) => void;
}) {
  const { field, value, binding, disabled, onChange } = props;
  const fieldId = `intake-field-${field.key}`;
  const label = (
    <span className="intake-field__label">
      <span>{field.label}{field.required ? ' *' : ''}</span>
      {binding ? <small><ChevronRight size={13} /> Naar {bindingLabel(binding.target)}</small> : null}
    </span>
  );
  const className = `intake-field${field.type === 'textarea' || field.type === 'radio' ? ' intake-field--wide' : ''}`;

  if (field.type === 'textarea') {
    return (
      <label className={className} htmlFor={fieldId}>
        {label}
        <textarea
          id={fieldId}
          value={asInputString(value)}
          disabled={disabled}
          required={field.required}
          aria-describedby={field.help_text ? `${fieldId}-help` : undefined}
          onChange={(event) => onChange(event.target.value === '' ? null : event.target.value)}
        />
        {field.help_text ? <small id={`${fieldId}-help`}>{field.help_text}</small> : null}
      </label>
    );
  }

  if (field.type === 'select') {
    return (
      <label className={className} htmlFor={fieldId}>
        {label}
        <select
          id={fieldId}
          value={asInputString(value)}
          disabled={disabled}
          required={field.required}
          onChange={(event) => onChange(event.target.value === '' ? null : event.target.value)}
        >
          <option value="">Kies…</option>
          {(field.options ?? []).map((option) => <option value={option.value} key={option.value}>{option.label}</option>)}
        </select>
        {field.help_text ? <small>{field.help_text}</small> : null}
      </label>
    );
  }

  if (field.type === 'radio') {
    return (
      <fieldset className={`${className} intake-radio-field`} id={fieldId}>
        <legend>{label}</legend>
        <div>
          {(field.options ?? []).map((option) => (
            <ChoiceOption
              name={fieldId}
              option={option}
              selected={value === option.value}
              disabled={disabled}
              key={option.value}
              onSelect={() => onChange(option.value)}
            />
          ))}
        </div>
        {field.help_text ? <small>{field.help_text}</small> : null}
      </fieldset>
    );
  }

  if (field.type === 'checkbox') {
    const selected = intakeBooleanChoice(value);
    return (
      <fieldset className={`${className} intake-radio-field intake-boolean-field`} id={fieldId}>
        <legend>{label}</legend>
        <div>
          {!field.required ? (
            <ChoiceOption
              name={fieldId}
              option={{ value: 'unanswered', label: 'Onbeantwoord' }}
              selected={selected === null}
              disabled={disabled}
              onSelect={() => onChange(null)}
            />
          ) : null}
          <ChoiceOption
            name={fieldId}
            option={{ value: 'yes', label: 'Ja' }}
            selected={selected === true}
            disabled={disabled}
            onSelect={() => onChange(true)}
          />
          <ChoiceOption
            name={fieldId}
            option={{ value: 'no', label: 'Nee' }}
            selected={selected === false}
            disabled={disabled}
            onSelect={() => onChange(false)}
          />
        </div>
        {field.help_text ? <small>{field.help_text}</small> : null}
      </fieldset>
    );
  }

  const inputType = field.type === 'number'
    ? 'number'
    : field.type === 'date'
      ? 'date'
      : field.type === 'datetime'
        ? 'datetime-local'
        : 'text';

  return (
    <label className={className} htmlFor={fieldId}>
      {label}
      <input
        id={fieldId}
        type={inputType}
        value={field.type === 'datetime' ? dateTimeLocalValue(value) : asInputString(value)}
        disabled={disabled}
        required={field.required}
        onChange={(event) => {
          if (event.target.value === '') {
            onChange(null);
          } else if (field.type === 'number') {
            onChange(Number(event.target.value));
          } else if (field.type === 'datetime') {
            onChange(new Date(event.target.value).toISOString());
          } else {
            onChange(event.target.value);
          }
        }}
      />
      {field.help_text ? <small>{field.help_text}</small> : null}
    </label>
  );
}

function ChoiceOption(props: {
  name: string;
  option: IntakeFieldOption;
  selected: boolean;
  disabled: boolean;
  onSelect: () => void;
}) {
  return (
    <label className={props.selected ? 'intake-choice intake-choice--selected' : 'intake-choice'}>
      <input
        type="radio"
        name={props.name}
        checked={props.selected}
        disabled={props.disabled}
        onChange={props.onSelect}
      />
      <span>{props.option.label}</span>
    </label>
  );
}

function AssessmentCard({ dossier, completeness }: { dossier: IntakeDossier; completeness: number | null }) {
  return (
    <section className="intake-assessment-card intake-assessment-card--primary">
      <header>
        <div>
          <span className="intake-workspace__eyebrow">Live beoordeling</span>
          <h3>Prioriteitsadvies</h3>
        </div>
        <StatusPill
          value={intakePriorityLabel(dossier.triage.recommended_priority)}
          tone={intakePriorityTone(dossier.triage.recommended_priority)}
        />
      </header>
      {completeness !== null ? (
        <div className="intake-completeness">
          <div><span>Volledigheid</span><strong>{completeness}%</strong></div>
          <progress max="100" value={completeness}>{completeness}%</progress>
        </div>
      ) : null}
      {dossier.triage.reasons.length > 0 ? (
        <ul className="intake-reason-list">
          {dossier.triage.reasons.map((reason) => <li key={reason}><CheckCircle2 size={15} /> {reason}</li>)}
        </ul>
      ) : (
        <p className="intake-assessment-card__empty">
          {dossier.triage.state === 'incomplete'
            ? 'Vul de ontbrekende kerngegevens in om een advies te berekenen.'
            : 'Er is nog geen verklaarbaar prioriteitsadvies.'}
        </p>
      )}
      {dossier.triage.missing_fields.length > 0 ? (
        <div className="intake-missing-fields">
          <strong>Nog nodig</strong>
          <div>
            {dossier.triage.missing_fields.map((field) => (
              <button
                type="button"
                key={field.key}
                onClick={() => focusIntakeField(field.key)}
              >
                {field.label}
              </button>
            ))}
          </div>
        </div>
      ) : null}
    </section>
  );
}

function focusIntakeField(fieldKey: string) {
  const target = document.getElementById(`intake-field-${fieldKey}`);
  if (target instanceof HTMLFieldSetElement) {
    target.querySelector<HTMLInputElement>('input:not(:disabled)')?.focus();
    return;
  }
  target?.focus();
}

function DecisionCard(props: {
  dossier: IntakeDossier;
  priority: IntakePriority | null;
  reason: string;
  canOverride: boolean;
  overrideRequired: boolean;
  blocked: boolean;
  saving: boolean;
  error: string | null;
  onPriorityChange: (priority: IntakePriority) => void;
  onReasonChange: (reason: string) => void;
  onSave: () => void;
}) {
  const advised = props.dossier.triage.recommended_priority;

  return (
    <section className="intake-assessment-card">
      <header>
        <div>
          <span className="intake-workspace__eyebrow">Besluit centralist</span>
          <h3>Vastgestelde prioriteit</h3>
        </div>
        {props.dossier.decided_priority ? (
          <StatusPill
            value={intakePriorityLabel(props.dossier.decided_priority)}
            tone={intakePriorityTone(props.dossier.decided_priority)}
          />
        ) : null}
      </header>
      <div className="intake-priority-grid" role="radiogroup" aria-label="Vastgestelde prioriteit">
        {intakePriorityOptions.map((option) => {
          const isOverride = advised === null || option.value !== advised;
          return (
            <label
              className={`intake-priority-option intake-priority-option--${option.value}${props.priority === option.value ? ' intake-priority-option--selected' : ''}`}
              key={option.value}
            >
              <input
                type="radio"
                name={`priority-${props.dossier.id}`}
                value={option.value}
                checked={props.priority === option.value}
                disabled={props.blocked || (isOverride && !props.canOverride)}
                onChange={() => props.onPriorityChange(option.value)}
              />
              <span>{option.label}</span>
              {option.value === advised ? <small>Advies</small> : null}
            </label>
          );
        })}
      </div>
      {props.blocked ? (
        <p className="intake-blocked-notice">
          <AlertTriangle size={15} /> De beoordeling wordt beschikbaar zodra de kerngegevens compleet zijn.
        </p>
      ) : null}
      {props.overrideRequired ? (
        <label>
          Reden van afwijking *
          <textarea
            value={props.reason}
            required
            maxLength={1000}
            placeholder="Leg kort uit waarom je afwijkt."
            onChange={(event) => props.onReasonChange(event.target.value)}
          />
        </label>
      ) : null}
      <button className="secondary-button" type="button" disabled={props.saving || props.blocked} onClick={props.onSave}>
        {props.saving ? <Loader2 className="spin" size={16} /> : <ClipboardCheck size={16} />}
        Beoordeling vastleggen
      </button>
      {props.error ? <p className="form-error" role="alert">{props.error}</p> : null}
    </section>
  );
}

interface DeploymentForm {
  teamIds: string[];
  resources: string[];
  recipientCount: number | null;
  dispatchMode: 'preannouncement' | 'direct_dispatch' | null;
  certificationTypeIds: string[];
  notes: string;
}

function DeploymentCard(props: {
  proposal: IntakeDeploymentProposal | null;
  selected: IntakeDeploymentProposal | null;
  teams: Team[];
  certifications: Certification[];
  canOverride: boolean;
  draft: DeploymentForm;
  onChange: (draft: DeploymentForm) => void;
}) {
  const { proposal, selected, teams, certifications, canOverride, draft, onChange } = props;
  const shown = proposal ?? selected;

  return (
    <section className="intake-assessment-card">
      <header>
        <div>
          <span className="intake-workspace__eyebrow">Voorstel</span>
          <h3>Inzet</h3>
        </div>
      </header>
      {shown ? (
        <>
          <div className="intake-deployment-proposal">
            <strong>{shown.label}</strong>
            <p>{shown.summary}</p>
            <dl>
              <div>
                <dt>Ontvangers</dt>
                <dd>{shown.recommended_recipient_count ?? 'Niet bepaald'}</dd>
              </div>
              <div>
                <dt>Adviesroute</dt>
                <dd>{dispatchModeLabel(shown.recommended_dispatch_mode)}</dd>
              </div>
              <div>
                <dt>Certificaten</dt>
                <dd>{certificationSnapshotNames(shown)}</dd>
              </div>
            </dl>
            <small>Dit voorstel selecteert of alarmeert niemand automatisch.</small>
          </div>
          <fieldset className="intake-team-choice" disabled={!canOverride}>
            <legend>Teams</legend>
            {teams.filter((team) => team.is_operational).map((team) => (
              <label key={team.id}>
                <input
                  type="checkbox"
                  checked={draft.teamIds.includes(team.id)}
                  onChange={() => onChange({
                    ...draft,
                    teamIds: draft.teamIds.includes(team.id)
                      ? draft.teamIds.filter((id) => id !== team.id)
                      : [...draft.teamIds, team.id],
                  })}
                />
                <span>{team.code} · {team.name}</span>
              </label>
            ))}
          </fieldset>
          <div className="intake-deployment-routing">
            <label>
              Aanbevolen aantal ontvangers
              <input
                type="number"
                min="1"
                max="200"
                value={draft.recipientCount ?? ''}
                disabled={!canOverride}
                onChange={(event) => onChange({
                  ...draft,
                  recipientCount: event.target.value === '' ? null : Number(event.target.value),
                })}
              />
            </label>
            <label>
              Adviesroute
              <select
                value={draft.dispatchMode ?? ''}
                disabled={!canOverride}
                onChange={(event) => onChange({
                  ...draft,
                  dispatchMode: event.target.value === ''
                    ? null
                    : event.target.value as DeploymentForm['dispatchMode'],
                })}
              >
                <option value="">Niet bepaald</option>
                <option value="preannouncement">Voorwaarschuwing</option>
                <option value="direct_dispatch">Directe alarmering</option>
              </select>
            </label>
          </div>
          <fieldset className="intake-team-choice" disabled={!canOverride}>
            <legend>Vereiste certificaattypen</legend>
            {certifications.map((certification) => (
              <label key={certification.id}>
                <input
                  type="checkbox"
                  checked={draft.certificationTypeIds.includes(certification.id)}
                  onChange={() => onChange({
                    ...draft,
                    certificationTypeIds: draft.certificationTypeIds.includes(certification.id)
                      ? draft.certificationTypeIds.filter((id) => id !== certification.id)
                      : [...draft.certificationTypeIds, certification.id],
                  })}
                />
                <span>{certification.code} · {certification.name}</span>
              </label>
            ))}
          </fieldset>
          <label>
            Benodigde middelen
            <textarea
              value={draft.resources.join('\n')}
              disabled={!canOverride}
              placeholder="Eén middel per regel"
              onChange={(event) => onChange({
                ...draft,
                resources: event.target.value.split('\n').map((value) => value.trim()).filter(Boolean),
              })}
            />
          </label>
          <label>
            Toelichting inzet
            <textarea
              value={draft.notes}
              disabled={!canOverride}
              maxLength={1000}
              onChange={(event) => onChange({ ...draft, notes: event.target.value })}
            />
          </label>
          {!canOverride ? <small>Afwijken van het inzetvoorstel vereist aanvullende rechten.</small> : null}
        </>
      ) : (
        <p className="intake-assessment-card__empty">Er is nog geen inzetvoorstel. Vul eerst de ontbrekende gegevens aan.</p>
      )}
    </section>
  );
}

function ReadonlyIntake(props: { dossier: IntakeDossier; canRefresh: boolean; onRefresh: () => Promise<void> }) {
  return (
    <div className="intake-readonly">
      <header>
        <div>
          <span className="intake-workspace__eyebrow">Uitvraag</span>
          <h3>{props.dossier.subject_type_label}</h3>
          <span>Bijgewerkt {formatDateTime(props.dossier.updated_at)}</span>
        </div>
        <div>
          <StatusPill
            value={intakePriorityLabel(props.dossier.decided_priority ?? props.dossier.triage.recommended_priority)}
            tone={intakePriorityTone(props.dossier.decided_priority ?? props.dossier.triage.recommended_priority)}
          />
          {props.canRefresh ? (
            <button className="icon-button" type="button" aria-label="Uitvraag vernieuwen" onClick={() => void props.onRefresh()}>
              <RefreshCcw size={16} />
            </button>
          ) : null}
        </div>
      </header>
      {props.dossier.answer_rows.length > 0 ? (
        <dl className="intake-readonly__answers">
          {props.dossier.answer_rows.map((answer) => (
            <div key={answer.key}>
              <dt>{answer.label}</dt>
              <dd>{answer.display_value || '-'}</dd>
            </div>
          ))}
        </dl>
      ) : (
        <div className="empty-panel">Nog geen uitvraaggegevens vastgelegd.</div>
      )}
      {props.dossier.incident_id ? (
        <Link className="secondary-button" href={`/incidents/${props.dossier.incident_id}`}>
          Naar gekoppeld incident <ArrowRight size={16} />
        </Link>
      ) : null}
    </div>
  );
}

function SaveIndicator({ state }: { state: IntakeSaveState }) {
  const icon: ReactNode = state === 'saving'
    ? <Loader2 className="spin" size={15} />
    : state === 'offline'
      ? <CloudOff size={15} />
      : state === 'conflict' || state === 'error'
        ? <AlertTriangle size={15} />
        : <Check size={15} />;

  return (
    <span className={`intake-save-state intake-save-state--${state}`} role="status" aria-live="polite">
      {icon}
      {intakeSaveLabel(state)}
    </span>
  );
}

function deploymentFormFromDossier(dossier: IntakeDossier): DeploymentForm {
  const proposal = dossier.selected_deployment_proposal ?? dossier.deployment_proposal;
  return {
    teamIds: proposal?.team_ids ?? [],
    resources: proposal?.resources ?? [],
    recipientCount: proposal?.recommended_recipient_count ?? null,
    dispatchMode: proposal?.recommended_dispatch_mode ?? null,
    certificationTypeIds: proposal?.required_certification_type_ids ?? [],
    notes: proposal?.notes ?? '',
  };
}

function deploymentDiffers(draft: DeploymentForm, proposal: IntakeDeploymentProposal | null): boolean {
  if (proposal === null) {
    return draft.teamIds.length > 0
      || draft.resources.length > 0
      || draft.recipientCount !== null
      || draft.dispatchMode !== null
      || draft.certificationTypeIds.length > 0
      || draft.notes.trim() !== '';
  }
  return !sameStringSet(draft.teamIds, proposal.team_ids)
    || !sameStringList(draft.resources, proposal.resources)
    || draft.recipientCount !== proposal.recommended_recipient_count
    || draft.dispatchMode !== proposal.recommended_dispatch_mode
    || !sameStringSet(draft.certificationTypeIds, proposal.required_certification_type_ids)
    || draft.notes.trim() !== (proposal.notes ?? '').trim();
}

function DeploymentSnapshotCard({ proposal }: { proposal: IntakeDeploymentProposal | null }) {
  if (proposal === null) return null;

  return (
    <section className="intake-assessment-card">
      <header>
        <div>
          <span className="intake-workspace__eyebrow">Vastgelegde inzet</span>
          <h3>{proposal.label}</h3>
        </div>
      </header>
      <p className="intake-assessment-card__empty">{proposal.summary}</p>
      <dl className="intake-snapshot-facts">
        <div><dt>Ontvangers</dt><dd>{proposal.recommended_recipient_count ?? 'Niet bepaald'}</dd></div>
        <div><dt>Adviesroute</dt><dd>{dispatchModeLabel(proposal.recommended_dispatch_mode)}</dd></div>
        <div><dt>Teams</dt><dd>{proposal.teams.map((team) => team.name).join(', ') || 'Geen'}</dd></div>
        <div><dt>Certificaten</dt><dd>{certificationSnapshotNames(proposal)}</dd></div>
        <div><dt>Middelen</dt><dd>{proposal.resources.join(', ') || 'Geen'}</dd></div>
      </dl>
      <small>Dit is een advies/snapshot en start geen alarmering.</small>
    </section>
  );
}

function dispatchModeLabel(mode: IntakeDeploymentProposal['recommended_dispatch_mode']): string {
  if (mode === 'preannouncement') return 'Voorwaarschuwing';
  if (mode === 'direct_dispatch') return 'Directe alarmering';
  return 'Niet bepaald';
}

function certificationSnapshotNames(proposal: IntakeDeploymentProposal): string {
  if (proposal.required_certification_type_ids.length === 0) return 'Geen';
  const names = proposal.required_certification_types.map((certification) => certification.name);
  return names.length === proposal.required_certification_type_ids.length
    ? names.join(', ')
    : `${proposal.required_certification_type_ids.length} vereist`;
}

function sameStringSet(left: string[], right: string[]): boolean {
  if (left.length !== right.length) return false;
  const sortedLeft = [...left].sort();
  const sortedRight = [...right].sort();
  return sortedLeft.every((value, index) => value === sortedRight[index]);
}

function sameStringList(left: string[], right: string[]): boolean {
  return left.length === right.length && left.every((value, index) => value === right[index]);
}

function asInputString(value: unknown): string {
  if (typeof value === 'string' || typeof value === 'number') return String(value);
  return '';
}

function dateTimeLocalValue(value: unknown): string {
  if (typeof value !== 'string' || value.trim() === '') return '';
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return '';
  const offset = parsed.getTimezoneOffset() * 60_000;
  return new Date(parsed.getTime() - offset).toISOString().slice(0, 16);
}

function isOfflineError(error: unknown): boolean {
  return (error instanceof ApiClientError && error.status === 0)
    || (typeof navigator !== 'undefined' && navigator.onLine === false);
}
