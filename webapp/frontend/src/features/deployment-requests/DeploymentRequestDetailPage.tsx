'use client';

import { Trash2, X } from 'lucide-react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import { RealtimeBridge } from '../realtime/RealtimeBridge';
import { DeploymentRequestWorkspace } from './DeploymentRequestWorkspace';
import type {
  DeploymentRequest,
  DeploymentRequestWorkflowRevision,
} from './deploymentRequestWorkflow';

export function DeploymentRequestDetailPage({
  deploymentRequestId,
}: {
  deploymentRequestId: string;
}) {
  const router = useRouter();
  const { api, hasPermission } = useAuth();
  const canManage = hasPermission('deployments.manage');
  const canDelete = canManage && hasPermission('deployment-requests.delete');
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const deploymentRequest = useApiResource<DeploymentRequest>(
    `/deployment-requests/${deploymentRequestId}`,
    deploymentRequestId !== '',
  );
  const reloadDeploymentRequestSilently = deploymentRequest.silentReload;
  const workflow = useApiResource<DeploymentRequestWorkflowRevision>(
    `/deployment-request-workflow/config?deployment_request_id=${encodeURIComponent(deploymentRequestId)}`,
    canManage && deploymentRequestId !== '',
  );

  useEffect(() => {
    if (!deploymentRequestId) return undefined;
    const timer = window.setInterval(() => void reloadDeploymentRequestSilently(), 20_000);
    return () => window.clearInterval(timer);
  }, [deploymentRequestId, reloadDeploymentRequestSilently]);

  const request = deploymentRequest.data;
  const deleteBlockedByDeployment = request !== null && request.deployment_id !== null;

  const openDeleteDialog = () => {
    if (request === null || request.deployment_id !== null) return;
    setDeleteError(null);
    setDeleteDialogOpen(true);
  };
  const closeDeleteDialog = useCallback(() => {
    setDeleteDialogOpen(false);
  }, []);

  const deleteDeploymentRequest = async () => {
    if (request === null || request.deployment_id !== null || deleting) return;

    setDeleting(true);
    setDeleteError(null);
    try {
      await api.delete(`/deployment-requests/${request.id}`, {
        lock_version: request.lock_version,
      });
      router.replace('/aanvragen');
    } catch (caught) {
      setDeleteError(
        caught instanceof ApiClientError
          ? caught.message
          : 'De aanvraag kon niet worden verwijderd.',
      );
      setDeleting(false);
    }
  };

  return (
    <div className="page-stack deployment-request-detail-page">
      <RealtimeBridge onDeploymentRequestEvent={() => void reloadDeploymentRequestSilently()} />
      <Panel
        title={request?.title ?? 'Aanvraag'}
        action={(
          <div className="table-actions">
            <Link className="secondary-button" href="/aanvragen">Terug naar aanvragen</Link>
            {canDelete && request ? (
              <button
                className="danger-button"
                type="button"
                disabled={deleting || deleteBlockedByDeployment}
                aria-describedby={deleteBlockedByDeployment ? 'deployment-request-delete-blocked' : undefined}
                title={deleteBlockedByDeployment ? 'Een aan een inzet gekoppelde aanvraag kan niet worden verwijderd.' : undefined}
                onClick={openDeleteDialog}
              >
                <Trash2 size={16} aria-hidden />
                Verwijderen
              </button>
            ) : null}
          </div>
        )}
      >
        <ResourceState
          loading={deploymentRequest.loading || (canManage && workflow.loading)}
          error={deploymentRequest.error ?? (canManage ? workflow.error : null)}
          empty={!deploymentRequest.data}
        >
          {request ? (
            <>
              {canDelete && deleteBlockedByDeployment ? (
                <p className="form-note deployment-request-delete-help" id="deployment-request-delete-blocked">
                  Deze aanvraag is gekoppeld aan een inzet en kan daarom niet afzonderlijk worden verwijderd.
                </p>
              ) : null}
              <DeploymentRequestWorkspace
                deploymentRequest={request}
                workflow={workflow.data ?? null}
                canManage={canManage}
                onDeploymentRequestChange={deploymentRequest.mutate}
                onRefresh={reloadDeploymentRequestSilently}
              />
            </>
          ) : null}
        </ResourceState>
      </Panel>
      {deleteDialogOpen && request && request.deployment_id === null && canDelete ? (
        <DeleteDeploymentRequestDialog
          deploymentRequest={request}
          deleting={deleting}
          error={deleteError}
          onCancel={closeDeleteDialog}
          onConfirm={() => void deleteDeploymentRequest()}
        />
      ) : null}
    </div>
  );
}

function DeleteDeploymentRequestDialog({
  deploymentRequest,
  deleting,
  error,
  onCancel,
  onConfirm,
}: {
  deploymentRequest: DeploymentRequest;
  deleting: boolean;
  error: string | null;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  const dialogRef = useRef<HTMLElement>(null);
  const cancelButtonRef = useRef<HTMLButtonElement>(null);
  const deletingRef = useRef(deleting);

  useEffect(() => {
    deletingRef.current = deleting;
  }, [deleting]);

  useEffect(() => {
    const previouslyFocused = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    cancelButtonRef.current?.focus();

    const keepFocusInDialog = (event: KeyboardEvent) => {
      const dialog = dialogRef.current;
      if (dialog === null) return;

      if (event.key === 'Escape') {
        if (!deletingRef.current) {
          event.preventDefault();
          onCancel();
        }
        return;
      }
      if (event.key !== 'Tab') return;

      const focusable = Array.from(dialog.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
      )).filter((element) => element.tabIndex >= 0);
      if (focusable.length === 0) {
        event.preventDefault();
        dialog.focus();
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      const active = document.activeElement;
      if (event.shiftKey && (active === first || !dialog.contains(active))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (active === last || !dialog.contains(active))) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', keepFocusInDialog);
    return () => {
      document.removeEventListener('keydown', keepFocusInDialog);
      if (previouslyFocused?.isConnected) previouslyFocused.focus();
    };
  }, [onCancel]);

  return (
    <div className="modal-backdrop" role="presentation">
      <section
        ref={dialogRef}
        className="modal modal--narrow"
        role="dialog"
        tabIndex={-1}
        aria-modal="true"
        aria-labelledby="delete-deployment-request-title"
        aria-describedby="delete-deployment-request-description"
      >
        <header className="modal__header">
          <h2 id="delete-deployment-request-title">Aanvraag verwijderen</h2>
          <button className="icon-button" type="button" onClick={onCancel} aria-label="Sluiten" disabled={deleting}>
            <X size={18} aria-hidden />
          </button>
        </header>
        <div className="confirm-dialog">
          <p id="delete-deployment-request-description">
            Weet je zeker dat je <strong>{deploymentRequest.title}</strong> permanent wilt verwijderen?
          </p>
          <p className="muted-text">
            De uitvraag, antwoorden en beoordelingsgegevens verdwijnen definitief. Dit kan niet ongedaan worden gemaakt.
          </p>
          {error ? <p className="form-error" role="alert">{error}</p> : null}
        </div>
        <div className="actions-row">
          <button ref={cancelButtonRef} className="secondary-button" type="button" onClick={onCancel} disabled={deleting}>
            Annuleren
          </button>
          <button className="danger-button" type="button" onClick={onConfirm} disabled={deleting}>
            {deleting ? 'Verwijderen...' : 'Ja, aanvraag verwijderen'}
          </button>
        </div>
      </section>
    </div>
  );
}
