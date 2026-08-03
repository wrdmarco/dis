import {
  ArrowDown,
  ArrowUp,
  CheckCircle2,
  ClipboardCheck,
  GitBranch,
  History,
  Link2,
  ListChecks,
  Plus,
  Rocket,
  Save,
  ShieldCheck,
  Trash2,
} from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { useConfirmDialog } from '../../components/ConfirmDialogContext';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import {
  browserNavigationController,
  type InterceptableNavigationEvent,
} from '../../lib/browserNavigation';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type { DeploymentSubjectType } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import styles from './DeploymentRequestWorkflowStudio.module.css';
import {
  availableBindingTargets,
  bindingTypesCompatible,
  conditionOperatorNeedsValue,
  conditionOperatorsForField,
  configurationEquals,
  createWorkflowOption,
  createWorkflowDeploymentProfile,
  createWorkflowField,
  createWorkflowPriorityRule,
  createWorkflowPriorityRuleForSubject,
  defaultConditionOperator,
  defaultConditionValue,
  fieldTypeLabel,
  fieldsForScope,
  deploymentRequestWorkflowFieldTypes,
  deploymentRequestWorkflowDateTimeIsoValue,
  deploymentRequestWorkflowDateTimeLocalValue,
  deploymentRequestWorkflowPriorities,
  deploymentRequestWorkflowScopes,
  linesToResources,
  moveWorkflowItem,
  moveWorkflowPriorityRuleForSubject,
  normalizeRuleForSubjects,
  optionsFromLines,
  priorityLabel,
  removeWorkflowField,
  ruleSafeFields,
  scopeLabel,
  updateWorkflowBinding,
  updateWorkflowDeploymentProfile,
  updateWorkflowOptionLabel,
  workflowPriorityRulesForSubject,
  type DeploymentRequestWorkflowAdminEnvelope,
  type DeploymentRequestWorkflowCondition,
  type DeploymentRequestWorkflowConfiguration,
  type DeploymentRequestWorkflowDeploymentProfile,
  type DeploymentRequestWorkflowField,
  type DeploymentRequestWorkflowFieldType,
  type DeploymentRequestWorkflowOption,
  type DeploymentRequestWorkflowPriority,
  type DeploymentRequestWorkflowPriorityRule,
  type DeploymentRequestWorkflowRevision,
  type DeploymentRequestWorkflowScope,
  type DeploymentRequestWorkflowSimulationResult,
  type DeploymentRequestWorkflowValidationResult,
} from './deploymentRequestWorkflow';

type StudioTab = 'questions' | 'bindings' | 'rules' | 'profiles' | 'versions';
export type DeploymentRequestWorkflowStudioMode = 'full' | 'decisions';

const studioTabs: Array<{
  id: StudioTab;
  label: string;
  description: string;
  icon: typeof ClipboardCheck;
}> = [
  { id: 'questions', label: 'Uitvraag', description: 'Velden per onderwerp', icon: ClipboardCheck },
  { id: 'bindings', label: 'Koppelingen', description: 'Eenmalig invullen', icon: Link2 },
  { id: 'rules', label: 'Prioriteitsregels', description: 'Advies en uitleg', icon: GitBranch },
  { id: 'profiles', label: 'Inzetvoorstellen', description: 'Teams en middelen', icon: ListChecks },
  { id: 'versions', label: 'Versies & testen', description: 'Valideren en publiceren', icon: History },
];

export function DeploymentRequestWorkflowStudio({
  onDirtyChange,
  mode = 'full',
}: {
  onDirtyChange?: (dirty: boolean) => void;
  mode?: DeploymentRequestWorkflowStudioMode;
}) {
  const { api, hasPermission } = useAuth();
  const router = useRouter();
  const confirmAction = useConfirmDialog();
  const canManageForms = hasPermission('forms.manage');
  const workflow = useApiResource<DeploymentRequestWorkflowAdminEnvelope>(
    '/admin/deployment-request-workflow/config',
    canManageForms,
  );
  const [configuration, setConfiguration] = useState<DeploymentRequestWorkflowConfiguration | null>(null);
  const [activeTab, setActiveTab] = useState<StudioTab>(mode === 'decisions' ? 'rules' : 'questions');
  const [saving, setSaving] = useState(false);
  const [validating, setValidating] = useState(false);
  const [publishing, setPublishing] = useState(false);
  const [restoringId, setRestoringId] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [conflictRevision, setConflictRevision] = useState<DeploymentRequestWorkflowRevision | null>(null);
  const skipNextWorkflowHydration = useRef(false);
  const allowNavigationRef = useRef(false);
  const navigationConfirmationPendingRef = useRef(false);

  useEffect(() => {
    if (workflow.data === null) {
      return;
    }
    if (skipNextWorkflowHydration.current) {
      skipNextWorkflowHydration.current = false;
      return;
    }

    setConfiguration(cloneConfiguration(workflow.data.draft.configuration));
  }, [workflow.data]);

  const dirty = configuration !== null
    && workflow.data !== null
    && !configurationEquals(configuration, workflow.data.draft.configuration);
  const hasConflict = error !== null && error.startsWith('Een andere beheerder');
  const busy = saving || validating || publishing || restoringId !== null || hasConflict;
  const visibleStudioTabs = mode === 'decisions'
    ? studioTabs.filter((tab) => ['rules', 'profiles', 'versions'].includes(tab.id))
    : studioTabs.filter((tab) => ['questions', 'bindings', 'versions'].includes(tab.id));
  const workflowName = mode === 'decisions' ? 'prioriteitsbesluiten' : 'uitvraag';

  useEffect(() => {
    onDirtyChange?.(dirty);
  }, [dirty, onDirtyChange]);

  useEffect(() => () => onDirtyChange?.(false), [onDirtyChange]);

  useEffect(() => {
    const warnBeforeUnload = (event: BeforeUnloadEvent) => {
      if (!dirty || allowNavigationRef.current) return;
      event.preventDefault();
      event.returnValue = '';
    };
    const confirmInternalNavigation = (event: MouseEvent) => {
      if (
        !dirty
        || event.defaultPrevented
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
      ) {
        return;
      }
      const destination = new URL(anchor.href, window.location.href);
      if (
        destination.pathname === window.location.pathname
        && destination.search === window.location.search
      ) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      if (navigationConfirmationPendingRef.current) {
        return;
      }

      navigationConfirmationPendingRef.current = true;
      void confirmAction({
        title: 'Pagina verlaten?',
        message: `Er zijn niet-opgeslagen wijzigingen in de ${workflowName}. Deze wijzigingen gaan verloren wanneer je deze pagina verlaat.`,
        confirmLabel: 'Pagina verlaten',
        intent: 'warning',
      }).then((confirmed) => {
        navigationConfirmationPendingRef.current = false;
        if (!confirmed) {
          return;
        }

        allowNavigationRef.current = true;
        window.setTimeout(() => {
          allowNavigationRef.current = false;
        }, 1_500);
        if (destination.origin === window.location.origin) {
          router.push(`${destination.pathname}${destination.search}${destination.hash}`);
        } else {
          window.location.assign(destination.href);
        }
      });
    };
    const confirmHistoryNavigation = (event: InterceptableNavigationEvent) => {
      if (
        !dirty
        || allowNavigationRef.current
        || !event.canIntercept
        || !event.cancelable
        || event.downloadRequest !== null
        || event.formData !== null
        || event.hashChange
      ) {
        return;
      }

      const destination = new URL(event.destination.url, window.location.href);
      if (
        destination.pathname === window.location.pathname
        && destination.search === window.location.search
      ) {
        return;
      }

      event.intercept({
        precommitHandler: async () => {
          const confirmed = await confirmAction({
            title: 'Pagina verlaten?',
            message: `Er zijn niet-opgeslagen wijzigingen in de ${workflowName}. Deze wijzigingen gaan verloren wanneer je deze pagina verlaat.`,
            confirmLabel: 'Pagina verlaten',
            intent: 'warning',
          });
          if (!confirmed) {
            throw new DOMException('Navigatie geannuleerd vanwege niet-opgeslagen wijzigingen.', 'AbortError');
          }
        },
      });
    };
    const navigation = browserNavigationController();

    window.addEventListener('beforeunload', warnBeforeUnload);
    document.addEventListener('click', confirmInternalNavigation, true);
    navigation?.addEventListener('navigate', confirmHistoryNavigation);
    return () => {
      window.removeEventListener('beforeunload', warnBeforeUnload);
      document.removeEventListener('click', confirmInternalNavigation, true);
      navigation?.removeEventListener('navigate', confirmHistoryNavigation);
    };
  }, [confirmAction, dirty, router, workflowName]);

  function applyEnvelope(envelope: DeploymentRequestWorkflowAdminEnvelope, successMessage: string) {
    skipNextWorkflowHydration.current = true;
    workflow.mutate(envelope);
    setConfiguration(cloneConfiguration(envelope.draft.configuration));
    setMessage(successMessage);
    setError(null);
    setConflictRevision(null);
  }

  function actionFailed(actionError: unknown, fallback: string) {
    if (actionError instanceof ApiClientError && actionError.status === 409) {
      setConflictRevision(readConflictRevision(actionError.details));
      setError('Een andere beheerder heeft het concept gewijzigd. Je lokale wijzigingen zijn bewaard; kies bewust welke versie je wilt gebruiken.');
      setMessage(null);
      return;
    }

    setError(actionError instanceof ApiClientError ? actionError.message : fallback);
    setMessage(null);
  }

  async function saveDraft() {
    if (configuration === null || workflow.data === null) {
      return;
    }

    setSaving(true);
    setError(null);
    setMessage(null);
    try {
      const response = await api.patch<DeploymentRequestWorkflowAdminEnvelope>('/admin/deployment-request-workflow/draft', {
        expected_revision: workflow.data.draft.lock_version,
        configuration,
      });
      applyEnvelope(response.data, 'Concept opgeslagen.');
    } catch (actionError) {
      actionFailed(actionError, 'Concept opslaan mislukt.');
    } finally {
      setSaving(false);
    }
  }

  async function validateDraft() {
    if (configuration === null || workflow.data === null) {
      return;
    }

    setValidating(true);
    setError(null);
    setMessage(null);
    try {
      const response = await api.post<DeploymentRequestWorkflowValidationResult>('/admin/deployment-request-workflow/validate', {
        expected_revision: workflow.data.draft.lock_version,
        configuration,
      });
      setConfiguration(cloneConfiguration(response.data.configuration));
      setMessage('Concept is geldig. Eventuele normalisaties staan klaar om op te slaan.');
    } catch (actionError) {
      actionFailed(actionError, 'Concept valideren mislukt.');
    } finally {
      setValidating(false);
    }
  }

  async function publishDraft() {
    if (workflow.data === null || dirty || !await confirmAction({
      title: 'Conceptversie publiceren?',
      message: 'Nieuwe aanvragen gebruiken na publicatie direct deze configuratie.',
      confirmLabel: 'Concept publiceren',
      intent: 'default',
    })) {
      return;
    }

    setPublishing(true);
    setError(null);
    setMessage(null);
    try {
      const response = await api.post<DeploymentRequestWorkflowAdminEnvelope>('/admin/deployment-request-workflow/publish', {
        expected_revision: workflow.data.draft.lock_version,
      });
      const publishedVersion = response.data.published?.version;
      applyEnvelope(
        response.data,
        publishedVersion === null || publishedVersion === undefined
          ? 'Concept is gepubliceerd.'
          : `Versie ${publishedVersion} is gepubliceerd.`,
      );
    } catch (actionError) {
      actionFailed(actionError, 'Concept publiceren mislukt.');
    } finally {
      setPublishing(false);
    }
  }

  async function restoreRevision(revision: DeploymentRequestWorkflowRevision) {
    if (workflow.data === null) {
      return;
    }
    if (dirty) {
      setError('Sla je lokale wijzigingen eerst op of herlaad de pagina voordat je een oude versie als concept terugzet.');
      setMessage(null);
      return;
    }
    if (!await confirmAction({
      title: `Versie ${revision.version} terugzetten?`,
      message: 'Deze versie wordt het nieuwe concept. De gepubliceerde versie blijft actief.',
      confirmLabel: 'Als concept terugzetten',
      intent: 'warning',
    })) {
      return;
    }

    setRestoringId(revision.id);
    setError(null);
    setMessage(null);
    try {
      const response = await api.post<DeploymentRequestWorkflowAdminEnvelope>('/admin/deployment-request-workflow/restore', {
        published_revision_id: revision.id,
        expected_revision: workflow.data.draft.lock_version,
      });
      applyEnvelope(response.data, `Versie ${revision.version} staat klaar als concept.`);
    } catch (actionError) {
      actionFailed(actionError, 'Versie terugzetten mislukt.');
    } finally {
      setRestoringId(null);
    }
  }

  async function useServerConflictRevision() {
    if (workflow.data === null || conflictRevision === null || !await confirmAction({
      title: 'Serverconcept gebruiken?',
      message: 'Je lokale wijzigingen worden verworpen en het nieuwste serverconcept wordt geladen.',
      confirmLabel: 'Lokale wijzigingen verwerpen',
      intent: 'danger',
    })) {
      return;
    }

    skipNextWorkflowHydration.current = true;
    workflow.mutate({ ...workflow.data, draft: conflictRevision });
    setConfiguration(cloneConfiguration(conflictRevision.configuration));
    setConflictRevision(null);
    setError(null);
    setMessage('Nieuwste serverconcept geladen.');
  }

  async function keepLocalConflictRevision() {
    if (workflow.data === null || conflictRevision === null || !await confirmAction({
      title: 'Lokale configuratie behouden?',
      message: 'Je lokale configuratie wordt op de nieuwste serverrevisie geplaatst. Controleer de wijzigingen en kies daarna opnieuw Concept opslaan.',
      confirmLabel: 'Lokale versie behouden',
      intent: 'warning',
    })) {
      return;
    }

    skipNextWorkflowHydration.current = true;
    workflow.mutate({ ...workflow.data, draft: conflictRevision });
    setConflictRevision(null);
    setError(null);
    setMessage('Lokale configuratie behouden. Controleer en sla opnieuw op om de serverversie te vervangen.');
  }

  async function reloadAfterUnknownConflict() {
    if (!await confirmAction({
      title: 'Serverconcept opnieuw laden?',
      message: 'Je lokale wijzigingen worden verworpen en het nieuwste serverconcept wordt opgehaald.',
      confirmLabel: 'Lokale wijzigingen verwerpen',
      intent: 'danger',
    })) {
      return;
    }

    await workflow.reload();
    setConflictRevision(null);
    setError(null);
    setMessage('Nieuwste serverconcept geladen.');
  }

  return (
    <Panel title={mode === 'decisions' ? 'Prioriteitsbesluiten' : 'Aanvraagconfiguratie'}>
      <ResourceState loading={workflow.loading} error={workflow.error} empty={false}>
        {workflow.data !== null && configuration !== null ? (
          <div className={styles.studio}>
            <WorkflowHeader
              draft={workflow.data.draft}
              published={workflow.data.published}
              dirty={dirty}
              busy={busy}
              saving={saving}
              validating={validating}
              publishing={publishing}
              mode={mode}
              onSave={() => void saveDraft()}
              onValidate={() => void validateDraft()}
              onPublish={() => void publishDraft()}
            />

            {error !== null ? <p className={styles.error} role="alert">{error}</p> : null}
            {hasConflict ? (
              <div className={styles.conflictActions}>
                <strong>Conflictoplossing</strong>
                <span>Er wordt niets automatisch overschreven.</span>
                <div>
                  <button
                    className="secondary-button"
                    type="button"
                    onClick={conflictRevision === null ? () => void reloadAfterUnknownConflict() : useServerConflictRevision}
                  >
                    Serverconcept gebruiken
                  </button>
                  {conflictRevision !== null ? (
                    <button className="primary-button" type="button" onClick={keepLocalConflictRevision}>
                      Mijn lokale versie behouden
                    </button>
                  ) : null}
                </div>
              </div>
            ) : null}
            {message !== null ? <p className={styles.success} role="status">{message}</p> : null}

            <div
              className={`${styles.flow}${mode === 'decisions' ? ` ${styles.decisionFlow}` : ''}`}
              aria-label={mode === 'decisions' ? 'Prioriteitsbeheer' : 'Configuratiestroom'}
            >
              {visibleStudioTabs.map((tab, index) => {
                const Icon = tab.icon;
                return (
                  <button
                    type="button"
                    key={tab.id}
                    className={activeTab === tab.id ? styles.flowStepActive : styles.flowStep}
                    aria-current={activeTab === tab.id ? 'step' : undefined}
                    onClick={() => setActiveTab(tab.id)}
                  >
                    <span>{index + 1}</span>
                    <Icon size={18} aria-hidden="true" />
                    <strong>{tab.label}</strong>
                    <small>{tab.description}</small>
                  </button>
                );
              })}
            </div>

            {activeTab === 'questions' ? (
              <QuestionsPanel
                configuration={configuration}
                onChange={setConfiguration}
              />
            ) : null}
            {activeTab === 'bindings' ? (
              <BindingsPanel
                configuration={configuration}
                deploymentFields={workflow.data.catalogs.deployment_fields}
                onChange={setConfiguration}
              />
            ) : null}
            {activeTab === 'rules' ? (
              <RulesPanel
                configuration={configuration}
                operatorCatalog={workflow.data.catalogs.operators}
                decisionMode={mode === 'decisions'}
                onChange={setConfiguration}
              />
            ) : null}
            {activeTab === 'profiles' ? (
              <ProfilesPanel
                configuration={configuration}
                teams={workflow.data.catalogs.teams}
                certificationTypes={workflow.data.catalogs.certification_types}
                decisionMode={mode === 'decisions'}
                onChange={setConfiguration}
              />
            ) : null}
            {activeTab === 'versions' ? (
              <VersionsPanel
                configuration={configuration}
                envelope={workflow.data}
                dirty={dirty}
                restoringId={restoringId}
                onConflict={(actionError) => actionFailed(actionError, 'Simulatie uitvoeren mislukt.')}
                onRestore={(revision) => void restoreRevision(revision)}
              />
            ) : null}
          </div>
        ) : null}
      </ResourceState>
    </Panel>
  );
}

function WorkflowHeader(props: {
  draft: DeploymentRequestWorkflowRevision;
  published: DeploymentRequestWorkflowRevision | null;
  mode: DeploymentRequestWorkflowStudioMode;
  dirty: boolean;
  busy: boolean;
  saving: boolean;
  validating: boolean;
  publishing: boolean;
  onSave: () => void;
  onValidate: () => void;
  onPublish: () => void;
}) {
  const {
    draft,
    published,
    mode,
    dirty,
    busy,
    saving,
    validating,
    publishing,
    onSave,
    onValidate,
    onPublish,
  } = props;
  const decisionsMode = mode === 'decisions';

  return (
    <header className={styles.header}>
      <div>
        <span className={styles.eyebrow}>
          {decisionsMode ? 'Besluitmodel voor de aanvraag' : 'Doorlopende aanvraag'}
        </span>
        <h2>{decisionsMode ? 'Van aanvraag naar conceptinzet' : 'Van eerste uitvraag naar inzetadvies'}</h2>
        <p>
          {decisionsMode
            ? 'Beheer per aanvraagtype welke voorwaarden tot een prioriteitsadvies en inzetprofiel leiden. Publiceren activeert de volledige opgeslagen conceptconfiguratie, inclusief aanvraagvelden en koppelingen.'
            : 'Beheer één gegevensbron voor aanvraag en inzet. Publiceren activeert de configuratie; er wordt nooit automatisch gealarmeerd.'}
        </p>
      </div>
      <div className={styles.headerMeta}>
        <span className={dirty ? styles.dirtyPill : styles.savedPill}>
          {dirty ? 'Niet-opgeslagen wijzigingen' : `Concept · revisie ${draft.lock_version}`}
        </span>
        <span className={styles.publishedPill}>
          {published === null ? 'Nog niet gepubliceerd' : `Actief: versie ${published.version ?? 'onbekend'}`}
        </span>
      </div>
      <div className={styles.headerActions}>
        <button className="secondary-button" type="button" onClick={onValidate} disabled={busy}>
          <ShieldCheck size={17} aria-hidden="true" />
          {validating ? 'Valideren…' : 'Valideren'}
        </button>
        <button className="secondary-button" type="button" onClick={onSave} disabled={busy || !dirty}>
          <Save size={17} aria-hidden="true" />
          {saving ? 'Opslaan…' : 'Concept opslaan'}
        </button>
        <button className="primary-button" type="button" onClick={onPublish} disabled={busy || dirty}>
          <Rocket size={17} aria-hidden="true" />
          {publishing ? 'Publiceren…' : 'Publiceren'}
        </button>
      </div>
    </header>
  );
}

function QuestionsPanel(props: {
  configuration: DeploymentRequestWorkflowConfiguration;
  onChange: (configuration: DeploymentRequestWorkflowConfiguration) => void;
}) {
  const { configuration, onChange } = props;
  const confirmAction = useConfirmDialog();
  const [scope, setScope] = useState<DeploymentRequestWorkflowScope>('common');
  const [newType, setNewType] = useState<DeploymentRequestWorkflowFieldType>('text');
  const scopedFields = fieldsForScope(configuration.fields, scope);

  function updateField(fieldKey: string, changes: Partial<DeploymentRequestWorkflowField>) {
    const nextFields = configuration.fields.map((field) => field.key === fieldKey
      ? normalizeField({ ...field, ...changes })
      : field);
    const nextConfiguration = {
      ...configuration,
      fields: nextFields,
      priority_rules: configuration.priority_rules.map((rule) => normalizeRuleForSubjects(rule, nextFields)),
    };
    onChange(nextConfiguration);
  }

  function addField() {
    onChange({
      ...configuration,
      fields: [...configuration.fields, createWorkflowField(configuration.fields, scope, newType)],
    });
  }

  async function removeField(field: DeploymentRequestWorkflowField) {
    const referenced = configuration.bindings.some((binding) => binding.field_key === field.key)
      || configuration.priority_rules.some((rule) => rule.conditions.some((condition) => condition.field_key === field.key));
    const warning = referenced
      ? `“${field.label}” wordt ook uit koppelingen en prioriteitsregels verwijderd.`
      : `Je verwijdert “${field.label}” uit de uitvraag.`;

    if (!await confirmAction({
      title: 'Uitvraagveld verwijderen?',
      message: warning,
      confirmLabel: 'Veld verwijderen',
      intent: 'danger',
    })) {
      return;
    }

    onChange(removeWorkflowField(configuration, field.key));
  }

  async function removeOption(field: DeploymentRequestWorkflowField, option: DeploymentRequestWorkflowOption) {
    const referenced = configuration.priority_rules.some((rule) => rule.conditions.some(
      (condition) => condition.field_key === field.key && condition.value === option.value,
    ));
    if (
      referenced
      && !await confirmAction({
        title: 'Keuze verwijderen?',
        message: `“${option.label}” wordt ook uit de gekoppelde prioriteitsvoorwaarden verwijderd.`,
        confirmLabel: 'Keuze verwijderen',
        intent: 'danger',
      })
    ) {
      return;
    }

    updateField(field.key, {
      options: field.options.filter((candidate) => candidate.value !== option.value),
    });
  }

  function moveField(field: DeploymentRequestWorkflowField, direction: -1 | 1) {
    const scopeIndexes = configuration.fields
      .map((candidate, index) => candidate.scope === field.scope ? index : -1)
      .filter((index) => index >= 0);
    const scopedIndex = scopedFields.findIndex((candidate) => candidate.key === field.key);
    const targetScopedIndex = scopedIndex + direction;
    if (targetScopedIndex < 0 || targetScopedIndex >= scopeIndexes.length) {
      return;
    }

    const next = [...configuration.fields];
    const currentIndex = scopeIndexes[scopedIndex];
    const targetIndex = scopeIndexes[targetScopedIndex];
    [next[currentIndex], next[targetIndex]] = [next[targetIndex], next[currentIndex]];
    onChange({ ...configuration, fields: next });
  }

  return (
    <section className={styles.workspace} aria-labelledby="workflow-questions-title">
      <div className={styles.sectionHeading}>
        <div>
          <span className={styles.eyebrow}>Gegevensmodel</span>
          <h3 id="workflow-questions-title">Uitvraag per onderwerp</h3>
          <p>Gemeenschappelijke velden worden bij Mens, Dier en Object getoond. Een ander veld hoort bij exact één onderwerp.</p>
        </div>
        <div className={styles.addControl}>
          <label>
            Bouwblok
            <select value={newType} onChange={(event) => setNewType(event.target.value as DeploymentRequestWorkflowFieldType)}>
              {deploymentRequestWorkflowFieldTypes.map((type) => (
                <option key={type.value} value={type.value}>{type.label}</option>
              ))}
            </select>
          </label>
          <button className="secondary-button" type="button" onClick={addField}>
            <Plus size={17} aria-hidden="true" />
            Toevoegen
          </button>
        </div>
      </div>

      <div className={styles.scopeTabs} role="group" aria-label="Uitvraagonderwerpen">
        {deploymentRequestWorkflowScopes.map((item) => {
          const count = fieldsForScope(configuration.fields, item.value).length;
          return (
            <button
              key={item.value}
              type="button"
              aria-pressed={scope === item.value}
              className={scope === item.value ? styles.scopeTabActive : styles.scopeTab}
              onClick={() => setScope(item.value)}
            >
              <strong>{item.label}</strong>
              <span>{count}</span>
            </button>
          );
        })}
      </div>

      <div className={styles.cardList}>
        {scopedFields.length === 0 ? (
          <div className={styles.empty}>
            <strong>Nog geen velden voor {scopeLabel(scope).toLowerCase()}</strong>
            <span>Voeg hierboven het eerste bouwblok toe.</span>
          </div>
        ) : null}
        {scopedFields.map((field, index) => (
          <FieldCard
            key={field.key}
            field={field}
            first={index === 0}
            last={index === scopedFields.length - 1}
            onMove={(direction) => moveField(field, direction)}
            onRemove={() => void removeField(field)}
            onRemoveOption={(option) => void removeOption(field, option)}
            onUpdate={(changes) => updateField(field.key, changes)}
          />
        ))}
      </div>
    </section>
  );
}

function FieldCard(props: {
  field: DeploymentRequestWorkflowField;
  first: boolean;
  last: boolean;
  onMove: (direction: -1 | 1) => void;
  onRemove: () => void;
  onRemoveOption: (option: DeploymentRequestWorkflowOption) => void;
  onUpdate: (changes: Partial<DeploymentRequestWorkflowField>) => void;
}) {
  const { field, first, last, onMove, onRemove, onRemoveOption, onUpdate } = props;

  return (
    <details className={styles.editorCard}>
      <summary>
        <div>
          <strong>{field.label}</strong>
          <code>{field.key}</code>
        </div>
        <span className={styles.summaryMeta}>
          <span>{fieldTypeLabel(field.type)}</span>
          <span>{scopeLabel(field.scope)}</span>
          {field.operator_visible && field.type !== 'section' ? <span>Operator</span> : null}
        </span>
      </summary>
      <div className={styles.editorBody}>
        <div className={styles.fieldGrid}>
          <label>
            Label
            <input value={field.label} onChange={(event) => onUpdate({ label: event.target.value })} />
          </label>
          <label>
            Technische sleutel
            <input value={field.key} readOnly aria-describedby={`${field.key}-key-help`} />
            <span id={`${field.key}-key-help`}>Blijft gelijk wanneer het label wijzigt.</span>
          </label>
          <label>
            Type
            <select
              value={field.type}
              onChange={(event) => {
                const type = event.target.value as DeploymentRequestWorkflowFieldType;
                onUpdate({
                  type,
                  required: type === 'section' ? false : field.required,
                  operator_visible: type === 'section' ? false : field.operator_visible,
                  options: type === 'select' || type === 'radio'
                    ? (field.options.length > 0 ? field.options : optionsFromLines('Optie 1\nOptie 2'))
                    : [],
                });
              }}
            >
              {deploymentRequestWorkflowFieldTypes.map((type) => (
                <option key={type.value} value={type.value}>{type.label}</option>
              ))}
            </select>
          </label>
          <label>
            Onderwerp
            <select value={field.scope} onChange={(event) => onUpdate({ scope: event.target.value as DeploymentRequestWorkflowScope })}>
              {deploymentRequestWorkflowScopes.map((item) => (
                <option key={item.value} value={item.value}>{item.label}</option>
              ))}
            </select>
          </label>
        </div>
        {field.type !== 'section' ? (
          <>
            <label className={styles.fullField}>
              Toelichting voor centralist
              <textarea
                rows={2}
                value={field.help_text ?? ''}
                onChange={(event) => onUpdate({ help_text: event.target.value })}
              />
            </label>
            <div className={styles.checkRow}>
              <label>
                <input type="checkbox" checked={field.required} onChange={(event) => onUpdate({ required: event.target.checked })} />
                Verplicht
              </label>
              <label>
                <input
                  type="checkbox"
                  checked={field.operator_visible}
                  onChange={(event) => onUpdate({ operator_visible: event.target.checked })}
                />
                Tonen in Operator-app
              </label>
            </div>
          </>
        ) : null}
        {field.type === 'select' || field.type === 'radio' ? (
          <OptionsEditor field={field} onRemoveOption={onRemoveOption} onUpdate={onUpdate} />
        ) : null}
        <div className={styles.cardActions}>
          <button className="icon-button" type="button" disabled={first} onClick={() => onMove(-1)} aria-label={`${field.label} omhoog`}>
            <ArrowUp size={16} aria-hidden="true" />
          </button>
          <button className="icon-button" type="button" disabled={last} onClick={() => onMove(1)} aria-label={`${field.label} omlaag`}>
            <ArrowDown size={16} aria-hidden="true" />
          </button>
          <button className="danger-button" type="button" onClick={onRemove}>
            <Trash2 size={16} aria-hidden="true" />
            Verwijderen
          </button>
        </div>
      </div>
    </details>
  );
}

function OptionsEditor(props: {
  field: DeploymentRequestWorkflowField;
  onRemoveOption: (option: DeploymentRequestWorkflowOption) => void;
  onUpdate: (changes: Partial<DeploymentRequestWorkflowField>) => void;
}) {
  const { field, onRemoveOption, onUpdate } = props;

  return (
    <div className={styles.optionsEditor}>
      <div className={styles.blockHeading}>
        <div>
          <strong>Keuzeopties</strong>
          <span>De technische waarde blijft gelijk als je alleen het label wijzigt.</span>
        </div>
        <button
          className="secondary-button"
          type="button"
          onClick={() => onUpdate({
            options: [...field.options, createWorkflowOption(field.options)],
          })}
        >
          <Plus size={15} aria-hidden="true" />
          Optie toevoegen
        </button>
      </div>
      <div className={styles.optionList}>
        {field.options.map((option, index) => (
          <div className={styles.optionRow} key={option.value}>
            <label>
              Label
              <input
                value={option.label}
                maxLength={120}
                onChange={(event) => onUpdate({
                  options: updateWorkflowOptionLabel(field.options, option.value, event.target.value),
                })}
              />
            </label>
            <div className={styles.optionValue}>
              <span>Technische waarde</span>
              <code>{option.value}</code>
            </div>
            <div className={styles.optionActions}>
              <button
                className="icon-button"
                type="button"
                disabled={index === 0}
                onClick={() => onUpdate({ options: moveWorkflowItem(field.options, index, -1) })}
                aria-label={`${option.label} omhoog`}
              >
                <ArrowUp size={15} aria-hidden="true" />
              </button>
              <button
                className="icon-button"
                type="button"
                disabled={index === field.options.length - 1}
                onClick={() => onUpdate({ options: moveWorkflowItem(field.options, index, 1) })}
                aria-label={`${option.label} omlaag`}
              >
                <ArrowDown size={15} aria-hidden="true" />
              </button>
              <button
                className="icon-button"
                type="button"
                disabled={field.options.length <= 1}
                onClick={() => onRemoveOption(option)}
                aria-label={`${option.label} verwijderen`}
              >
                <Trash2 size={15} aria-hidden="true" />
              </button>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function BindingsPanel(props: {
  configuration: DeploymentRequestWorkflowConfiguration;
  deploymentFields: DeploymentRequestWorkflowAdminEnvelope['catalogs']['deployment_fields'];
  onChange: (configuration: DeploymentRequestWorkflowConfiguration) => void;
}) {
  const { configuration, deploymentFields, onChange } = props;
  const fields = configuration.fields.filter((field) => field.type !== 'section');

  return (
    <section className={styles.workspace} aria-labelledby="workflow-bindings-title">
      <div className={styles.sectionHeading}>
        <div>
          <span className={styles.eyebrow}>Eén gegevensbron</span>
          <h3 id="workflow-bindings-title">Koppelingen naar de inzet</h3>
          <p>Koppel een uitvraagveld aan maximaal één toegestaan inzetveld. Per actieve aanvraag heeft een inzetveld precies één bron; exclusieve Mens-, Dier- en Objecttakken mogen hetzelfde doel delen.</p>
        </div>
        <span className={styles.counter}>{configuration.bindings.length} gekoppeld</span>
      </div>
      <div className={styles.bindingList}>
        {fields.map((field) => {
          const binding = configuration.bindings.find((candidate) => candidate.field_key === field.key);
          const targets = availableBindingTargets(
            field.key,
            configuration.fields,
            configuration.bindings,
            deploymentFields,
          );
          const selectedTarget = binding === undefined
            ? null
            : deploymentFields.find((candidate) => candidate.target === binding.target) ?? null;
          const bindingValid = selectedTarget !== null && bindingTypesCompatible(field, selectedTarget);

          return (
            <div className={styles.bindingRow} key={field.key}>
              <div>
                <strong>{field.label}</strong>
                <span>{scopeLabel(field.scope)} · <code>{field.key}</code></span>
              </div>
              <Link2 size={18} aria-hidden="true" />
              <label>
                <span className="sr-only">Inzetveld voor {field.label}</span>
                <select
                  value={binding?.target ?? ''}
                  onChange={(event) => onChange({
                    ...configuration,
                    bindings: updateWorkflowBinding(configuration.bindings, field.key, event.target.value),
                  })}
                >
                  <option value="">Niet gekoppeld</option>
                  {binding !== undefined && selectedTarget === null ? (
                    <option value={binding.target}>Niet meer beschikbaar</option>
                  ) : null}
                  {selectedTarget !== null && !targets.some((target) => target.target === selectedTarget.target) ? (
                    <option value={selectedTarget.target}>{selectedTarget.label}</option>
                  ) : null}
                  {targets.map((target) => (
                    <option key={target.target} value={target.target}>{target.label}</option>
                  ))}
                </select>
              </label>
              {selectedTarget !== null && bindingValid ? (
                <span className={styles.bindingChip}>{selectedTarget.label}</span>
              ) : binding !== undefined ? (
                <span className={styles.invalidBindingChip}>Kies een passend veldtype</span>
              ) : (
                <span className={styles.unboundChip}>Alleen uitvraag</span>
              )}
            </div>
          );
        })}
      </div>
    </section>
  );
}

function RulesPanel(props: {
  configuration: DeploymentRequestWorkflowConfiguration;
  operatorCatalog: DeploymentRequestWorkflowAdminEnvelope['catalogs']['operators'];
  decisionMode: boolean;
  onChange: (configuration: DeploymentRequestWorkflowConfiguration) => void;
}) {
  const { configuration, operatorCatalog, decisionMode, onChange } = props;
  const confirmAction = useConfirmDialog();
  const [activeSubject, setActiveSubject] = useState<DeploymentSubjectType>('person');
  const visibleRules = decisionMode
    ? workflowPriorityRulesForSubject(configuration.priority_rules, activeSubject)
    : configuration.priority_rules;
  const activeSubjectLabel = configuration.subject_types.find((subject) => subject.key === activeSubject)?.label
    ?? activeSubject;

  function updateRule(
    ruleId: string,
    updater: (
      rule: DeploymentRequestWorkflowPriorityRule,
    ) => DeploymentRequestWorkflowPriorityRule,
  ) {
    onChange({
      ...configuration,
      priority_rules: configuration.priority_rules.map((rule) => rule.id === ruleId ? updater(rule) : rule),
    });
  }

  function addRule() {
    onChange({
      ...configuration,
      priority_rules: [
        ...configuration.priority_rules,
        decisionMode
          ? createWorkflowPriorityRuleForSubject(
              configuration.priority_rules,
              configuration.fields,
              activeSubject,
            )
          : createWorkflowPriorityRule(configuration.priority_rules, configuration.fields),
      ],
    });
  }

  async function moveRule(
    rule: DeploymentRequestWorkflowPriorityRule,
    index: number,
    direction: -1 | 1,
  ) {
    const adjacentRule = visibleRules[index + direction];
    if (
      decisionMode
      && (rule.subject_types.length > 1 || (adjacentRule?.subject_types.length ?? 0) > 1)
      && !await confirmAction({
        title: 'Gedeelde regel verplaatsen?',
        message: 'Deze verplaatsing raakt een gedeelde regel en wijzigt de regelvolgorde ook voor de andere aanvraagtypen.',
        confirmLabel: 'Regel verplaatsen',
        intent: 'warning',
      })
    ) {
      return;
    }

    onChange({
      ...configuration,
      priority_rules: decisionMode
        ? moveWorkflowPriorityRuleForSubject(
            configuration.priority_rules,
            rule.id,
            activeSubject,
            direction,
          )
        : moveWorkflowItem(configuration.priority_rules, index, direction),
    });
  }

  async function removeRule(rule: DeploymentRequestWorkflowPriorityRule) {
    if (!await confirmAction({
      title: 'Prioriteitsregel verwijderen?',
      message: `Je verwijdert de regel “${rule.label}” uit het concept.`,
      confirmLabel: 'Regel verwijderen',
      intent: 'danger',
    })) {
      return;
    }

    onChange({
      ...configuration,
      priority_rules: configuration.priority_rules.filter((candidate) => candidate.id !== rule.id),
    });
  }

  return (
    <section className={styles.workspace} aria-labelledby="workflow-rules-title">
      <div className={styles.sectionHeading}>
        <div>
          <span className={styles.eyebrow}>Beslisregels</span>
          <h3 id="workflow-rules-title">Prioriteitsadvies met uitleg</h3>
          <p>
            {decisionMode
              ? 'Bekijk en beheer regels per aanvraagtype. Nieuwe regels gelden alleen voor het gekozen type; bestaande gedeelde regels blijven herkenbaar in elke betrokken rail.'
              : 'Regels adviseren Laag, Middel, Hoog of Urgent. De centralist blijft verantwoordelijk voor de vastgestelde prioriteit.'}
          </p>
        </div>
        <button className="secondary-button" type="button" onClick={addRule}>
          <Plus size={17} aria-hidden="true" />
          {decisionMode ? `Regel voor ${activeSubjectLabel.toLowerCase()} toevoegen` : 'Regel toevoegen'}
        </button>
      </div>

      {decisionMode ? (
        <>
          <nav className={styles.decisionSubjectRail} aria-label="Aanvraagtype">
            {configuration.subject_types.map((subject) => {
              const count = workflowPriorityRulesForSubject(configuration.priority_rules, subject.key).length;

              return (
                <button
                  type="button"
                  key={subject.key}
                  className={subject.key === activeSubject
                    ? styles.decisionSubjectActive
                    : styles.decisionSubject}
                  aria-current={subject.key === activeSubject ? 'page' : undefined}
                  onClick={() => setActiveSubject(subject.key)}
                >
                  <span>{subject.label}</span>
                  <strong>{count}</strong>
                </button>
              );
            })}
          </nav>
          <PriorityRail rules={visibleRules} subjectLabel={activeSubjectLabel} />
        </>
      ) : null}

      <div className={styles.cardList}>
        {visibleRules.length === 0 ? (
          <div className={styles.empty}>
            <strong>
              {decisionMode
                ? `Nog geen prioriteitsregels voor ${activeSubjectLabel.toLowerCase()}`
                : 'Nog geen prioriteitsregels'}
            </strong>
            <span>Zonder passende regel blijft het advies nog niet bepaalbaar.</span>
          </div>
        ) : null}
        {visibleRules.map((rule, index) => (
          <RuleCard
            key={rule.id}
            rule={rule}
            fields={configuration.fields}
            profiles={configuration.deployment_profiles}
            subjectTypes={configuration.subject_types}
            operatorCatalog={operatorCatalog}
            decisionMode={decisionMode}
            first={index === 0}
            last={index === visibleRules.length - 1}
            onUpdate={(updater) => updateRule(rule.id, updater)}
            onMove={(direction) => void moveRule(rule, index, direction)}
            onRemove={() => void removeRule(rule)}
          />
        ))}
      </div>
    </section>
  );
}

function PriorityRail(props: {
  rules: DeploymentRequestWorkflowPriorityRule[];
  subjectLabel: string;
}) {
  return (
    <ol className={styles.priorityRail} aria-label={`Verdeling prioriteitsadviezen voor ${props.subjectLabel}`}>
      {deploymentRequestWorkflowPriorities.map((priority) => (
        <li className={decisionPriorityRailClass(priority.value)} key={priority.value}>
          <span aria-hidden="true" />
          <strong>{props.rules.filter((rule) => rule.priority === priority.value).length}</strong>
          <small>{priority.label}</small>
        </li>
      ))}
    </ol>
  );
}

function RuleCard(props: {
  rule: DeploymentRequestWorkflowPriorityRule;
  fields: DeploymentRequestWorkflowField[];
  profiles: DeploymentRequestWorkflowDeploymentProfile[];
  subjectTypes: DeploymentRequestWorkflowConfiguration['subject_types'];
  operatorCatalog: DeploymentRequestWorkflowAdminEnvelope['catalogs']['operators'];
  decisionMode: boolean;
  first: boolean;
  last: boolean;
  onUpdate: (
    updater: (
      rule: DeploymentRequestWorkflowPriorityRule,
    ) => DeploymentRequestWorkflowPriorityRule,
  ) => void;
  onMove: (direction: -1 | 1) => void;
  onRemove: () => void;
}) {
  const {
    rule,
    fields,
    profiles,
    subjectTypes,
    operatorCatalog,
    decisionMode,
    first,
    last,
    onUpdate,
    onMove,
    onRemove,
  } = props;
  const safeFields = ruleSafeFields(fields, rule.subject_types);
  const compatibleProfiles = profiles.filter((profile) =>
    rule.subject_types.every((subject) => profile.subject_types.includes(subject))
    && profile.priorities.includes(rule.priority));

  function updateSubjects(subject: DeploymentSubjectType, checked: boolean) {
    const selected = checked
      ? [...rule.subject_types, subject]
      : rule.subject_types.filter((candidate) => candidate !== subject);
    if (selected.length === 0) {
      return;
    }

    onUpdate((current) => {
      const normalized = normalizeRuleForSubjects({ ...current, subject_types: selected }, fields);
      const selectedProfile = profiles.find((profile) => profile.id === normalized.deployment_profile_id);
      const profileStillCompatible = selectedProfile !== undefined
        && selected.every((candidate) => selectedProfile.subject_types.includes(candidate))
        && selectedProfile.priorities.includes(normalized.priority);

      return profileStillCompatible ? normalized : { ...normalized, deployment_profile_id: null };
    });
  }

  function addCondition() {
    const field = safeFields[0];
    if (field === undefined) {
      return;
    }
    const operator = defaultConditionOperator(field.type);
    onUpdate((current) => ({
      ...current,
      conditions: [
        ...current.conditions,
        {
          field_key: field.key,
          operator,
          ...(conditionOperatorNeedsValue(operator, operatorCatalog) ? { value: defaultConditionValue(field) } : {}),
        },
      ],
    }));
  }

  return (
    <details className={`${styles.editorCard}${decisionMode
      ? ` ${styles.decisionRuleCard} ${decisionPriorityRailClass(rule.priority)}`
      : ''}`}>
      <summary>
        <div>
          <strong>{rule.label}</strong>
          <code>{rule.id}</code>
        </div>
        <span className={styles.summaryMeta}>
          <span className={priorityClass(rule.priority)}>{priorityLabel(rule.priority)}</span>
          <span>{rule.conditions.length} voorwaarden</span>
          {decisionMode && rule.subject_types.length > 1 ? (
            <span>Gedeeld over {rule.subject_types.length} typen</span>
          ) : null}
        </span>
      </summary>
      <div className={styles.editorBody}>
        <div className={styles.fieldGrid}>
          <label>
            Naam regel
            <input value={rule.label} onChange={(event) => onUpdate((current) => ({ ...current, label: event.target.value }))} />
          </label>
          <label>
            Als voorwaarden
            <select value={rule.match} onChange={(event) => onUpdate((current) => ({ ...current, match: event.target.value as 'all' | 'any' }))}>
              <option value="all">allemaal kloppen</option>
              <option value="any">minimaal één klopt</option>
            </select>
          </label>
        </div>
        <fieldset className={styles.choiceGroup}>
          <legend>Geldt voor</legend>
          <div>
            {subjectTypes.map((subject) => (
              <label key={subject.key}>
                <input
                  type="checkbox"
                  checked={rule.subject_types.includes(subject.key)}
                  disabled={rule.subject_types.length === 1 && rule.subject_types.includes(subject.key)}
                  onChange={(event) => updateSubjects(subject.key, event.target.checked)}
                />
                {subject.label}
              </label>
            ))}
          </div>
        </fieldset>
        {decisionMode && rule.subject_types.length > 1 ? (
          <p className={styles.inlineNotice}>
            Dit is één gedeelde regel. Wijzigingen gelden voor alle aangevinkte aanvraagtypen en de regelvolgorde is globaal.
          </p>
        ) : null}

        <div className={styles.conditionBlock}>
          <div className={styles.blockHeading}>
            <strong>Voorwaarden</strong>
            <button className="secondary-button" type="button" onClick={addCondition} disabled={safeFields.length === 0}>
              <Plus size={15} aria-hidden="true" />
              Voorwaarde
            </button>
          </div>
          {rule.conditions.length === 0 ? (
            <p className={styles.inlineNotice}>Zonder voorwaarden geldt deze regel als standaardregel voor de gekozen onderwerpen. Andere passende regels kunnen een hogere prioriteit adviseren.</p>
          ) : null}
          {rule.conditions.map((condition, index) => (
            <ConditionRow
              key={`${rule.id}-${index}`}
              condition={condition}
              fields={safeFields}
              operatorCatalog={operatorCatalog}
              onUpdate={(next) => onUpdate((current) => ({
                ...current,
                conditions: current.conditions.map((candidate, conditionIndex) => conditionIndex === index ? next : candidate),
              }))}
              onRemove={() => onUpdate((current) => ({
                ...current,
                conditions: current.conditions.filter((_, conditionIndex) => conditionIndex !== index),
              }))}
            />
          ))}
        </div>

        <div className={styles.outcome}>
          <label>
            Adviesprioriteit
            <select
              value={rule.priority}
              onChange={(event) => onUpdate((current) => ({
                ...current,
                priority: event.target.value as DeploymentRequestWorkflowPriority,
                deployment_profile_id: null,
              }))}
            >
              {deploymentRequestWorkflowPriorities.map((priority) => (
                <option key={priority.value} value={priority.value}>{priority.label}</option>
              ))}
            </select>
          </label>
          <label>
            Inzetvoorstel
            <select
              value={rule.deployment_profile_id ?? ''}
              onChange={(event) => onUpdate((current) => ({ ...current, deployment_profile_id: event.target.value || null }))}
            >
              <option value="">Automatisch passend inzetprofiel</option>
              {compatibleProfiles.map((profile) => (
                <option key={profile.id} value={profile.id}>{profile.label}</option>
              ))}
            </select>
          </label>
          <label className={styles.fullField}>
            Uitleg bij het advies
            <textarea
              rows={2}
              value={rule.explanation}
              onChange={(event) => onUpdate((current) => ({ ...current, explanation: event.target.value }))}
              placeholder="Bijvoorbeeld: kwetsbare vermiste persoon, voor het laatst gezien nabij water."
            />
          </label>
        </div>

        <div className={styles.cardActions}>
          <button className="icon-button" type="button" disabled={first} onClick={() => onMove(-1)} aria-label={`${rule.label} omhoog`}>
            <ArrowUp size={16} aria-hidden="true" />
          </button>
          <button className="icon-button" type="button" disabled={last} onClick={() => onMove(1)} aria-label={`${rule.label} omlaag`}>
            <ArrowDown size={16} aria-hidden="true" />
          </button>
          <button className="danger-button" type="button" onClick={onRemove}>
            <Trash2 size={16} aria-hidden="true" />
            Verwijderen
          </button>
        </div>
      </div>
    </details>
  );
}

function ConditionRow(props: {
  condition: DeploymentRequestWorkflowCondition;
  fields: DeploymentRequestWorkflowField[];
  operatorCatalog: DeploymentRequestWorkflowAdminEnvelope['catalogs']['operators'];
  onUpdate: (condition: DeploymentRequestWorkflowCondition) => void;
  onRemove: () => void;
}) {
  const { condition, fields, operatorCatalog, onUpdate, onRemove } = props;
  const field = fields.find((candidate) => candidate.key === condition.field_key) ?? fields[0];
  const operators = conditionOperatorsForField(field, operatorCatalog);
  const operator = operators.some((candidate) => candidate.key === condition.operator)
    ? condition.operator
    : operators[0]?.key;
  const needsValue = operator !== undefined && conditionOperatorNeedsValue(operator, operatorCatalog);

  if (field === undefined || operator === undefined) {
    return null;
  }

  return (
    <div className={styles.conditionRow}>
      <label>
        <span className="sr-only">Veld</span>
        <select
          value={field.key}
          onChange={(event) => {
            const nextField = fields.find((candidate) => candidate.key === event.target.value);
            if (nextField === undefined) {
              return;
            }
            const nextOperator = defaultConditionOperator(nextField.type);
            onUpdate({
              field_key: nextField.key,
              operator: nextOperator,
              ...(conditionOperatorNeedsValue(nextOperator, operatorCatalog) ? { value: defaultConditionValue(nextField) } : {}),
            });
          }}
        >
          {fields.map((candidate) => (
            <option key={candidate.key} value={candidate.key}>{candidate.label}</option>
          ))}
        </select>
      </label>
      <label>
        <span className="sr-only">Vergelijking</span>
        <select
          value={operator}
          onChange={(event) => {
            const nextOperator = event.target.value as DeploymentRequestWorkflowCondition['operator'];
            onUpdate({
              field_key: field.key,
              operator: nextOperator,
              ...(conditionOperatorNeedsValue(nextOperator, operatorCatalog) ? { value: defaultConditionValue(field) } : {}),
            });
          }}
        >
          {operators.map((candidate) => (
            <option key={candidate.key} value={candidate.key}>{candidate.label}</option>
          ))}
        </select>
      </label>
      {needsValue ? (
        <ConditionValueInput
          field={field}
          value={condition.value}
          onChange={(value) => onUpdate({ ...condition, operator, value })}
        />
      ) : <span className={styles.noValue}>Geen waarde nodig</span>}
      <button className="icon-button" type="button" onClick={onRemove} aria-label="Voorwaarde verwijderen">
        <Trash2 size={16} aria-hidden="true" />
      </button>
    </div>
  );
}

function ConditionValueInput(props: {
  field: DeploymentRequestWorkflowField;
  value: unknown;
  onChange: (value: unknown) => void;
}) {
  const { field, value, onChange } = props;

  if (field.type === 'checkbox') {
    return (
      <label>
        <span className="sr-only">Waarde</span>
        <select
          value={value === true ? 'true' : value === false ? 'false' : ''}
          onChange={(event) => onChange(event.target.value === 'true')}
        >
          <option value="true">Ja</option>
          <option value="false">Nee</option>
        </select>
      </label>
    );
  }

  if (field.type === 'select' || field.type === 'radio') {
    return (
      <label>
        <span className="sr-only">Waarde</span>
        <select value={typeof value === 'string' ? value : ''} onChange={(event) => onChange(event.target.value)}>
          {field.options.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </label>
    );
  }

  return (
    <label>
      <span className="sr-only">Waarde</span>
      <input
        type={field.type === 'number' ? 'number' : field.type === 'datetime' ? 'datetime-local' : field.type === 'date' ? 'date' : 'text'}
        value={field.type === 'datetime'
          ? deploymentRequestWorkflowDateTimeLocalValue(value)
          : typeof value === 'string' || typeof value === 'number' ? value : ''}
        onChange={(event) => onChange(field.type === 'number'
          ? (event.target.value === '' ? '' : Number(event.target.value))
          : field.type === 'datetime'
            ? deploymentRequestWorkflowDateTimeIsoValue(event.target.value)
            : event.target.value)}
      />
    </label>
  );
}

function ProfilesPanel(props: {
  configuration: DeploymentRequestWorkflowConfiguration;
  teams: DeploymentRequestWorkflowAdminEnvelope['catalogs']['teams'];
  certificationTypes: DeploymentRequestWorkflowAdminEnvelope['catalogs']['certification_types'];
  decisionMode: boolean;
  onChange: (configuration: DeploymentRequestWorkflowConfiguration) => void;
}) {
  const { configuration, teams, certificationTypes, decisionMode, onChange } = props;
  const confirmAction = useConfirmDialog();

  function updateProfile(
    profileId: string,
    updater: (
      profile: DeploymentRequestWorkflowDeploymentProfile,
    ) => DeploymentRequestWorkflowDeploymentProfile,
  ) {
    onChange(updateWorkflowDeploymentProfile(configuration, profileId, updater));
  }

  function addProfile() {
    onChange({
      ...configuration,
      deployment_profiles: [
        ...configuration.deployment_profiles,
        createWorkflowDeploymentProfile(configuration.deployment_profiles),
      ],
    });
  }

  async function removeProfile(profile: DeploymentRequestWorkflowDeploymentProfile) {
    if (!await confirmAction({
      title: 'Inzetprofiel verwijderen?',
      message: `Je verwijdert het profiel “${profile.label}”. Gekoppelde regels verliezen dit inzetvoorstel.`,
      confirmLabel: 'Profiel verwijderen',
      intent: 'danger',
    })) {
      return;
    }

    onChange({
      ...configuration,
      deployment_profiles: configuration.deployment_profiles.filter((candidate) => candidate.id !== profile.id),
      priority_rules: configuration.priority_rules.map((rule) => rule.deployment_profile_id === profile.id
        ? { ...rule, deployment_profile_id: null }
        : rule),
    });
  }

  return (
    <section className={styles.workspace} aria-labelledby="workflow-profiles-title">
      <div className={styles.sectionHeading}>
        <div>
          <span className={styles.eyebrow}>Voorstel, geen alarmering</span>
          <h3 id="workflow-profiles-title">
            {decisionMode ? 'Standaard inzetprofielen voor de conceptinzet' : 'Configureerbare inzetprofielen'}
          </h3>
          <p>
            {decisionMode
              ? 'Zet per profiel de voorgestelde teams expliciet aan of uit. De inzet wordt pas later voorbereid; hier wordt niemand geselecteerd of gealarmeerd.'
              : 'Een profiel adviseert teams en benodigde middelen. Beschikbaarheid en inzetbaarheid worden pas bij alarmeren server-side gecontroleerd.'}
          </p>
        </div>
        <button className="secondary-button" type="button" onClick={addProfile}>
          <Plus size={17} aria-hidden="true" />
          Profiel toevoegen
        </button>
      </div>
      <div className={styles.cardList}>
        {configuration.deployment_profiles.length === 0 ? (
          <div className={styles.empty}>
            <strong>Nog geen inzetprofielen</strong>
            <span>Prioriteitsregels kunnen voorlopig alleen een prioriteit adviseren.</span>
          </div>
        ) : null}
        {configuration.deployment_profiles.map((profile, index) => (
          <ProfileCard
            key={profile.id}
            profile={profile}
            teams={teams}
            certificationTypes={certificationTypes}
            subjectTypes={configuration.subject_types}
            decisionMode={decisionMode}
            first={index === 0}
            last={index === configuration.deployment_profiles.length - 1}
            onUpdate={(updater) => updateProfile(profile.id, updater)}
            onMove={(direction) => onChange({
              ...configuration,
              deployment_profiles: moveWorkflowItem(configuration.deployment_profiles, index, direction),
            })}
            onRemove={() => void removeProfile(profile)}
          />
        ))}
      </div>
    </section>
  );
}

function ProfileCard(props: {
  profile: DeploymentRequestWorkflowDeploymentProfile;
  teams: DeploymentRequestWorkflowAdminEnvelope['catalogs']['teams'];
  certificationTypes: DeploymentRequestWorkflowAdminEnvelope['catalogs']['certification_types'];
  subjectTypes: DeploymentRequestWorkflowConfiguration['subject_types'];
  decisionMode: boolean;
  first: boolean;
  last: boolean;
  onUpdate: (
    updater: (
      profile: DeploymentRequestWorkflowDeploymentProfile,
    ) => DeploymentRequestWorkflowDeploymentProfile,
  ) => void;
  onMove: (direction: -1 | 1) => void;
  onRemove: () => void;
}) {
  const {
    profile,
    teams,
    certificationTypes,
    subjectTypes,
    decisionMode,
    first,
    last,
    onUpdate,
    onMove,
    onRemove,
  } = props;
  const missingTeamIds = profile.team_ids.filter((teamId) => !teams.some((team) => team.id === teamId));
  const missingCertificationIds = profile.required_certification_type_ids.filter(
    (certificationId) => !certificationTypes.some((certification) => certification.id === certificationId),
  );

  return (
    <details className={styles.editorCard}>
      <summary>
        <div>
          <strong>{profile.label}</strong>
          <code>{profile.id}</code>
        </div>
        <span className={styles.summaryMeta}>
          <span>{profile.team_ids.length} teams</span>
          <span>{profile.resources.length} middelen</span>
        </span>
      </summary>
      <div className={styles.editorBody}>
        <div className={styles.fieldGrid}>
          <label>
            Naam profiel
            <input value={profile.label} onChange={(event) => onUpdate((current) => ({ ...current, label: event.target.value }))} />
          </label>
          <label>
            Technische sleutel
            <input value={profile.id} readOnly />
          </label>
          <label>
            Geadviseerd aantal ontvangers
            <input
              type="number"
              min={1}
              max={200}
              step={1}
              value={profile.recommended_recipient_count ?? ''}
              placeholder="Geen vast aantal"
              onChange={(event) => onUpdate((current) => ({
                ...current,
                recommended_recipient_count: event.target.value === '' ? null : Number(event.target.value),
              }))}
            />
          </label>
          <label>
            Alarmeringsadvies
            <select
              value={profile.recommended_dispatch_mode ?? ''}
              onChange={(event) => onUpdate((current) => ({
                ...current,
                recommended_dispatch_mode: event.target.value === ''
                  ? null
                  : event.target.value as 'preannouncement' | 'direct_dispatch',
              }))}
            >
              <option value="">Geen voorkeur</option>
              <option value="preannouncement">Adviseer eerst vooraankondiging</option>
              <option value="direct_dispatch">Adviseer direct alarmeren</option>
            </select>
          </label>
          <label className={styles.fullField}>
            Toelichting en notities voor centralist
            <textarea rows={2} value={profile.summary ?? ''} onChange={(event) => onUpdate((current) => ({ ...current, summary: event.target.value }))} />
          </label>
        </div>
        <div className={styles.choiceColumns}>
          <fieldset className={styles.choiceGroup}>
            <legend>Onderwerpen</legend>
            <div>
              {subjectTypes.map((subject) => (
                <label key={subject.key}>
                  <input
                    type="checkbox"
                    checked={profile.subject_types.includes(subject.key)}
                    disabled={profile.subject_types.length === 1 && profile.subject_types.includes(subject.key)}
                    onChange={(event) => onUpdate((current) => ({
                      ...current,
                      subject_types: event.target.checked
                        ? [...current.subject_types, subject.key]
                        : current.subject_types.filter((candidate) => candidate !== subject.key),
                    }))}
                  />
                  {subject.label}
                </label>
              ))}
            </div>
          </fieldset>
          <fieldset className={styles.choiceGroup}>
            <legend>Prioriteiten</legend>
            <div>
              {deploymentRequestWorkflowPriorities.map((priority) => (
                <label key={priority.value}>
                  <input
                    type="checkbox"
                    checked={profile.priorities.includes(priority.value)}
                    disabled={profile.priorities.length === 1 && profile.priorities.includes(priority.value)}
                    onChange={(event) => onUpdate((current) => ({
                      ...current,
                      priorities: event.target.checked
                        ? [...current.priorities, priority.value]
                        : current.priorities.filter((candidate) => candidate !== priority.value),
                    }))}
                  />
                  {priority.label}
                </label>
              ))}
            </div>
          </fieldset>
        </div>
        <fieldset className={`${styles.teamGroup}${decisionMode ? ` ${styles.teamSwitches}` : ''}`}>
          <legend>Voorgestelde teams</legend>
          <div>
            {teams.length === 0 ? <span>Geen teams beschikbaar in de catalogus.</span> : null}
            {teams.map((team) => (
              <label key={team.id}>
                <input
                  type="checkbox"
                  checked={profile.team_ids.includes(team.id)}
                  onChange={(event) => onUpdate((current) => ({
                    ...current,
                    team_ids: event.target.checked
                      ? [...current.team_ids, team.id]
                      : current.team_ids.filter((candidate) => candidate !== team.id),
                  }))}
                />
                <span>{team.name}</span>
                {decisionMode ? (
                  <small>{profile.team_ids.includes(team.id) ? 'Aan' : 'Uit'}</small>
                ) : null}
              </label>
            ))}
            {missingTeamIds.map((teamId) => {
              const snapshot = profile.team_snapshots?.find((team) => team.id === teamId);
              return (
                <label className={styles.missingChoice} key={teamId}>
                  <input
                    type="checkbox"
                    checked
                    onChange={() => onUpdate((current) => ({
                      ...current,
                      team_ids: current.team_ids.filter((candidate) => candidate !== teamId),
                    }))}
                  />
                  Verwijderd team{snapshot === undefined ? '' : `: ${snapshot.code} · ${snapshot.name}`} · uitvinken om te herstellen
                </label>
              );
            })}
          </div>
        </fieldset>
        <fieldset className={styles.teamGroup}>
          <legend>Vereiste certificaten</legend>
          <div>
            {certificationTypes.length === 0 ? <span>Geen certificaatsoorten beschikbaar.</span> : null}
            {certificationTypes.map((certification) => (
              <label key={certification.id}>
                <input
                  type="checkbox"
                  checked={profile.required_certification_type_ids.includes(certification.id)}
                  onChange={(event) => onUpdate((current) => ({
                    ...current,
                    required_certification_type_ids: event.target.checked
                      ? [...current.required_certification_type_ids, certification.id]
                      : current.required_certification_type_ids.filter((candidate) => candidate !== certification.id),
                  }))}
                />
                {certification.code} · {certification.name}
              </label>
            ))}
            {missingCertificationIds.map((certificationId) => {
              const snapshot = profile.certification_type_snapshots?.find(
                (certification) => certification.id === certificationId,
              );
              return (
                <label className={styles.missingChoice} key={certificationId}>
                  <input
                    type="checkbox"
                    checked
                    onChange={() => onUpdate((current) => ({
                      ...current,
                      required_certification_type_ids: current.required_certification_type_ids.filter(
                        (candidate) => candidate !== certificationId,
                      ),
                    }))}
                  />
                  Verwijderd certificaat{snapshot === undefined ? '' : `: ${snapshot.code} · ${snapshot.name}`} · uitvinken om te herstellen
                </label>
              );
            })}
          </div>
        </fieldset>
        <ResourcesEditor
          profile={profile}
          onUpdate={(resources) => onUpdate((current) => ({ ...current, resources }))}
        />
        <p className={styles.inlineNotice}>
          Dit is uitsluitend een inzetadvies. Het profiel kiest geen individuele gebruikers en start geen alarmering.
        </p>
        <div className={styles.cardActions}>
          <button className="icon-button" type="button" disabled={first} onClick={() => onMove(-1)} aria-label={`${profile.label} omhoog`}>
            <ArrowUp size={16} aria-hidden="true" />
          </button>
          <button className="icon-button" type="button" disabled={last} onClick={() => onMove(1)} aria-label={`${profile.label} omlaag`}>
            <ArrowDown size={16} aria-hidden="true" />
          </button>
          <button className="danger-button" type="button" onClick={onRemove}>
            <Trash2 size={16} aria-hidden="true" />
            Verwijderen
          </button>
        </div>
      </div>
    </details>
  );
}

function ResourcesEditor(props: {
  profile: DeploymentRequestWorkflowDeploymentProfile;
  onUpdate: (resources: string[]) => void;
}) {
  const { profile, onUpdate } = props;
  const serialized = profile.resources.join('\n');
  const [draft, setDraft] = useState(serialized);
  const inputRef = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    if (document.activeElement !== inputRef.current) setDraft(serialized);
  }, [profile.id, serialized]);

  return (
    <label className={styles.fullField}>
      Benodigde middelen en capaciteiten
      <textarea
        ref={inputRef}
        rows={4}
        value={draft}
        onChange={(event) => {
          setDraft(event.target.value);
          onUpdate(linesToResources(event.target.value));
        }}
        onBlur={() => setDraft(linesToResources(draft).join('\n'))}
        placeholder={'Warmtebeeldcamera\nZoeklicht\nPiloot met nachtbevoegdheid'}
      />
      <span>Eén inzetcomponent per regel.</span>
    </label>
  );
}

function VersionsPanel(props: {
  configuration: DeploymentRequestWorkflowConfiguration;
  envelope: DeploymentRequestWorkflowAdminEnvelope;
  dirty: boolean;
  restoringId: string | null;
  onConflict: (error: ApiClientError) => void;
  onRestore: (revision: DeploymentRequestWorkflowRevision) => void;
}) {
  const { configuration, envelope, dirty, restoringId, onConflict, onRestore } = props;
  const { api } = useAuth();
  const [subjectType, setSubjectType] = useState<DeploymentSubjectType>('person');
  const [answers, setAnswers] = useState<Record<string, unknown>>({});
  const [simulating, setSimulating] = useState(false);
  const [simulation, setSimulation] = useState<DeploymentRequestWorkflowSimulationResult | null>(null);
  const [simulationError, setSimulationError] = useState<string | null>(null);
  const simulationFields = configuration.fields.filter((field) =>
    field.type !== 'section' && (field.scope === 'common' || field.scope === subjectType));

  async function simulate() {
    if (dirty) {
      return;
    }

    setSimulating(true);
    setSimulationError(null);
    try {
      const response = await api.post<DeploymentRequestWorkflowSimulationResult>('/admin/deployment-request-workflow/simulate', {
        expected_revision: envelope.draft.lock_version,
        subject_type: subjectType,
        answers: Object.fromEntries(
          Object.entries(answers)
            .filter(([, value]) => value !== '' && value !== null && value !== undefined),
        ),
      });
      setSimulation(response.data);
    } catch (actionError) {
      if (actionError instanceof ApiClientError && actionError.status === 409) {
        onConflict(actionError);
        setSimulationError(null);
        setSimulation(null);
        return;
      }
      setSimulationError(actionError instanceof ApiClientError ? actionError.message : 'Simulatie uitvoeren mislukt.');
      setSimulation(null);
    } finally {
      setSimulating(false);
    }
  }

  return (
    <section className={styles.workspace} aria-labelledby="workflow-versions-title">
      <div className={styles.sectionHeading}>
        <div>
          <span className={styles.eyebrow}>Veilig publiceren</span>
          <h3 id="workflow-versions-title">Versies, validatie en simulatie</h3>
          <p>Test de opgeslagen conceptversie met voorbeeldantwoorden. Terugzetten maakt altijd een nieuw concept en wijzigt de actieve versie niet.</p>
        </div>
      </div>

      <div className={styles.versionGrid}>
        <article className={styles.simulator}>
          <div className={styles.blockHeading}>
            <div>
              <strong>Advies simuleren</strong>
              <span>Opgeslagen concept · revisie {envelope.draft.lock_version}</span>
            </div>
            {dirty ? <span className={styles.dirtyPill}>Sla wijzigingen eerst op</span> : null}
          </div>
          <label>
            Wie of wat zoeken we?
            <select
              value={subjectType}
              onChange={(event) => {
                setSubjectType(event.target.value as DeploymentSubjectType);
                setAnswers({});
                setSimulation(null);
              }}
            >
              {configuration.subject_types.map((subject) => (
                <option key={subject.key} value={subject.key}>{subject.label}</option>
              ))}
            </select>
          </label>
          <div className={styles.simulationFields}>
            {simulationFields.map((field) => (
              <SimulationInput
                key={field.key}
                field={field}
                value={answers[field.key]}
                onChange={(value) => setAnswers((current) => ({ ...current, [field.key]: value }))}
              />
            ))}
          </div>
          {simulationError !== null ? <p className={styles.error} role="alert">{simulationError}</p> : null}
          <button className="secondary-button" type="button" disabled={dirty || simulating} onClick={() => void simulate()}>
            <ClipboardCheck size={17} aria-hidden="true" />
            {simulating ? 'Simuleren…' : 'Advies berekenen'}
          </button>
          {simulation !== null ? (
            <SimulationResult
              result={simulation}
              configuration={configuration}
              teams={envelope.catalogs.teams}
              certificationTypes={envelope.catalogs.certification_types}
            />
          ) : null}
        </article>

        <article className={styles.historyCard}>
          <div className={styles.blockHeading}>
            <div>
              <strong>Versiehistorie</strong>
              <span>{envelope.history.length} gepubliceerde versies</span>
            </div>
            <History size={19} aria-hidden="true" />
          </div>
          {dirty ? (
            <p className={styles.inlineNotice}>Sla je lokale wijzigingen op of herlaad de pagina voordat je een eerdere versie als concept terugzet.</p>
          ) : null}
          <ol className={styles.historyList}>
            {envelope.history.length === 0 ? (
              <li className={styles.empty}>
                <strong>Nog geen versie gepubliceerd</strong>
                <span>Publiceer een gevalideerd en opgeslagen concept.</span>
              </li>
            ) : null}
            {envelope.history.map((revision) => (
              <li key={revision.id}>
                <div>
                  <strong>Versie {revision.version ?? 'onbekend'}</strong>
                  <span>{revision.published_at === null ? 'Niet gepubliceerd' : formatDateTime(revision.published_at)}</span>
                </div>
                {envelope.published?.id === revision.id ? (
                  <span className={styles.savedPill}>Actief</span>
                ) : (
                  <button
                    className="secondary-button"
                    type="button"
                    disabled={restoringId !== null || dirty}
                    onClick={() => onRestore(revision)}
                  >
                    {restoringId === revision.id ? 'Terugzetten…' : 'Als concept'}
                  </button>
                )}
              </li>
            ))}
          </ol>
        </article>
      </div>
    </section>
  );
}

function SimulationInput(props: {
  field: DeploymentRequestWorkflowField;
  value: unknown;
  onChange: (value: unknown) => void;
}) {
  const { field, value, onChange } = props;
  const label = field.required ? `${field.label} *` : field.label;

  if (field.type === 'checkbox') {
    return (
      <label>
        {label}
        <select
          value={value === true ? 'true' : value === false ? 'false' : ''}
          onChange={(event) => onChange(
            event.target.value === '' ? undefined : event.target.value === 'true',
          )}
        >
          <option value="">Onbeantwoord</option>
          <option value="true">Ja</option>
          <option value="false">Nee</option>
        </select>
      </label>
    );
  }

  if (field.type === 'select' || field.type === 'radio') {
    return (
      <label>
        {label}
        <select value={typeof value === 'string' ? value : ''} onChange={(event) => onChange(event.target.value)}>
          <option value="">Niet ingevuld</option>
          {field.options.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </label>
    );
  }

  return (
    <label>
      {label}
      {field.type === 'textarea' ? (
        <textarea rows={2} value={typeof value === 'string' ? value : ''} onChange={(event) => onChange(event.target.value)} />
      ) : (
        <input
          type={field.type === 'number' ? 'number' : field.type === 'datetime' ? 'datetime-local' : field.type === 'date' ? 'date' : 'text'}
          value={field.type === 'datetime'
            ? deploymentRequestWorkflowDateTimeLocalValue(value)
            : typeof value === 'string' || typeof value === 'number' ? value : ''}
          onChange={(event) => onChange(field.type === 'number'
            ? (event.target.value === '' ? '' : Number(event.target.value))
            : field.type === 'datetime'
              ? deploymentRequestWorkflowDateTimeIsoValue(event.target.value)
              : event.target.value)}
        />
      )}
    </label>
  );
}

function SimulationResult(props: {
  result: DeploymentRequestWorkflowSimulationResult;
  configuration: DeploymentRequestWorkflowConfiguration;
  teams: DeploymentRequestWorkflowAdminEnvelope['catalogs']['teams'];
  certificationTypes: DeploymentRequestWorkflowAdminEnvelope['catalogs']['certification_types'];
}) {
  const { result, configuration, teams, certificationTypes } = props;
  const priority = result.triage.recommended_priority;
  const profile = result.deployment_proposal?.profile_id === undefined
    ? null
    : configuration.deployment_profiles.find((candidate) => candidate.id === result.deployment_proposal?.profile_id) ?? null;

  return (
    <div className={styles.simulationResult}>
      <div>
        <CheckCircle2 size={20} aria-hidden="true" />
        <span>Prioriteitsadvies</span>
        <strong className={priority === null ? undefined : priorityClass(priority)}>
          {priority === null ? 'Nog niet bepaalbaar' : priorityLabel(priority)}
        </strong>
      </div>
      {result.triage.reasons.length > 0 ? (
        <ul>
          {result.triage.reasons.map((reason) => <li key={reason}>{reason}</li>)}
        </ul>
      ) : null}
      {result.triage.missing_fields.length > 0 ? (
        <p>Nog nodig: {result.triage.missing_fields.map((field) => field.label).join(', ')}</p>
      ) : null}
      {result.deployment_proposal !== null ? (
        <section>
          <strong>{result.deployment_proposal.label ?? profile?.label ?? 'Inzetvoorstel'}</strong>
          {result.deployment_proposal.summary !== null || profile?.summary ? (
            <p>{result.deployment_proposal.summary ?? profile?.summary}</p>
          ) : null}
          {result.deployment_proposal.team_ids.length > 0 ? (
            <span>
              Teams: {(result.deployment_proposal.teams.length > 0
                ? result.deployment_proposal.teams.map((team) => team.name)
                : result.deployment_proposal.team_ids
                  .map((teamId) => teams.find((team) => team.id === teamId)?.name ?? teamId))
                .join(', ')}
            </span>
          ) : null}
          {result.deployment_proposal.resources.length > 0 ? (
            <span>Middelen: {result.deployment_proposal.resources.join(', ')}</span>
          ) : null}
          {result.deployment_proposal.recommended_recipient_count !== null ? (
            <span>Geadviseerd aantal ontvangers: {result.deployment_proposal.recommended_recipient_count}</span>
          ) : null}
          {result.deployment_proposal.recommended_dispatch_mode !== null ? (
            <span>
              Alarmeringsadvies: {result.deployment_proposal.recommended_dispatch_mode === 'preannouncement'
                ? 'eerst vooraankondiging'
                : 'direct alarmeren'}
            </span>
          ) : null}
          {result.deployment_proposal.required_certification_type_ids.length > 0 ? (
            <span>
              Certificaten: {(result.deployment_proposal.required_certification_types.length > 0
                ? result.deployment_proposal.required_certification_types
                  .map((certification) => `${certification.code} · ${certification.name}`)
                : result.deployment_proposal.required_certification_type_ids
                  .map((certificationId) => {
                    const certification = certificationTypes.find((candidate) => candidate.id === certificationId);
                    return certification === undefined ? certificationId : `${certification.code} · ${certification.name}`;
                  }))
                .join(', ')}
            </span>
          ) : null}
        </section>
      ) : null}
    </div>
  );
}

function normalizeField(
  field: DeploymentRequestWorkflowField,
): DeploymentRequestWorkflowField {
  if (field.type === 'section') {
    return {
      ...field,
      required: false,
      operator_visible: false,
      help_text: undefined,
      options: [],
    };
  }

  if (field.type !== 'select' && field.type !== 'radio') {
    return { ...field, options: [] };
  }

  return field;
}

function cloneConfiguration(
  configuration: DeploymentRequestWorkflowConfiguration,
): DeploymentRequestWorkflowConfiguration {
  return structuredClone(configuration);
}

function readConflictRevision(
  details?: Record<string, unknown>,
): DeploymentRequestWorkflowRevision | null {
  const current = details?.current;
  if (
    current === null
    || typeof current !== 'object'
    || typeof (current as Partial<DeploymentRequestWorkflowRevision>).id !== 'string'
    || typeof (current as Partial<DeploymentRequestWorkflowRevision>).lock_version !== 'number'
    || (current as Partial<DeploymentRequestWorkflowRevision>).configuration === null
    || typeof (current as Partial<DeploymentRequestWorkflowRevision>).configuration !== 'object'
  ) {
    return null;
  }

  return current as DeploymentRequestWorkflowRevision;
}

function priorityClass(priority: DeploymentRequestWorkflowPriority): string {
  return priority === 'urgent'
    ? styles.priorityUrgent
    : priority === 'high'
      ? styles.priorityHigh
      : priority === 'medium'
        ? styles.priorityMedium
        : styles.priorityLow;
}

function decisionPriorityRailClass(priority: DeploymentRequestWorkflowPriority): string {
  return priority === 'urgent'
    ? styles.priorityRailUrgent
    : priority === 'high'
      ? styles.priorityRailHigh
      : priority === 'medium'
        ? styles.priorityRailMedium
        : styles.priorityRailLow;
}
