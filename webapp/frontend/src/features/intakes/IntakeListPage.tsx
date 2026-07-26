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
  intakeDossierStatusLabel,
  intakeDossierStatusTone,
  intakeDossierTitle,
  intakePriorityLabel,
  intakePriorityTone,
  type IntakeDossier,
} from './intakeWorkflow';

const DOSSIERS_PER_PAGE = 50;
type IntakeListView = 'open' | 'closed';

export function IntakeListPage() {
  const { hasPermission } = useAuth();
  const [page, setPage] = useState(1);
  const [view, setView] = useState<IntakeListView>('open');
  const dossiers = useApiResource<IntakeDossier[]>(
    `/intake-dossiers?status=${view}&per_page=${DOSSIERS_PER_PAGE}&page=${page}`,
  );
  const reloadDossiersSilently = dossiers.silentReload;
  const canManage = hasPermission('incidents.manage');
  const list = dossiers.data ?? [];
  const pagination = intakePagination(dossiers.meta);
  const dossierCount = pagination?.total ?? list.length;
  const urgentCount = list.filter((dossier) => (
    dossier.decided_priority === 'urgent' || dossier.triage.recommended_priority === 'urgent'
  )).length;
  const undecidedCount = list.filter((dossier) => dossier.decided_priority === null).length;
  const incompleteCount = list.filter((dossier) => dossier.triage.state === 'incomplete').length;

  useEffect(() => {
    const timer = window.setInterval(() => void reloadDossiersSilently(), 30_000);
    return () => window.clearInterval(timer);
  }, [reloadDossiersSilently]);

  useEffect(() => {
    if (pagination !== null && page > pagination.last_page) {
      setPage(Math.max(1, pagination.last_page));
    }
  }, [page, pagination]);

  return (
    <div className="page-stack intake-list-page">
      <RealtimeBridge onIntakeEvent={() => void reloadDossiersSilently()} />
      <Panel
        title="Meldingen"
        action={canManage ? (
          <Link className="primary-button" href="/meldingen/new">
            <FilePlus2 size={17} /> Nieuwe melding
          </Link>
        ) : null}
      >
        <ResourceState loading={dossiers.loading} error={dossiers.error} empty={false}>
          <div className="intake-list">
            <div className="segmented-control" role="group" aria-label="Meldingenweergave">
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

            <div className="intake-list__summary" aria-label="Meldingen samenvatting">
              <Summary label={view === 'open' ? 'Totaal in uitvraag' : 'Totaal afgesloten'} value={dossierCount} />
              <Summary label="Urgent op deze pagina" value={urgentCount} />
              <Summary label="Te beoordelen op deze pagina" value={undecidedCount} />
              <Summary label="Onvolledig op deze pagina" value={incompleteCount} />
            </div>

            {list.length > 0 ? (
              <div className="intake-card-grid">
                {list.map((dossier) => (
                  <Link className="intake-card" href={`/meldingen/${dossier.id}`} key={dossier.id}>
                    <header>
                      <span className="intake-card__subject">
                        <UserSearch size={16} aria-hidden />
                        {dossier.subject_type_label}
                      </span>
                      <StatusPill
                        value={intakeDossierStatusLabel(dossier.status)}
                        tone={intakeDossierStatusTone(dossier.status)}
                      />
                    </header>
                    <strong>{intakeDossierTitle(dossier)}</strong>
                    <div className="intake-card__priority">
                      <span>Prioriteit</span>
                      <StatusPill
                        value={intakePriorityLabel(dossier.decided_priority ?? dossier.triage.recommended_priority)}
                        tone={intakePriorityTone(dossier.decided_priority ?? dossier.triage.recommended_priority)}
                      />
                    </div>
                    <footer>
                      <span><Clock3 size={15} aria-hidden /> {formatDateTime(dossier.updated_at)}</span>
                      {dossier.incident_id ? <span><Link2 size={15} aria-hidden /> Gekoppeld incident</span> : null}
                    </footer>
                  </Link>
                ))}
              </div>
            ) : (
              <div className="empty-panel">
                <ClipboardList size={28} aria-hidden />
                <strong>{view === 'open' ? 'Geen open meldingen' : 'Geen afgesloten meldingen'}</strong>
                <span>
                  {view === 'open'
                    ? 'Start een nieuwe uitvraag zodra een melding binnenkomt.'
                    : 'Afgesloten meldingen blijven hier raadpleegbaar.'}
                </span>
              </div>
            )}

            {pagination !== null && pagination.last_page > 1 ? (
              <nav className="actions-row" aria-label="Paginering meldingen">
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

function intakePagination(meta: unknown): PaginationMeta | null {
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
