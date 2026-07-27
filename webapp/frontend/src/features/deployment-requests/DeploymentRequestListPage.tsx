'use client';

import { ChevronLeft, ChevronRight, ClipboardList, Clock3, FilePlus2, Link2, UserSearch } from 'lucide-react';
import Link from 'next/link';
import { useEffect, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import { RealtimeBridge } from '../realtime/RealtimeBridge';
import type { PaginationMeta } from '../../types/api';
import {
  deploymentRequestPriorityLabel,
  deploymentRequestPriorityTone,
  deploymentRequestStatusLabel,
  deploymentRequestStatusTone,
  deploymentRequestTitle,
  type DeploymentRequest,
} from './deploymentRequestWorkflow';

const DEPLOYMENT_REQUESTS_PER_PAGE = 50;
type DeploymentRequestListView = 'open' | 'closed';

export function DeploymentRequestListPage() {
  const { hasPermission } = useAuth();
  const [page, setPage] = useState(1);
  const [view, setView] = useState<DeploymentRequestListView>('open');
  const deploymentRequests = useApiResource<DeploymentRequest[]>(
    `/deployment-requests?status=${view}&per_page=${DEPLOYMENT_REQUESTS_PER_PAGE}&page=${page}`,
  );
  const reloadDeploymentRequestsSilently = deploymentRequests.silentReload;
  const canManage = hasPermission('deployments.manage');
  const list = deploymentRequests.data ?? [];
  const pagination = deploymentRequestPagination(deploymentRequests.meta);
  const deploymentRequestCount = pagination?.total ?? list.length;
  const urgentCount = list.filter((deploymentRequest) => (
    deploymentRequest.decided_priority === 'urgent'
      || deploymentRequest.triage.recommended_priority === 'urgent'
  )).length;
  const undecidedCount = list.filter((deploymentRequest) => deploymentRequest.decided_priority === null).length;
  const incompleteCount = list.filter((deploymentRequest) => deploymentRequest.triage.state === 'incomplete').length;

  useEffect(() => {
    const timer = window.setInterval(() => void reloadDeploymentRequestsSilently(), 30_000);
    return () => window.clearInterval(timer);
  }, [reloadDeploymentRequestsSilently]);

  useEffect(() => {
    if (pagination !== null && page > pagination.last_page) {
      setPage(Math.max(1, pagination.last_page));
    }
  }, [page, pagination]);

  return (
    <div className="page-stack deployment-request-list-page">
      <RealtimeBridge onDeploymentRequestEvent={() => void reloadDeploymentRequestsSilently()} />
      <Panel
        title="Aanvragen"
        action={canManage ? (
          <Link className="primary-button" href="/aanvragen/new">
            <FilePlus2 size={17} /> Nieuwe aanvraag
          </Link>
        ) : null}
      >
        <ResourceState loading={deploymentRequests.loading} error={deploymentRequests.error} empty={false}>
          <div className="deployment-request-list">
            <div className="segmented-control" role="group" aria-label="Aanvragenweergave">
              <button
                className={`segmented-control__item${view === 'open' ? ' segmented-control__item--active' : ''}`}
                type="button"
                aria-pressed={view === 'open'}
                onClick={() => {
                  setView('open');
                  setPage(1);
                }}
              >
                Open
              </button>
              <button
                className={`segmented-control__item${view === 'closed' ? ' segmented-control__item--active' : ''}`}
                type="button"
                aria-pressed={view === 'closed'}
                onClick={() => {
                  setView('closed');
                  setPage(1);
                }}
              >
                Afgesloten
              </button>
            </div>

            <div className="deployment-request-list__summary" aria-label="Samenvatting aanvragen">
              <Summary label={view === 'open' ? 'Totaal in uitvraag' : 'Totaal afgesloten'} value={deploymentRequestCount} />
              <Summary label="Urgent op deze pagina" value={urgentCount} />
              <Summary label="Te beoordelen op deze pagina" value={undecidedCount} />
              <Summary label="Onvolledig op deze pagina" value={incompleteCount} />
            </div>

            {list.length > 0 ? (
              <div className="deployment-request-card-grid">
                {list.map((deploymentRequest) => (
                  <Link
                    className="deployment-request-card"
                    href={`/aanvragen/${deploymentRequest.id}`}
                    key={deploymentRequest.id}
                  >
                    <header>
                      <span className="deployment-request-card__subject">
                        <UserSearch size={16} aria-hidden />
                        {deploymentRequest.subject_type_label}
                      </span>
                      <StatusPill
                        value={deploymentRequestStatusLabel(deploymentRequest.status)}
                        tone={deploymentRequestStatusTone(deploymentRequest.status)}
                      />
                    </header>
                    <strong>{deploymentRequestTitle(deploymentRequest)}</strong>
                    <div className="deployment-request-card__priority">
                      <span>Prioriteit</span>
                      <StatusPill
                        value={deploymentRequestPriorityLabel(
                          deploymentRequest.decided_priority
                            ?? deploymentRequest.triage.recommended_priority,
                        )}
                        tone={deploymentRequestPriorityTone(
                          deploymentRequest.decided_priority
                            ?? deploymentRequest.triage.recommended_priority,
                        )}
                      />
                    </div>
                    <footer>
                      <span><Clock3 size={15} aria-hidden /> {formatDateTime(deploymentRequest.updated_at)}</span>
                      {deploymentRequest.deployment_id ? <span><Link2 size={15} aria-hidden /> Gekoppelde inzet</span> : null}
                    </footer>
                  </Link>
                ))}
              </div>
            ) : (
              <div className="empty-panel">
                <ClipboardList size={28} aria-hidden />
                <strong>{view === 'open' ? 'Geen open aanvragen' : 'Geen afgesloten aanvragen'}</strong>
                <span>
                  {view === 'open'
                    ? 'Start een nieuwe aanvraag zodra een hulpvraag binnenkomt.'
                    : 'Afgesloten aanvragen blijven hier raadpleegbaar.'}
                </span>
              </div>
            )}

            {pagination !== null && pagination.last_page > 1 ? (
              <nav className="actions-row" aria-label="Paginering aanvragen">
                <button
                  className="secondary-button"
                  type="button"
                  disabled={pagination.current_page <= 1}
                  onClick={() => setPage((current) => Math.max(1, current - 1))}
                >
                  <ChevronLeft size={16} aria-hidden /> Nieuwer
                </button>
                <span>Pagina {pagination.current_page} van {pagination.last_page}</span>
                <button
                  className="secondary-button"
                  type="button"
                  disabled={pagination.current_page >= pagination.last_page}
                  onClick={() => setPage((current) => Math.min(pagination.last_page, current + 1))}
                >
                  Ouder <ChevronRight size={16} aria-hidden />
                </button>
              </nav>
            ) : null}
          </div>
        </ResourceState>
      </Panel>
    </div>
  );
}

function deploymentRequestPagination(meta: unknown): PaginationMeta | null {
  if (
    meta === null
    || typeof meta !== 'object'
    || !('current_page' in meta)
    || !('last_page' in meta)
    || !('per_page' in meta)
    || !('total' in meta)
  ) {
    return null;
  }

  const candidate = meta as Record<string, unknown>;
  return [candidate.current_page, candidate.last_page, candidate.per_page, candidate.total]
    .every((value) => typeof value === 'number' && Number.isFinite(value))
    ? candidate as unknown as PaginationMeta
    : null;
}

function Summary({ label, value }: { label: string; value: number }) {
  return (
    <div>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}
