'use client';

import { Eye, MessageSquare, Pencil, Save, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import {
  DeploymentRequestWorkspace,
  type DeploymentRequestWorkspaceHandle,
} from './DeploymentRequestWorkspace';
import {
  deploymentRequestPilotVisibleAnswers,
  deploymentRequestPilotVisibleChanges,
  deploymentRequestPilotVisibleChangesMessage,
  type DeploymentRequest,
  type DeploymentRequestChanges,
  type DeploymentRequestWorkflowRevision,
} from './deploymentRequestWorkflow';

interface AdditionalInfoDeliveryResult {
  queued_tokens: number;
  recipient_users: number;
}

interface DeploymentRequestPanelProps {
  deploymentId: string;
  canManage: boolean;
  refreshVersion?: number;
  additionalInfoRecipientCount?: number | null;
  onSendAdditionalInfo?: (message: string) => Promise<AdditionalInfoDeliveryResult>;
}

export function DeploymentRequestPanel(props: DeploymentRequestPanelProps) {
  const {
    deploymentId,
    canManage,
    refreshVersion = 0,
    additionalInfoRecipientCount = null,
    onSendAdditionalInfo,
  } = props;
  const endpoint = `/deployments/${deploymentId}/deployment-request`;
  const deploymentRequest = useApiResource<DeploymentRequest>(endpoint, deploymentId !== '');
  const reloadDeploymentRequestSilently = deploymentRequest.silentReload;
  const [editModalOpen, setEditModalOpen] = useState(false);
  const [editBaseline, setEditBaseline] = useState<DeploymentRequest | null>(null);
  const [sendPilotChanges, setSendPilotChanges] = useState(false);
  const [finishingEdit, setFinishingEdit] = useState(false);
  const [editError, setEditError] = useState<string | null>(null);
  const [panelMessage, setPanelMessage] = useState<string | null>(null);
  const workspaceRef = useRef<DeploymentRequestWorkspaceHandle>(null);
  const touchedAnswerKeysRef = useRef(new Set<string>());
  const subjectChangedRef = useRef(false);
  const workflow = useApiResource<DeploymentRequestWorkflowRevision>(
    deploymentRequest.data
      ? `/deployment-request-workflow/config?deployment_request_id=${encodeURIComponent(deploymentRequest.data.id)}`
      : '/deployment-request-workflow/config',
    canManage && editModalOpen && deploymentRequest.data !== null,
  );
  const pilotVisibleAnswers = deploymentRequest.data
    ? deploymentRequestPilotVisibleAnswers(deploymentRequest.data)
    : [];
  const canSendPilotChanges = additionalInfoRecipientCount !== null
    && additionalInfoRecipientCount > 0
    && onSendAdditionalInfo !== undefined;

  useEffect(() => {
    if (deploymentId === '' || editModalOpen) return undefined;
    const timer = window.setInterval(() => void reloadDeploymentRequestSilently(), 20_000);
    return () => window.clearInterval(timer);
  }, [deploymentId, editModalOpen, reloadDeploymentRequestSilently]);

  useEffect(() => {
    if (refreshVersion > 0 && !editModalOpen) void reloadDeploymentRequestSilently();
  }, [editModalOpen, refreshVersion, reloadDeploymentRequestSilently]);

  const recordQueuedChanges = useCallback((changes: DeploymentRequestChanges) => {
    if (changes.subject_type !== undefined) subjectChangedRef.current = true;
    Object.keys(changes.answers ?? {}).forEach((key) => touchedAnswerKeysRef.current.add(key));
  }, []);

  const openEditModal = () => {
    if (deploymentRequest.data === null) return;
    touchedAnswerKeysRef.current = new Set();
    subjectChangedRef.current = false;
    setEditBaseline(deploymentRequest.data);
    setSendPilotChanges(false);
    setEditError(null);
    setPanelMessage(null);
    setEditModalOpen(true);
  };

  const closeEditModal = () => {
    setEditModalOpen(false);
    setEditBaseline(null);
    setSendPilotChanges(false);
    setEditError(null);
  };

  const finishEditing = async (sendAdditionalInfo: boolean) => {
    if (finishingEdit) return;
    if (workspaceRef.current === null) {
      closeEditModal();
      return;
    }

    setFinishingEdit(true);
    setEditError(null);
    let persistedSuccessfully = false;
    try {
      const persisted = await workspaceRef.current.savePendingChanges();
      if (persisted === null) {
        setEditError('De wijzigingen konden nog niet veilig worden opgeslagen. Controleer de melding in het formulier.');
        return;
      }

      deploymentRequest.mutate(persisted);
      persistedSuccessfully = true;
      const visibleChanges = editBaseline === null
        ? []
        : deploymentRequestPilotVisibleChanges(editBaseline, persisted)
          .filter((change) => (
            subjectChangedRef.current || touchedAnswerKeysRef.current.has(change.key)
          ));

      if (sendAdditionalInfo && visibleChanges.length > 0 && onSendAdditionalInfo !== undefined) {
        const message = deploymentRequestPilotVisibleChangesMessage(visibleChanges);
        if (message.length > 2000) {
          setEditError('De aanvraag is opgeslagen, maar de pilootinformatie is langer dan 2.000 tekens. Verstuur daarvoor een kortere nadere-info melding.');
          return;
        }

        const result = await onSendAdditionalInfo(message);
        setPanelMessage(
          `Aanvraag bijgewerkt en als nadere info verzonden naar ${result.recipient_users} opkomende gebruiker(s); ${result.queued_tokens} pushbericht(en) staan in de wachtrij.`,
        );
      } else if (sendAdditionalInfo) {
        setPanelMessage('Aanvraag bijgewerkt; er waren geen gewijzigde piloot-zichtbare velden om te versturen.');
      } else {
        setPanelMessage('Aanvraag bijgewerkt.');
      }

      closeEditModal();
    } catch (caught) {
      if (!persistedSuccessfully) {
        setEditError('De wijzigingen konden niet veilig worden opgeslagen. Probeer het opnieuw.');
        return;
      }
      setEditError(
        caught instanceof Error
          ? `De aanvraag is opgeslagen, maar de nadere info kon niet worden verstuurd: ${caught.message}`
          : 'De aanvraag is opgeslagen, maar de nadere info kon niet worden verstuurd.',
      );
    } finally {
      setFinishingEdit(false);
    }
  };

  return (
    <>
      <Panel
        title="Belangrijke inzetinformatie"
        action={canManage && deploymentRequest.data?.status !== 'closed' ? (
          <button className="secondary-button" type="button" onClick={openEditModal}>
            <Pencil size={16} /> Aanvullen
          </button>
        ) : null}
      >
        <ResourceState
          loading={deploymentRequest.loading}
          error={deploymentRequest.error}
          empty={!deploymentRequest.data}
        >
          {deploymentRequest.data ? (
            <div className="deployment-request-briefing">
              <header>
                <div className="deployment-request-briefing__identity">
                  <span>Aanvraagdossier</span>
                  <strong>{deploymentRequest.data.title}</strong>
                  <small>{deploymentRequest.data.subject_type_label}</small>
                </div>
                <span>Bijgewerkt {formatDateTime(deploymentRequest.data.updated_at)}</span>
              </header>
              <p className="deployment-request-briefing__visibility">
                <Eye size={15} aria-hidden /> Onderwerp en gegevens hieronder zijn zichtbaar voor piloten.
              </p>
              {pilotVisibleAnswers.length > 0 ? (
                <dl className="deployment-request-briefing__answers">
                  {pilotVisibleAnswers.map((answer) => (
                    <div key={answer.key}>
                      <dt>{answer.label}</dt>
                      <dd>{answer.display_value}</dd>
                    </div>
                  ))}
                </dl>
              ) : (
                <div className="empty-panel">Nog geen belangrijke inzetinformatie vastgelegd.</div>
              )}
              {panelMessage ? <p className="form-note" role="status">{panelMessage}</p> : null}
            </div>
          ) : null}
        </ResourceState>
      </Panel>

      {editModalOpen && deploymentRequest.data ? (
        <div className="modal-backdrop" role="presentation">
          <section
            className="modal modal--deployment-request"
            role="dialog"
            aria-modal="true"
            aria-labelledby="deployment-request-edit-title"
          >
            <header className="modal__header">
              <div>
                <h2 id="deployment-request-edit-title">Aanvraag aanvullen</h2>
                <span>
                  Antwoorden, prioriteit en inzetvoorstel worden veilig opgeslagen zonder
                  een nieuwe alarmering te starten.
                </span>
              </div>
              <button
                className="icon-button"
                type="button"
                onClick={() => void finishEditing(false)}
                disabled={finishingEdit}
                aria-label="Aanvraag opslaan en sluiten"
              >
                <X size={18} />
              </button>
            </header>
            <ResourceState
              loading={workflow.loading}
              error={workflow.error}
              empty={false}
            >
              <DeploymentRequestWorkspace
                ref={workspaceRef}
                deploymentRequest={deploymentRequest.data}
                workflow={workflow.data ?? null}
                canManage={canManage}
                saveEndpoint={endpoint}
                allowPreparedEditing
                interactionDisabled={finishingEdit}
                onDeploymentRequestChange={deploymentRequest.mutate}
                onChangesQueued={recordQueuedChanges}
                onRefresh={reloadDeploymentRequestSilently}
              />
            </ResourceState>
            <footer className="deployment-request-modal__footer">
              <div>
                {canSendPilotChanges ? (
                  <label className="checkbox-card deployment-request-modal__send-choice">
                    <input
                      type="checkbox"
                      checked={sendPilotChanges}
                      disabled={finishingEdit}
                      onChange={(event) => setSendPilotChanges(event.target.checked)}
                    />
                    <span>
                      <strong>Gewijzigde pilootinformatie ook versturen</strong>
                      <small>
                        Als nadere info naar {additionalInfoRecipientCount} opkomende gebruiker(s). Privévelden worden nooit meegestuurd.
                      </small>
                    </span>
                  </label>
                ) : additionalInfoRecipientCount === 0 ? (
                  <p className="form-note">
                    Nadere info kan worden verstuurd zodra ten minste één gebruiker opkomt of onderweg is.
                  </p>
                ) : (
                  <p className="form-note">
                    De aanvraag wordt bijgewerkt; deze handeling verstuurt geen nieuwe vooralarm of alarmering.
                  </p>
                )}
                {editError ? <p className="form-error" role="alert">{editError}</p> : null}
              </div>
              <div className="actions-row">
                <button
                  className="secondary-button"
                  type="button"
                  onClick={() => void finishEditing(false)}
                  disabled={finishingEdit}
                >
                  Sluiten
                </button>
                <button
                  className="primary-button"
                  type="button"
                  onClick={() => void finishEditing(sendPilotChanges)}
                  disabled={finishingEdit || workflow.loading || workflow.data === null}
                >
                  {sendPilotChanges ? <MessageSquare size={16} /> : <Save size={16} />}
                  {finishingEdit
                    ? 'Opslaan...'
                    : sendPilotChanges
                      ? 'Wijzigingen opslaan en info versturen'
                      : 'Wijzigingen opslaan en sluiten'}
                </button>
              </div>
            </footer>
          </section>
        </div>
      ) : null}
    </>
  );
}
