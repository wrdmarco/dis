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
  forwardRef,
  useCallback,
  useEffect,
  useImperativeHandle,
  useMemo,
  useRef,
  useState,
  type FocusEvent,
  type ReactNode,
} from 'react';
import { AddressAutocomplete } from '../../components/AddressAutocomplete';
import { useConfirmDialog } from '../../components/ConfirmDialogContext';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import {
  browserNavigationController,
  type InterceptableNavigationEvent,
} from '../../lib/browserNavigation';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type { Team } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import {
  bindingLabel,
  conflictDeploymentRequest,
  deploymentRequestBooleanChoice,
  deploymentRequestBranchFields,
  deploymentRequestCommonFields,
  deploymentRequestCompleteness,
  deploymentRequestDecisionProfileId,
  deploymentRequestFieldIsAnswered,
  deploymentRequestHasChanges,
  deploymentRequestPriorityLabel,
  deploymentRequestPriorityOptions,
  deploymentRequestPriorityTone,
  deploymentRequestRequiredAnswersAreComplete,
  deploymentRequestSaveLabel,
  deploymentRequestStatusLabel,
  deploymentRequestStatusTone,
  deploymentRequestSuggestedDecisionPriority,
  makeDeploymentRequestMutationId,
  mergeDeploymentRequestChanges,
  mergeQueuedDeploymentRequestChanges,
  rebaseDeploymentRequestTeamIds,
  type DeploymentProposal,
  type DeploymentRequest,
  type DeploymentRequestChanges,
  type DeploymentRequestFieldOption,
  type DeploymentRequestPriority,
  type DeploymentRequestSaveState,
  type DeploymentRequestSubjectType,
  type DeploymentRequestWorkflowBinding,
  type DeploymentRequestWorkflowField,
  type DeploymentRequestWorkflowRevision,
  type PrepareDeploymentResponse,
} from './deploymentRequestWorkflow';

interface DeploymentRequestWorkspaceProps {
  deploymentRequest: DeploymentRequest;
  workflow: DeploymentRequestWorkflowRevision | null;
  canManage: boolean;
  saveEndpoint?: string;
  compact?: boolean;
  allowPreparedEditing?: boolean;
  interactionDisabled?: boolean;
  onDeploymentRequestChange: (deploymentRequest: DeploymentRequest) => void;
  onChangesQueued?: (changes: DeploymentRequestChanges) => void;
  onRefresh: () => Promise<void>;
}

export interface DeploymentRequestWorkspaceHandle {
  savePendingChanges: () => Promise<DeploymentRequest | null>;
}

interface QueuedMutation {
  changes: DeploymentRequestChanges;
  clientMutationId: string;
  lockVersion: number;
}

interface ConflictState {
  current: DeploymentRequest;
  attempted: DeploymentRequestChanges;
}

const EMPTY_CHANGES: DeploymentRequestChanges = {};
const AUTOSAVE_DELAY_MS = 850;

export const DeploymentRequestWorkspace = forwardRef<
  DeploymentRequestWorkspaceHandle,
  DeploymentRequestWorkspaceProps
>(function DeploymentRequestWorkspace(props, ref) {
  const {
    deploymentRequest,
    workflow,
    canManage,
    saveEndpoint = `/deployment-requests/${deploymentRequest.id}`,
    compact = false,
    allowPreparedEditing = false,
    interactionDisabled = false,
    onDeploymentRequestChange,
    onChangesQueued,
    onRefresh,
  } = props;
  const router = useRouter();
  const { api, hasPermission } = useAuth();
  const confirmAction = useConfirmDialog();
  const canOverride = hasPermission('deployment-requests.priority.override');
  const teams = useApiResource<Team[]>(
    '/teams',
    canManage
      && (deploymentRequest.status === 'open'
        || (allowPreparedEditing && deploymentRequest.status === 'prepared')),
  );
  const [draft, setDraft] = useState(deploymentRequest);
  const [saveState, setSaveState] = useState<DeploymentRequestSaveState>('saved');
  const [saveError, setSaveError] = useState<string | null>(null);
  const [conflict, setConflict] = useState<ConflictState | null>(null);
  const [decisionPriority, setDecisionPriority] = useState<DeploymentRequestPriority | null>(
    deploymentRequestSuggestedDecisionPriority(deploymentRequest),
  );
  const [decisionReason, setDecisionReason] = useState(deploymentRequest.priority_override_reason ?? '');
  const [deploymentDraft, setDeploymentDraft] = useState(
    () => deploymentFormFromRequest(deploymentRequest),
  );
  const deploymentDraftRef = useRef(deploymentDraft);
  const deploymentDraftBaselineRef = useRef(deploymentDraft);
  const deploymentDraftDirtyFieldsRef = useRef<Set<DeploymentFormField>>(new Set());
  const [decisionSaving, setDecisionSaving] = useState(false);
  const [saveAllActive, setSaveAllActive] = useState(false);
  const [decisionError, setDecisionError] = useState<string | null>(null);
  const [preparingDeployment, setPreparingDeployment] = useState(false);
  const [prepareDeploymentError, setPrepareDeploymentError] = useState<string | null>(null);
  const [closing, setClosing] = useState(false);
  const [closeConfirmOpen, setCloseConfirmOpen] = useState(false);
  const [closeReason, setCloseReason] = useState('');
  const [closeError, setCloseError] = useState<string | null>(null);
  const pendingRef = useRef<DeploymentRequestChanges>(EMPTY_CHANGES);
  const retryMutationRef = useRef<QueuedMutation | null>(null);
  const inFlightRef = useRef<Promise<boolean> | null>(null);
  const conflictRef = useRef<ConflictState | null>(null);
  const versionRef = useRef(deploymentRequest.lock_version);
  const timerRef = useRef<number | null>(null);
  const flushRef = useRef<() => Promise<boolean>>(async () => true);
  const saveAllRef = useRef<() => Promise<boolean>>(async () => true);
  const saveAllActiveRef = useRef(false);
  const saveAllInFlightRef = useRef<Promise<DeploymentRequest | null> | null>(null);
  const mountedRef = useRef(true);
  const navigationPendingRef = useRef(false);
  const prepareDeploymentMutationIdRef = useRef<string | null>(null);
  const decisionMutationRef = useRef<{
    signature: string;
    payload: Record<string, unknown>;
  } | null>(null);
  const decisionSelectionAdjustedRef = useRef(false);
  const prioritySelectionAdjustedRef = useRef(false);
  const decisionReasonAdjustedRef = useRef(false);
  const deploymentDraftAdjustedRef = useRef(false);
  const closeMutationRef = useRef<{
    signature: string;
    payload: Record<string, unknown>;
  } | null>(null);
  const latestDeploymentRequestRef = useRef(deploymentRequest);

  const configuration = workflow?.configuration ?? null;
  const bindingByField = useMemo(
    () => new Map((configuration?.bindings ?? []).map((binding) => [binding.field_key, binding])),
    [configuration?.bindings],
  );
  const completeness = configuration ? deploymentRequestCompleteness(draft, configuration) : null;
  const requiredAnswersComplete = configuration
    ? deploymentRequestRequiredAnswersAreComplete(draft, configuration)
    : false;
  const recommendedPlan = draft.deployment_proposal;
  const selectedPlan = draft.selected_deployment_proposal;
  const planChanged = deploymentDiffers(deploymentDraft, recommendedPlan);
  const priorityChangedFromAdvice = decisionPriority !== null
    && decisionPriority !== draft.triage.recommended_priority;
  const overrideRequired = priorityChangedFromAdvice || planChanged;
  const assessmentBlocked = draft.triage.state === 'incomplete';
  const statusEditable = draft.status === 'open'
    || (allowPreparedEditing && draft.status === 'prepared');
  const interactionLocked = interactionDisabled
    || saveAllActive
    || preparingDeployment
    || closing;
  const editable = canManage
    && statusEditable
    && conflict === null
    && configuration !== null
    && !interactionLocked;

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      if (timerRef.current !== null) window.clearTimeout(timerRef.current);
      if (
        deploymentRequestHasChanges(pendingRef.current)
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
        || !deploymentRequestHasUnsavedWork()
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
      void saveAllRef.current().then((saved) => {
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
      if (!deploymentRequestHasUnsavedWork()) return;

      event.preventDefault();
      event.returnValue = '';
    };

    window.addEventListener('beforeunload', warnBeforeUnload);
    return () => window.removeEventListener('beforeunload', warnBeforeUnload);
  }, []);

  useEffect(() => {
    const saveBeforeHistoryNavigation = (event: InterceptableNavigationEvent) => {
      if (
        !deploymentRequestHasUnsavedWork()
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
          const saved = await saveAllRef.current();
          navigationPendingRef.current = false;
          if (!saved) {
            throw new DOMException('Navigatie geannuleerd omdat de aanvraag niet kon worden opgeslagen.', 'AbortError');
          }
        },
      });
    };
    const navigation = browserNavigationController();
    navigation?.addEventListener('navigate', saveBeforeHistoryNavigation);
    return () => navigation?.removeEventListener('navigate', saveBeforeHistoryNavigation);
  }, []);

  useEffect(() => {
    const requestChanged = deploymentRequest.id !== latestDeploymentRequestRef.current.id;
    const externalVersionAdvanced = !requestChanged
      && deploymentRequest.lock_version > versionRef.current;
    if (requestChanged || deploymentRequest.lock_version >= latestDeploymentRequestRef.current.lock_version) {
      latestDeploymentRequestRef.current = deploymentRequest;
    }
    if (
      externalVersionAdvanced
      && (
        decisionSelectionAdjustedRef.current
        || deploymentDraftAdjustedRef.current
      )
      && !deploymentRequestHasChanges(pendingRef.current)
      && retryMutationRef.current === null
      && inFlightRef.current === null
      && saveAllInFlightRef.current === null
      && conflict === null
    ) {
      const nextConflict = { current: deploymentRequest, attempted: EMPTY_CHANGES };
      conflictRef.current = nextConflict;
      setConflict(nextConflict);
      setSaveState('conflict');
      setSaveError(
        'Het aanvraagdossier of inzetplan is intussen gewijzigd. Controleer de serverversie voordat je lokale wijzigingen toepast.',
      );
      return;
    }
    if (
      (requestChanged || deploymentRequest.lock_version > versionRef.current)
      && !deploymentRequestHasChanges(pendingRef.current)
      && retryMutationRef.current === null
      && inFlightRef.current === null
      && conflict === null
    ) {
      versionRef.current = deploymentRequest.lock_version;
      setDraft(deploymentRequest);
      const preserveDecision = !requestChanged && decisionSelectionAdjustedRef.current;
      const preserveDeploymentProposal = !requestChanged && deploymentDraftAdjustedRef.current;
      if (!preserveDecision) {
        decisionSelectionAdjustedRef.current = false;
        prioritySelectionAdjustedRef.current = false;
        decisionReasonAdjustedRef.current = false;
        setDecisionPriority(deploymentRequestSuggestedDecisionPriority(deploymentRequest));
        setDecisionReason(deploymentRequest.priority_override_reason ?? '');
      }
      if (!preserveDeploymentProposal) {
        deploymentDraftAdjustedRef.current = false;
        deploymentDraftDirtyFieldsRef.current = new Set();
        const nextDeploymentDraft = deploymentFormFromRequest(deploymentRequest);
        deploymentDraftBaselineRef.current = nextDeploymentDraft;
        deploymentDraftRef.current = nextDeploymentDraft;
        setDeploymentDraft(nextDeploymentDraft);
      }
      setSaveState(
        preserveDecision || preserveDeploymentProposal ? 'dirty' : 'saved',
      );
      setSaveError(null);
    }
  }, [conflict, deploymentRequest]);

  const scheduleSave = useCallback(() => {
    if (timerRef.current !== null) window.clearTimeout(timerRef.current);
    timerRef.current = window.setTimeout(() => {
      timerRef.current = null;
      void flushRef.current();
    }, AUTOSAVE_DELAY_MS);
  }, []);

  const flushSave = useCallback(async (): Promise<boolean> => {
    if (!canManage || !statusEditable) return true;
    if (conflictRef.current !== null) return false;
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
    if (!deploymentRequestHasChanges(changes)) {
      if (mountedRef.current) {
        setSaveState(
          decisionSelectionAdjustedRef.current
            || deploymentDraftAdjustedRef.current
            ? 'dirty'
            : 'saved',
        );
      }
      return true;
    }
    if (changes.title !== undefined && changes.title.trim() === '') {
      if (mountedRef.current) {
        setSaveState('error');
        setSaveError('Vul een titel in voordat je de aanvraag opslaat.');
      }
      return false;
    }

    const mutation: QueuedMutation = retry ?? {
      changes,
      clientMutationId: makeDeploymentRequestMutationId(),
      lockVersion: versionRef.current,
    };
    if (retry === null) pendingRef.current = EMPTY_CHANGES;
    if (mountedRef.current) {
      setSaveState('saving');
      setSaveError(null);
    }

    const request = api.patch<DeploymentRequest>(saveEndpoint, {
      lock_version: mutation.lockVersion,
      client_mutation_id: mutation.clientMutationId,
      changes: mutation.changes,
    })
      .then((response) => {
        retryMutationRef.current = null;
        versionRef.current = response.data.lock_version;
        latestDeploymentRequestRef.current = response.data;
        const hasPendingChanges = deploymentRequestHasChanges(pendingRef.current);
        if (mountedRef.current) {
          onDeploymentRequestChange(response.data);
          setDraft(mergeDeploymentRequestChanges(response.data, pendingRef.current));
          if (!hasPendingChanges && !prioritySelectionAdjustedRef.current) {
            setDecisionPriority(deploymentRequestSuggestedDecisionPriority(response.data));
          }
          if (!hasPendingChanges && !decisionReasonAdjustedRef.current) {
            setDecisionReason(response.data.priority_override_reason ?? '');
          }
          const serverDeploymentDraft = deploymentFormFromRequest(response.data);
          if (deploymentDraftDirtyFieldsRef.current.size > 0) {
            const rebasedDeploymentDraft = rebaseDeploymentForm(
              deploymentDraftRef.current,
              deploymentDraftBaselineRef.current,
              serverDeploymentDraft,
              deploymentDraftDirtyFieldsRef.current,
            );
            deploymentDraftBaselineRef.current = serverDeploymentDraft;
            const rebasedDirtyFields = deploymentFormDirtyFields(
              rebasedDeploymentDraft,
              serverDeploymentDraft,
            );
            deploymentDraftDirtyFieldsRef.current = rebasedDirtyFields;
            deploymentDraftAdjustedRef.current = rebasedDirtyFields.size > 0;
            deploymentDraftRef.current = rebasedDeploymentDraft;
            setDeploymentDraft(rebasedDeploymentDraft);
          } else {
            deploymentDraftAdjustedRef.current = false;
            deploymentDraftDirtyFieldsRef.current = new Set();
            deploymentDraftBaselineRef.current = serverDeploymentDraft;
            deploymentDraftRef.current = serverDeploymentDraft;
            setDeploymentDraft(serverDeploymentDraft);
          }
          setSaveState(
            hasPendingChanges
              || decisionSelectionAdjustedRef.current
              || deploymentDraftAdjustedRef.current
              ? 'dirty'
              : 'saved',
          );
          setSaveError(null);
        }
        return true;
      })
      .catch((caught: unknown) => {
        if (
          caught instanceof ApiClientError
          && caught.status === 409
          && ['deployment_request_version_conflict', 'deployment_request_mutation_conflict'].includes(caught.code)
        ) {
          const current = conflictDeploymentRequest(caught.details);
          if (current !== null) {
            const attempted = mergeQueuedDeploymentRequestChanges(
              mutation.changes,
              pendingRef.current,
            );
            pendingRef.current = EMPTY_CHANGES;
            retryMutationRef.current = null;
            if (mountedRef.current) {
              const nextConflict = { current, attempted };
              conflictRef.current = nextConflict;
              setConflict(nextConflict);
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
    if (succeeded && deploymentRequestHasChanges(pendingRef.current)) {
      return flushRef.current();
    }

    return succeeded;
  }, [api, canManage, onDeploymentRequestChange, saveEndpoint, statusEditable]);

  function deploymentRequestHasUnsavedWork(): boolean {
    return deploymentRequestHasChanges(pendingRef.current)
      || retryMutationRef.current !== null
      || inFlightRef.current !== null
      || decisionSelectionAdjustedRef.current
      || deploymentDraftAdjustedRef.current;
  }

  useEffect(() => {
    flushRef.current = flushSave;
  }, [flushSave]);

  useEffect(() => {
    const retryWhenOnline = () => {
      if (
        retryMutationRef.current !== null
        || deploymentRequestHasChanges(pendingRef.current)
      ) {
        void flushRef.current();
      }
    };
    window.addEventListener('online', retryWhenOnline);
    return () => window.removeEventListener('online', retryWhenOnline);
  }, []);

  const queueChanges = useCallback((changes: DeploymentRequestChanges) => {
    if (interactionDisabled || saveAllActiveRef.current) return;
    onChangesQueued?.(changes);
    setDraft((current) => mergeDeploymentRequestChanges(current, changes));
    pendingRef.current = mergeQueuedDeploymentRequestChanges(pendingRef.current, changes);
    setSaveState('dirty');
    setSaveError(null);
    scheduleSave();
  }, [interactionDisabled, onChangesQueued, scheduleSave]);

  const loadServerConflict = () => {
    if (conflict === null || saveAllActiveRef.current) return;
    pendingRef.current = EMPTY_CHANGES;
    retryMutationRef.current = null;
    versionRef.current = conflict.current.lock_version;
    latestDeploymentRequestRef.current = conflict.current;
    onDeploymentRequestChange(conflict.current);
    setDraft(conflict.current);
    decisionSelectionAdjustedRef.current = false;
    prioritySelectionAdjustedRef.current = false;
    decisionReasonAdjustedRef.current = false;
    deploymentDraftAdjustedRef.current = false;
    deploymentDraftDirtyFieldsRef.current = new Set();
    setDecisionPriority(deploymentRequestSuggestedDecisionPriority(conflict.current));
    setDecisionReason(conflict.current.priority_override_reason ?? '');
    const nextDeploymentDraft = deploymentFormFromRequest(conflict.current);
    deploymentDraftBaselineRef.current = nextDeploymentDraft;
    deploymentDraftRef.current = nextDeploymentDraft;
    setDeploymentDraft(nextDeploymentDraft);
    conflictRef.current = null;
    setConflict(null);
    setSaveState('saved');
    setSaveError(null);
  };

  const retryConflictOnCurrent = () => {
    if (conflict === null || saveAllActiveRef.current) return;
    versionRef.current = conflict.current.lock_version;
    latestDeploymentRequestRef.current = conflict.current;
    onDeploymentRequestChange(conflict.current);
    setDraft(mergeDeploymentRequestChanges(conflict.current, conflict.attempted));
    if (!prioritySelectionAdjustedRef.current) {
      setDecisionPriority(deploymentRequestSuggestedDecisionPriority(conflict.current));
    }
    if (!decisionReasonAdjustedRef.current) {
      setDecisionReason(conflict.current.priority_override_reason ?? '');
    }
    if (deploymentDraftDirtyFieldsRef.current.size > 0) {
      const serverDeploymentDraft = deploymentFormFromRequest(conflict.current);
      const rebasedDeploymentDraft = rebaseDeploymentForm(
        deploymentDraftRef.current,
        deploymentDraftBaselineRef.current,
        serverDeploymentDraft,
        deploymentDraftDirtyFieldsRef.current,
      );
      deploymentDraftBaselineRef.current = serverDeploymentDraft;
      const rebasedDirtyFields = deploymentFormDirtyFields(
        rebasedDeploymentDraft,
        serverDeploymentDraft,
      );
      deploymentDraftDirtyFieldsRef.current = rebasedDirtyFields;
      deploymentDraftAdjustedRef.current = rebasedDirtyFields.size > 0;
      deploymentDraftRef.current = rebasedDeploymentDraft;
      setDeploymentDraft(rebasedDeploymentDraft);
    } else {
      deploymentDraftDirtyFieldsRef.current = new Set();
      deploymentDraftAdjustedRef.current = false;
      const nextDeploymentDraft = deploymentFormFromRequest(conflict.current);
      deploymentDraftBaselineRef.current = nextDeploymentDraft;
      deploymentDraftRef.current = nextDeploymentDraft;
      setDeploymentDraft(nextDeploymentDraft);
    }
    pendingRef.current = conflict.attempted;
    retryMutationRef.current = null;
    conflictRef.current = null;
    setConflict(null);
    setSaveState('dirty');
    setSaveError(null);
    window.setTimeout(() => void saveAllRef.current(), 0);
  };

  const changeSubject = async (subjectType: DeploymentRequestSubjectType) => {
    if (
      interactionDisabled
      || saveAllActiveRef.current
      || !configuration
      || subjectType === draft.subject_type
    ) return;
    const oldBranchHasAnswers = deploymentRequestBranchFields(configuration, draft.subject_type)
      .some((field) => deploymentRequestFieldIsAnswered(field, draft.answers[field.key]));
    if (
      oldBranchHasAnswers
      && !await confirmAction({
        title: 'Type zoekonderwerp wijzigen?',
        message: 'De eerdere typespecifieke antwoorden blijven in de aanvraaggeschiedenis bewaard, maar zijn niet meer zichtbaar in de huidige uitvraag.',
        confirmLabel: 'Type wijzigen',
        intent: 'warning',
      })
    ) {
      return;
    }

    queueChanges({ subject_type: subjectType });
    const subjectLabel = configuration.subject_types.find((subject) => subject.key === subjectType)?.label;
    if (subjectLabel) {
      setDraft((current) => ({ ...current, subject_type_label: subjectLabel }));
    }
  };

  const requestDecision = async (
    current: DeploymentRequest,
    desiredDecision: Record<string, unknown>,
  ): Promise<DeploymentRequest> => {
    const decisionSignature = JSON.stringify({
      deployment_request_id: current.id,
      lock_version: current.lock_version,
      ...desiredDecision,
    });
    if (decisionMutationRef.current?.signature !== decisionSignature) {
      decisionMutationRef.current = {
        signature: decisionSignature,
        payload: {
          lock_version: current.lock_version,
          client_mutation_id: makeDeploymentRequestMutationId(),
          ...desiredDecision,
        },
      };
    }

    const response = await api.patch<DeploymentRequest>(
      `/deployment-requests/${current.id}/priority`,
      decisionMutationRef.current.payload,
    );
    decisionMutationRef.current = null;

    return response.data;
  };

  const performPersistAllChanges = async (): Promise<DeploymentRequest | null> => {
    setDecisionError(null);
    if (!await flushSave()) return null;
    if (conflictRef.current !== null) return null;

    const current = latestDeploymentRequestRef.current;
    if (
      !decisionSelectionAdjustedRef.current
      && !deploymentDraftAdjustedRef.current
    ) {
      return current;
    }

    if (current.triage.state === 'incomplete') {
      setDecisionError('Vul eerst de ontbrekende kerngegevens in.');
      return null;
    }
    const priority = prioritySelectionAdjustedRef.current
      ? decisionPriority
      : deploymentRequestSuggestedDecisionPriority(current);
    if (priority === null) {
      setDecisionError('Kies eerst de vastgestelde prioriteit.');
      return null;
    }

    const currentRecommendedPlan = current.deployment_proposal;
    const currentSelectedPlan = current.selected_deployment_proposal;
    const effectiveDeploymentDraft = deploymentDraftAdjustedRef.current
      ? deploymentDraftRef.current
      : deploymentFormFromRequest(current);
    const effectiveDecisionReason = decisionReasonAdjustedRef.current
      ? decisionReason
      : (current.priority_override_reason ?? '');
    const currentPlanChanged = deploymentDiffers(effectiveDeploymentDraft, currentRecommendedPlan);
    const currentOverrideRequired = priority !== current.triage.recommended_priority
      || currentPlanChanged;
    if (currentOverrideRequired && effectiveDecisionReason.trim() === '') {
      setDecisionError('Leg uit waarom je afwijkt van het advies of inzetvoorstel.');
      return null;
    }
    if (currentOverrideRequired && !canOverride) {
      setDecisionError('Je hebt geen toestemming om van het advies of inzetvoorstel af te wijken.');
      return null;
    }

    setDecisionSaving(true);
    try {
      const selectedProfileId = deploymentRequestDecisionProfileId(
        priority,
        current.decided_priority,
        current.triage.recommended_priority,
        currentSelectedPlan?.profile_id,
        currentRecommendedPlan?.profile_id,
      );
      const desiredDecision = {
        priority,
        selected_deployment_profile_id: selectedProfileId,
        ...(currentPlanChanged ? {
          deployment_adjustments: {
            team_ids: effectiveDeploymentDraft.teamIds,
            resources: effectiveDeploymentDraft.resources,
            recommended_recipient_count: effectiveDeploymentDraft.recipientCount,
            recommended_dispatch_mode: effectiveDeploymentDraft.dispatchMode,
            notes: effectiveDeploymentDraft.notes.trim() === '' ? null : effectiveDeploymentDraft.notes,
          },
        } : {}),
        reason: currentOverrideRequired ? effectiveDecisionReason.trim() : undefined,
      };
      const decided = await requestDecision(current, desiredDecision);
      const adopted = adoptActionResponse(decided);
      setDecisionError(null);
      return adopted;
    } catch (caught) {
      if (adoptActionConflict(caught)) {
        decisionMutationRef.current = null;
        return null;
      }
      setDecisionError(caught instanceof ApiClientError ? caught.message : 'De beoordeling kon niet worden opgeslagen.');
      return null;
    } finally {
      setDecisionSaving(false);
    }
  };

  const persistAllChanges = (): Promise<DeploymentRequest | null> => {
    const active = saveAllInFlightRef.current;
    if (active !== null) return active;

    saveAllActiveRef.current = true;
    if (mountedRef.current) setSaveAllActive(true);
    const operation = performPersistAllChanges()
      .finally(() => {
        saveAllInFlightRef.current = null;
        saveAllActiveRef.current = false;
        if (mountedRef.current) setSaveAllActive(false);
      });
    saveAllInFlightRef.current = operation;

    return operation;
  };

  const saveDecision = async () => {
    await persistAllChanges();
  };

  useEffect(() => {
    saveAllRef.current = async () => (await persistAllChanges()) !== null;
  });

  useImperativeHandle(ref, () => ({
    savePendingChanges: persistAllChanges,
  }));

  const prepareDeployment = async () => {
    setPrepareDeploymentError(null);
    const persisted = await persistAllChanges();
    if (persisted === null) return;
    let currentDeploymentRequest = persisted;
    if (currentDeploymentRequest.triage.state === 'incomplete') {
      setPrepareDeploymentError('De uitvraag is nog onvolledig. Vul eerst de ontbrekende kerngegevens in.');
      return;
    }
    if (currentDeploymentRequest.decided_priority === null) {
      if (
        currentDeploymentRequest.triage.recommended_priority === null
        || currentDeploymentRequest.deployment_proposal === null
      ) {
        setPrepareDeploymentError('Er is nog geen betrouwbaar prioriteits- en inzetadvies. Leg de beoordeling eerst handmatig vast.');
        return;
      }
    }
    if (!await confirmAction({
      title: 'Conceptinzet voorbereiden?',
      message: 'DIS maakt vanuit deze aanvraag een conceptinzet. Er wordt nog geen alarm verstuurd.',
      confirmLabel: 'Conceptinzet voorbereiden',
      intent: 'default',
    })) return;

    setPreparingDeployment(true);
    try {
      if (currentDeploymentRequest.decided_priority === null) {
        const decided = await requestDecision(currentDeploymentRequest, {
          priority: currentDeploymentRequest.triage.recommended_priority,
          selected_deployment_profile_id: currentDeploymentRequest.deployment_proposal?.profile_id ?? null,
        });
        currentDeploymentRequest = adoptActionResponse(decided);
      }

      const preparationMutationId = prepareDeploymentMutationIdRef.current
        ?? makeDeploymentRequestMutationId();
      prepareDeploymentMutationIdRef.current = preparationMutationId;
      const response = await api.post<PrepareDeploymentResponse>(
        `/deployment-requests/${currentDeploymentRequest.id}/prepare-deployment`,
        {
          lock_version: currentDeploymentRequest.lock_version,
          client_mutation_id: preparationMutationId,
        },
      );
      prepareDeploymentMutationIdRef.current = null;
      adoptActionResponse(response.data.deployment_request);
      router.push(`/inzetten/${response.data.deployment.id}`);
    } catch (caught) {
      if (adoptActionConflict(caught)) {
        decisionMutationRef.current = null;
        prepareDeploymentMutationIdRef.current = null;
        return;
      }
      setPrepareDeploymentError(
        caught instanceof ApiClientError
          ? caught.message
          : 'De conceptinzet kon niet worden voorbereid.',
      );
    } finally {
      setPreparingDeployment(false);
    }
  };

  const closeWithoutDeployment = async () => {
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
            client_mutation_id: makeDeploymentRequestMutationId(),
            ...desiredClose,
          },
        };
      }
      const response = await api.post<DeploymentRequest>(
        `/deployment-requests/${draft.id}/close`,
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
      setCloseError(caught instanceof ApiClientError ? caught.message : 'De aanvraag kon niet worden afgesloten.');
    } finally {
      setClosing(false);
    }
  };

  const adoptActionResponse = (next: DeploymentRequest): DeploymentRequest => {
    const latest = latestDeploymentRequestRef.current;
    let adopted = latest.id === next.id && latest.lock_version > next.lock_version
      ? latest
      : next;
    if (
      conflictRef.current?.current.id === adopted.id
      && conflictRef.current.current.lock_version > adopted.lock_version
    ) {
      adopted = conflictRef.current.current;
    }
    versionRef.current = adopted.lock_version;
    latestDeploymentRequestRef.current = adopted;
    pendingRef.current = EMPTY_CHANGES;
    retryMutationRef.current = null;
    setDraft(adopted);
    onDeploymentRequestChange(adopted);
    decisionSelectionAdjustedRef.current = false;
    prioritySelectionAdjustedRef.current = false;
    decisionReasonAdjustedRef.current = false;
    deploymentDraftAdjustedRef.current = false;
    deploymentDraftDirtyFieldsRef.current = new Set();
    setDecisionPriority(deploymentRequestSuggestedDecisionPriority(adopted));
    setDecisionReason(adopted.priority_override_reason ?? '');
    const nextDeploymentDraft = deploymentFormFromRequest(adopted);
    deploymentDraftBaselineRef.current = nextDeploymentDraft;
    deploymentDraftRef.current = nextDeploymentDraft;
    setDeploymentDraft(nextDeploymentDraft);
    if (
      conflictRef.current !== null
      && conflictRef.current.current.id === adopted.id
      && conflictRef.current.current.lock_version <= adopted.lock_version
    ) {
      conflictRef.current = null;
      setConflict(null);
    }
    setSaveState(conflictRef.current === null ? 'saved' : 'conflict');

    return adopted;
  };

  function adoptActionConflict(caught: unknown): boolean {
    if (!(caught instanceof ApiClientError) || caught.status !== 409) return false;
    const current = conflictDeploymentRequest(caught.details);
    if (current === null) return false;
    const nextConflict = { current, attempted: EMPTY_CHANGES };
    conflictRef.current = nextConflict;
    setConflict(nextConflict);
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
      <ReadonlyDeploymentRequest
        deploymentRequest={draft}
        canRefresh
        onRefresh={onRefresh}
      />
    );
  }

  return (
    <div className={`deployment-request-workspace${compact ? ' deployment-request-workspace--compact' : ''}`}>
      <header className="deployment-request-workspace__topline">
        <div>
          <span className="deployment-request-workspace__eyebrow">Aanvraag</span>
          <strong>{draft.title}</strong>
          <span>{draft.subject_type_label} · versie {draft.workflow_revision.version} · bijgewerkt {formatDateTime(draft.updated_at)}</span>
        </div>
        <div className="deployment-request-workspace__top-status">
          <StatusPill
            value={deploymentRequestStatusLabel(draft.status)}
            tone={deploymentRequestStatusTone(draft.status)}
          />
          <SaveIndicator state={saveState} />
        </div>
      </header>

      {conflict ? (
        <section className="deployment-request-conflict" role="alert">
          <ShieldAlert size={22} aria-hidden />
          <div>
            <strong>Deze aanvraag is intussen door iemand anders bijgewerkt.</strong>
            <p>{saveError ?? 'Kies welke versie je wilt gebruiken voordat je verdergaat.'}</p>
            <div className="actions-row">
              <button className="secondary-button" type="button" disabled={saveAllActive} onClick={loadServerConflict}>
                <RefreshCcw size={16} /> Serverversie laden
              </button>
              <button className="primary-button" type="button" disabled={saveAllActive} onClick={retryConflictOnCurrent}>
                <Save size={16} /> Mijn wijzigingen opnieuw toepassen
              </button>
            </div>
          </div>
        </section>
      ) : null}

      {saveState === 'offline' || saveState === 'error' ? (
        <div className="deployment-request-save-warning" role="alert">
          {saveState === 'offline' ? <CloudOff size={18} aria-hidden /> : <AlertTriangle size={18} aria-hidden />}
          <span>{saveError ?? deploymentRequestSaveLabel(saveState)}</span>
          <button className="secondary-button" type="button" onClick={() => void flushSave()}>
            Opnieuw opslaan
          </button>
        </div>
      ) : null}

      <div className="deployment-request-workspace__columns">
        <div className="deployment-request-questionnaire" onBlur={handleBlur}>
          <section className="deployment-request-questionnaire__section" aria-labelledby="deployment-request-subject-heading">
            <header>
              <span>01</span>
              <div>
                <h3 id="deployment-request-subject-heading">Wie of wat zoeken we?</h3>
                <p>De uitvraag past zich aan het gekozen onderwerp aan.</p>
              </div>
            </header>
            <div className="deployment-request-subject-selector" role="radiogroup" aria-label="Type zoekonderwerp">
              {configuration.subject_types.map((subject) => (
                <label
                  className={`deployment-request-subject-card${draft.subject_type === subject.key ? ' deployment-request-subject-card--selected' : ''}`}
                  key={subject.key}
                >
                  <input
                    type="radio"
                    name={`deployment-request-subject-${draft.id}`}
                    checked={draft.subject_type === subject.key}
                    disabled={!editable}
                    onChange={() => void changeSubject(subject.key)}
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
            title="Gegevens van de aanvraag"
            description="Deze gegevens worden één keer vastgelegd en waar ingesteld overgenomen in de inzet."
            leadingContent={(
              <label
                className="deployment-request-field deployment-request-field--wide"
                htmlFor={`deployment-request-title-${draft.id}`}
              >
                <span className="deployment-request-field__label">
                  <span>Titel *</span>
                </span>
                <input
                  id={`deployment-request-title-${draft.id}`}
                  type="text"
                  value={draft.title}
                  required
                  maxLength={180}
                  autoComplete="off"
                  disabled={!editable}
                  onChange={(event) => queueChanges({ title: event.target.value })}
                />
                <small>De herkenbare titel van deze aanvraag. Maximaal 180 tekens.</small>
              </label>
            )}
            fields={deploymentRequestCommonFields(configuration)}
            answers={draft.answers}
            bindings={bindingByField}
            disabled={!editable}
            onChange={(key, value) => queueChanges({ answers: { [key]: value } })}
          />

          <QuestionSection
            number="03"
            title={`Gegevens over ${draft.subject_type_label.toLowerCase()}`}
            description="Vul aan wat nu bekend is. Je kunt deze aanvraag later blijven bijwerken."
            fields={deploymentRequestBranchFields(configuration, draft.subject_type)}
            answers={draft.answers}
            bindings={bindingByField}
            disabled={!editable}
            onChange={(key, value) => queueChanges({ answers: { [key]: value } })}
          />
        </div>

        <aside className="deployment-request-assessment" aria-label="Beoordeling aanvraag">
          <AssessmentCard deploymentRequest={draft} completeness={completeness} />

          {statusEditable && canManage ? (
            <>
              <DecisionCard
                deploymentRequest={draft}
                priority={decisionPriority}
                reason={decisionReason}
                canOverride={canOverride}
                overrideRequired={overrideRequired}
                blocked={assessmentBlocked}
                saving={decisionSaving || interactionLocked}
                disabled={interactionLocked}
                error={decisionError}
                onPriorityChange={(priority) => {
                  if (interactionDisabled || saveAllActiveRef.current) return;
                  decisionSelectionAdjustedRef.current = true;
                  prioritySelectionAdjustedRef.current = true;
                  setDecisionPriority(priority);
                  setSaveState('dirty');
                  setSaveError(null);
                }}
                onReasonChange={(reason) => {
                  if (interactionDisabled || saveAllActiveRef.current) return;
                  decisionSelectionAdjustedRef.current = true;
                  decisionReasonAdjustedRef.current = true;
                  setDecisionReason(reason);
                  setSaveState('dirty');
                  setSaveError(null);
                }}
                onSave={() => void saveDecision()}
              />
              <DeploymentCard
                proposal={recommendedPlan}
                selected={selectedPlan}
                teams={teams.data ?? []}
                canOverride={canOverride}
                disabled={interactionLocked}
                draft={deploymentDraft}
                onChange={(next, changedField) => {
                  if (interactionDisabled || saveAllActiveRef.current) return;
                  const baseline = deploymentDraftBaselineRef.current;
                  const dirtyFields = new Set(deploymentDraftDirtyFieldsRef.current);
                  if (deploymentFormFieldEquals(next, baseline, changedField)) {
                    dirtyFields.delete(changedField);
                  } else {
                    dirtyFields.add(changedField);
                  }
                  deploymentDraftDirtyFieldsRef.current = dirtyFields;
                  deploymentDraftAdjustedRef.current = dirtyFields.size > 0;
                  deploymentDraftRef.current = next;
                  setDeploymentDraft(next);
                  setSaveState(deploymentRequestHasUnsavedWork() ? 'dirty' : 'saved');
                  setSaveError(null);
                }}
              />
            </>
          ) : (
            <DeploymentSnapshotCard proposal={selectedPlan ?? recommendedPlan} />
          )}

          {draft.status === 'open' && canManage ? (
            <>
              <section className="deployment-request-actions-card">
                <div className="deployment-request-actions-card__notice">
                  <Info size={18} aria-hidden />
                  <span>Inzet voorbereiden maakt alleen een concept. Er wordt geen alarm verstuurd.</span>
                </div>
                <button
                  className="primary-button deployment-request-prepare-button"
                  type="button"
                  disabled={interactionLocked || decisionSaving || !requiredAnswersComplete}
                  onClick={() => void prepareDeployment()}
                >
                  {preparingDeployment ? <Loader2 className="spin" size={17} /> : <FileCheck2 size={17} />}
                  Inzet voorbereiden
                </button>
                {!requiredAnswersComplete ? (
                  <p className="deployment-request-blocked-notice">
                    <AlertTriangle size={15} /> Inzet voorbereiden wordt beschikbaar zodra de kerngegevens compleet zijn.
                  </p>
                ) : draft.decided_priority === null ? (
                  <p className="form-note">
                    Het geadviseerde inzetvoorstel en de teams worden bij voorbereiden automatisch vastgelegd.
                  </p>
                ) : null}
                {prepareDeploymentError ? <p className="form-error" role="alert">{prepareDeploymentError}</p> : null}
                <button
                  className="deployment-request-close-trigger"
                  type="button"
                  disabled={interactionLocked}
                  onClick={() => setCloseConfirmOpen((current) => !current)}
                >
                  <XCircle size={16} /> Afsluiten zonder inzet
                </button>
                {closeConfirmOpen ? (
                  <div className="deployment-request-close-confirm">
                    <label>
                      Reden (optioneel)
                      <textarea
                        value={closeReason}
                        maxLength={1000}
                        disabled={interactionLocked}
                        onChange={(event) => setCloseReason(event.target.value)}
                      />
                    </label>
                    <div className="actions-row">
                      <button
                        className="secondary-button"
                        type="button"
                        disabled={interactionLocked}
                        onClick={() => setCloseConfirmOpen(false)}
                      >
                        Annuleren
                      </button>
                      <button className="danger-button" type="button" disabled={interactionLocked} onClick={() => void closeWithoutDeployment()}>
                        {closing ? <Loader2 className="spin" size={16} /> : null}
                        Aanvraag afsluiten
                      </button>
                    </div>
                    {closeError ? <p className="form-error" role="alert">{closeError}</p> : null}
                  </div>
                ) : null}
              </section>
            </>
          ) : null}

          {draft.deployment_id && !compact ? (
            <Link className="primary-button deployment-request-deployment-link" href={`/inzetten/${draft.deployment_id}`}>
              Naar gekoppelde inzet <ArrowRight size={17} />
            </Link>
          ) : null}
        </aside>
      </div>
    </div>
  );
});

function QuestionSection(props: {
  number: string;
  title: string;
  description: string;
  leadingContent?: ReactNode;
  fields: DeploymentRequestWorkflowField[];
  answers: Record<string, unknown>;
  bindings: Map<string, DeploymentRequestWorkflowBinding>;
  disabled: boolean;
  onChange: (key: string, value: unknown | null) => void;
}) {
  const {
    number,
    title,
    description,
    leadingContent,
    fields,
    answers,
    bindings,
    disabled,
    onChange,
  } = props;

  return (
    <section className="deployment-request-questionnaire__section">
      <header>
        <span>{number}</span>
        <div>
          <h3>{title}</h3>
          <p>{description}</p>
        </div>
      </header>
      <div className="deployment-request-field-grid">
        {leadingContent}
        {fields.map((field) => (
          field.type === 'section' ? (
            <div className="deployment-request-field-section" key={field.key}>
              <h4>{field.label}</h4>
              {field.help_text ? <p>{field.help_text}</p> : null}
            </div>
          ) : (
            <DeploymentRequestField
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

function DeploymentRequestField(props: {
  field: DeploymentRequestWorkflowField;
  value: unknown;
  binding?: DeploymentRequestWorkflowBinding;
  disabled: boolean;
  onChange: (value: unknown | null) => void;
}) {
  const { field, value, binding, disabled, onChange } = props;
  const fieldId = `deployment-request-field-${field.key}`;
  const label = (
    <span className="deployment-request-field__label">
      <span>{field.label}{field.required ? ' *' : ''}</span>
      {binding ? <small><ChevronRight size={13} /> Naar {bindingLabel(binding.target)}</small> : null}
    </span>
  );
  const className = `deployment-request-field${field.type === 'textarea' || field.type === 'radio' ? ' deployment-request-field--wide' : ''}`;

  if (field.type === 'address') {
    const helpId = field.help_text ? `${fieldId}-help` : undefined;

    return (
      <div className={className}>
        <label htmlFor={fieldId}>{label}</label>
        <AddressAutocomplete
          id={fieldId}
          value={asInputString(value)}
          disabled={disabled}
          required={field.required}
          describedBy={helpId}
          onChange={(nextValue) => onChange(nextValue === '' ? null : nextValue)}
        />
        {field.help_text ? <small id={helpId}>{field.help_text}</small> : null}
      </div>
    );
  }

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
      <fieldset className={`${className} deployment-request-radio-field`} id={fieldId}>
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
    const selected = deploymentRequestBooleanChoice(value);
    return (
      <fieldset className={`${className} deployment-request-radio-field deployment-request-boolean-field`} id={fieldId}>
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
  option: DeploymentRequestFieldOption;
  selected: boolean;
  disabled: boolean;
  onSelect: () => void;
}) {
  return (
    <label className={props.selected ? 'deployment-request-choice deployment-request-choice--selected' : 'deployment-request-choice'}>
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

function AssessmentCard({
  deploymentRequest,
  completeness,
}: {
  deploymentRequest: DeploymentRequest;
  completeness: number | null;
}) {
  return (
    <section className="deployment-request-assessment-card deployment-request-assessment-card--primary">
      <header>
        <div>
          <span className="deployment-request-workspace__eyebrow">Live beoordeling</span>
          <h3>Prioriteitsadvies</h3>
        </div>
        <StatusPill
          value={deploymentRequestPriorityLabel(deploymentRequest.triage.recommended_priority)}
          tone={deploymentRequestPriorityTone(deploymentRequest.triage.recommended_priority)}
        />
      </header>
      {completeness !== null ? (
        <div className="deployment-request-completeness">
          <div><span>Volledigheid</span><strong>{completeness}%</strong></div>
          <progress max="100" value={completeness}>{completeness}%</progress>
        </div>
      ) : null}
      {deploymentRequest.triage.reasons.length > 0 ? (
        <ul className="deployment-request-reason-list">
          {deploymentRequest.triage.reasons.map((reason) => <li key={reason}><CheckCircle2 size={15} /> {reason}</li>)}
        </ul>
      ) : (
        <p className="deployment-request-assessment-card__empty">
          {deploymentRequest.triage.state === 'incomplete'
            ? 'Vul de ontbrekende kerngegevens in om een advies te berekenen.'
            : 'Er is nog geen verklaarbaar prioriteitsadvies.'}
        </p>
      )}
      {deploymentRequest.triage.missing_fields.length > 0 ? (
        <div className="deployment-request-missing-fields">
          <strong>Nog nodig</strong>
          <div>
            {deploymentRequest.triage.missing_fields.map((field) => (
              <button
                type="button"
                key={field.key}
                onClick={() => focusDeploymentRequestField(field.key)}
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

function focusDeploymentRequestField(fieldKey: string) {
  const target = document.getElementById(`deployment-request-field-${fieldKey}`);
  if (target instanceof HTMLFieldSetElement) {
    target.querySelector<HTMLInputElement>('input:not(:disabled)')?.focus();
    return;
  }
  target?.focus();
}

function DecisionCard(props: {
  deploymentRequest: DeploymentRequest;
  priority: DeploymentRequestPriority | null;
  reason: string;
  canOverride: boolean;
  overrideRequired: boolean;
  blocked: boolean;
  saving: boolean;
  disabled: boolean;
  error: string | null;
  onPriorityChange: (priority: DeploymentRequestPriority) => void;
  onReasonChange: (reason: string) => void;
  onSave: () => void;
}) {
  const advised = props.deploymentRequest.triage.recommended_priority;

  return (
    <section className="deployment-request-assessment-card">
      <header>
        <div>
          <span className="deployment-request-workspace__eyebrow">Besluit centralist</span>
          <h3>Vastgestelde prioriteit</h3>
        </div>
        {props.deploymentRequest.decided_priority ? (
          <StatusPill
            value={deploymentRequestPriorityLabel(props.deploymentRequest.decided_priority)}
            tone={deploymentRequestPriorityTone(props.deploymentRequest.decided_priority)}
          />
        ) : null}
      </header>
      <div className="deployment-request-priority-grid" role="radiogroup" aria-label="Vastgestelde prioriteit">
        {deploymentRequestPriorityOptions.map((option) => {
          const isOverride = advised === null || option.value !== advised;
          return (
            <label
              className={`deployment-request-priority-option deployment-request-priority-option--${option.value}${props.priority === option.value ? ' deployment-request-priority-option--selected' : ''}`}
              key={option.value}
            >
              <input
                type="radio"
                name={`priority-${props.deploymentRequest.id}`}
                value={option.value}
                checked={props.priority === option.value}
                disabled={props.disabled || props.blocked || (isOverride && !props.canOverride)}
                onChange={() => props.onPriorityChange(option.value)}
              />
              <span>{option.label}</span>
              {option.value === advised ? <small>Advies</small> : null}
            </label>
          );
        })}
      </div>
      {props.blocked ? (
        <p className="deployment-request-blocked-notice">
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
            disabled={props.disabled}
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
  notes: string;
}

type DeploymentFormField = keyof DeploymentForm;

function DeploymentCard(props: {
  proposal: DeploymentProposal | null;
  selected: DeploymentProposal | null;
  teams: Team[];
  canOverride: boolean;
  disabled: boolean;
  draft: DeploymentForm;
  onChange: (draft: DeploymentForm, changedField: DeploymentFormField) => void;
}) {
  const {
    proposal,
    selected,
    teams,
    canOverride,
    disabled,
    draft,
    onChange,
  } = props;
  const shown = selected ?? proposal;

  return (
    <section className="deployment-request-assessment-card">
      <header>
        <div>
          <span className="deployment-request-workspace__eyebrow">Voorstel</span>
          <h3>Inzet</h3>
        </div>
      </header>
      {shown ? (
        <>
          <div className="deployment-request-deployment-proposal">
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
                <dt>Teams</dt>
                <dd>{shown.teams.map((team) => team.name).join(', ') || 'Niet bepaald'}</dd>
              </div>
            </dl>
            <small>Het geadviseerde voorstel en de bijbehorende teams zijn vooraf geselecteerd. Alarmeren blijft een aparte handmatige actie.</small>
          </div>
          <fieldset className="deployment-request-team-choice" disabled={disabled || !canOverride}>
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
                  }, 'teamIds')}
                />
                <span>{team.code} · {team.name}</span>
              </label>
            ))}
          </fieldset>
          <div className="deployment-request-deployment-routing">
            <label>
              Aanbevolen aantal ontvangers
              <input
                type="number"
                min="1"
                max="200"
                value={draft.recipientCount ?? ''}
                disabled={disabled || !canOverride}
                onChange={(event) => onChange({
                  ...draft,
                  recipientCount: event.target.value === '' ? null : Number(event.target.value),
                }, 'recipientCount')}
              />
            </label>
            <label>
              Adviesroute
              <select
                value={draft.dispatchMode ?? ''}
                disabled={disabled || !canOverride}
                onChange={(event) => onChange({
                  ...draft,
                  dispatchMode: event.target.value === ''
                    ? null
                    : event.target.value as DeploymentForm['dispatchMode'],
                }, 'dispatchMode')}
              >
                <option value="">Niet bepaald</option>
                <option value="preannouncement">Voorwaarschuwing</option>
                <option value="direct_dispatch">Directe alarmering</option>
              </select>
            </label>
          </div>
          <label>
            Benodigde middelen
            <textarea
              value={draft.resources.join('\n')}
              disabled={disabled || !canOverride}
              placeholder="Eén middel per regel"
              onChange={(event) => onChange({
                ...draft,
                resources: event.target.value.split('\n').map((value) => value.trim()).filter(Boolean),
              }, 'resources')}
            />
          </label>
          <label>
            Toelichting inzet
            <textarea
              value={draft.notes}
              disabled={disabled || !canOverride}
              maxLength={1000}
              onChange={(event) => onChange({ ...draft, notes: event.target.value }, 'notes')}
            />
          </label>
          {!canOverride ? <small>Afwijken van het inzetvoorstel vereist aanvullende rechten.</small> : null}
        </>
      ) : (
        <p className="deployment-request-assessment-card__empty">Er is nog geen inzetvoorstel. Vul eerst de ontbrekende gegevens aan.</p>
      )}
    </section>
  );
}

function ReadonlyDeploymentRequest(props: {
  deploymentRequest: DeploymentRequest;
  canRefresh: boolean;
  onRefresh: () => Promise<void>;
}) {
  return (
    <div className="deployment-request-readonly">
      <header>
        <div>
          <span className="deployment-request-workspace__eyebrow">Aanvraag · {props.deploymentRequest.subject_type_label}</span>
          <h3>{props.deploymentRequest.title}</h3>
          <span>Bijgewerkt {formatDateTime(props.deploymentRequest.updated_at)}</span>
        </div>
        <div>
          <StatusPill
            value={deploymentRequestPriorityLabel(
              props.deploymentRequest.decided_priority
                ?? props.deploymentRequest.triage.recommended_priority,
            )}
            tone={deploymentRequestPriorityTone(
              props.deploymentRequest.decided_priority
                ?? props.deploymentRequest.triage.recommended_priority,
            )}
          />
          {props.canRefresh ? (
            <button className="icon-button" type="button" aria-label="Uitvraag vernieuwen" onClick={() => void props.onRefresh()}>
              <RefreshCcw size={16} />
            </button>
          ) : null}
        </div>
      </header>
      {props.deploymentRequest.answer_rows.length > 0 ? (
        <dl className="deployment-request-readonly__answers">
          {props.deploymentRequest.answer_rows.map((answer) => (
            <div key={answer.key}>
              <dt>{answer.label}</dt>
              <dd>{answer.display_value || '-'}</dd>
            </div>
          ))}
        </dl>
      ) : (
        <div className="empty-panel">Nog geen uitvraaggegevens vastgelegd.</div>
      )}
      {props.deploymentRequest.deployment_id ? (
        <Link className="secondary-button" href={`/inzetten/${props.deploymentRequest.deployment_id}`}>
          Naar gekoppelde inzet <ArrowRight size={16} />
        </Link>
      ) : null}
    </div>
  );
}

function SaveIndicator({ state }: { state: DeploymentRequestSaveState }) {
  const icon: ReactNode = state === 'saving'
    ? <Loader2 className="spin" size={15} />
    : state === 'offline'
      ? <CloudOff size={15} />
      : state === 'conflict' || state === 'error'
        ? <AlertTriangle size={15} />
        : <Check size={15} />;

  return (
    <span className={`deployment-request-save-state deployment-request-save-state--${state}`} role="status" aria-live="polite">
      {icon}
      {deploymentRequestSaveLabel(state)}
    </span>
  );
}

function deploymentFormFromRequest(deploymentRequest: DeploymentRequest): DeploymentForm {
  const proposal = deploymentRequest.selected_deployment_proposal
    ?? deploymentRequest.deployment_proposal;
  return {
    teamIds: proposal?.team_ids ?? [],
    resources: proposal?.resources ?? [],
    recipientCount: proposal?.recommended_recipient_count ?? null,
    dispatchMode: proposal?.recommended_dispatch_mode ?? null,
    notes: proposal?.notes ?? '',
  };
}

function rebaseDeploymentForm(
  local: DeploymentForm,
  baseline: DeploymentForm,
  server: DeploymentForm,
  dirtyFields: Set<DeploymentFormField>,
): DeploymentForm {
  return {
    teamIds: dirtyFields.has('teamIds')
      ? rebaseDeploymentRequestTeamIds(local.teamIds, baseline.teamIds, server.teamIds)
      : server.teamIds,
    resources: dirtyFields.has('resources') ? local.resources : server.resources,
    recipientCount: dirtyFields.has('recipientCount')
      ? local.recipientCount
      : server.recipientCount,
    dispatchMode: dirtyFields.has('dispatchMode') ? local.dispatchMode : server.dispatchMode,
    notes: dirtyFields.has('notes') ? local.notes : server.notes,
  };
}

function deploymentFormDirtyFields(
  draft: DeploymentForm,
  baseline: DeploymentForm,
): Set<DeploymentFormField> {
  const fields: DeploymentFormField[] = [
    'teamIds',
    'resources',
    'recipientCount',
    'dispatchMode',
    'notes',
  ];

  return new Set(fields.filter((field) => !deploymentFormFieldEquals(draft, baseline, field)));
}

function deploymentFormFieldEquals(
  left: DeploymentForm,
  right: DeploymentForm,
  field: DeploymentFormField,
): boolean {
  switch (field) {
    case 'teamIds':
      return sameStringSet(left.teamIds, right.teamIds);
    case 'resources':
      return sameStringList(left.resources, right.resources);
    case 'recipientCount':
      return left.recipientCount === right.recipientCount;
    case 'dispatchMode':
      return left.dispatchMode === right.dispatchMode;
    case 'notes':
      return left.notes.trim() === right.notes.trim();
  }
}

function deploymentDiffers(draft: DeploymentForm, proposal: DeploymentProposal | null): boolean {
  if (proposal === null) {
    return draft.teamIds.length > 0
      || draft.resources.length > 0
      || draft.recipientCount !== null
      || draft.dispatchMode !== null
      || draft.notes.trim() !== '';
  }
  return !sameStringSet(draft.teamIds, proposal.team_ids)
    || !sameStringList(draft.resources, proposal.resources)
    || draft.recipientCount !== proposal.recommended_recipient_count
    || draft.dispatchMode !== proposal.recommended_dispatch_mode
    || draft.notes.trim() !== (proposal.notes ?? '').trim();
}

function DeploymentSnapshotCard({ proposal }: { proposal: DeploymentProposal | null }) {
  if (proposal === null) return null;

  return (
    <section className="deployment-request-assessment-card">
      <header>
        <div>
          <span className="deployment-request-workspace__eyebrow">Vastgelegde inzet</span>
          <h3>{proposal.label}</h3>
        </div>
      </header>
      <p className="deployment-request-assessment-card__empty">{proposal.summary}</p>
      <dl className="deployment-request-snapshot-facts">
        <div><dt>Ontvangers</dt><dd>{proposal.recommended_recipient_count ?? 'Niet bepaald'}</dd></div>
        <div><dt>Adviesroute</dt><dd>{dispatchModeLabel(proposal.recommended_dispatch_mode)}</dd></div>
        <div><dt>Teams</dt><dd>{proposal.teams.map((team) => team.name).join(', ') || 'Geen'}</dd></div>
        <div><dt>Middelen</dt><dd>{proposal.resources.join(', ') || 'Geen'}</dd></div>
      </dl>
      <small>Dit is een advies/snapshot en start geen alarmering.</small>
    </section>
  );
}

function dispatchModeLabel(mode: DeploymentProposal['recommended_dispatch_mode']): string {
  if (mode === 'preannouncement') return 'Voorwaarschuwing';
  if (mode === 'direct_dispatch') return 'Directe alarmering';
  return 'Niet bepaald';
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
