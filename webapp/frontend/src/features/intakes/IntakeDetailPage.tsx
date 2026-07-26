'use client';

import Link from 'next/link';
import { useEffect } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import { RealtimeBridge } from '../realtime/RealtimeBridge';
import { IntakeWorkspace } from './IntakeWorkspace';
import type { IntakeDossier, IntakeWorkflowRevision } from './intakeWorkflow';

export function IntakeDetailPage({ intakeId }: { intakeId: string }) {
  const { hasPermission } = useAuth();
  const canManage = hasPermission('incidents.manage');
  const dossier = useApiResource<IntakeDossier>(`/intake-dossiers/${intakeId}`, intakeId !== '');
  const reloadDossierSilently = dossier.silentReload;
  const workflow = useApiResource<IntakeWorkflowRevision>(
    `/intake-workflow/config?dossier_id=${encodeURIComponent(intakeId)}`,
    canManage && intakeId !== '',
  );

  useEffect(() => {
    if (!intakeId) return undefined;
    const timer = window.setInterval(() => void reloadDossierSilently(), 20_000);
    return () => window.clearInterval(timer);
  }, [intakeId, reloadDossierSilently]);

  return (
    <div className="page-stack intake-detail-page">
      <RealtimeBridge onIntakeEvent={() => void reloadDossierSilently()} />
      <Panel
        title="Meldingsdossier"
        action={<Link className="secondary-button" href="/meldingen">Terug naar meldingen</Link>}
      >
        <ResourceState
          loading={dossier.loading || (canManage && workflow.loading)}
          error={dossier.error ?? (canManage ? workflow.error : null)}
          empty={!dossier.data}
        >
          {dossier.data ? (
            <IntakeWorkspace
              dossier={dossier.data}
              workflow={workflow.data ?? null}
              canManage={canManage}
              onDossierChange={dossier.mutate}
              onRefresh={reloadDossierSilently}
            />
          ) : null}
        </ResourceState>
      </Panel>
    </div>
  );
}
