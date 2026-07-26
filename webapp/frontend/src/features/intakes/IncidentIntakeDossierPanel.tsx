'use client';

import Link from 'next/link';
import { useEffect } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { useApiResource } from '../../lib/useApiResource';
import { IntakeWorkspace } from './IntakeWorkspace';
import type { IntakeDossier, IntakeWorkflowRevision } from './intakeWorkflow';

export function IncidentIntakeDossierPanel(props: {
  incidentId: string;
  canManage: boolean;
  refreshVersion?: number;
}) {
  const { incidentId, canManage, refreshVersion = 0 } = props;
  const endpoint = `/incidents/${incidentId}/intake-dossier`;
  const dossier = useApiResource<IntakeDossier>(endpoint, incidentId !== '');
  const reloadDossierSilently = dossier.silentReload;
  const workflow = useApiResource<IntakeWorkflowRevision>(
    dossier.data
      ? `/intake-workflow/config?dossier_id=${encodeURIComponent(dossier.data.id)}`
      : '/intake-workflow/config',
    canManage && dossier.data !== null,
  );

  useEffect(() => {
    if (incidentId === '') return undefined;
    const timer = window.setInterval(() => void reloadDossierSilently(), 20_000);
    return () => window.clearInterval(timer);
  }, [incidentId, reloadDossierSilently]);

  useEffect(() => {
    if (refreshVersion > 0) void reloadDossierSilently();
  }, [refreshVersion, reloadDossierSilently]);

  return (
    <Panel
      title="Uitvraag"
      action={dossier.data ? (
        <Link className="secondary-button" href={`/meldingen/${dossier.data.id}`}>
          Volledig dossier
        </Link>
      ) : null}
    >
      <ResourceState
        loading={dossier.loading || (canManage && dossier.data !== null && workflow.loading)}
        error={dossier.error ?? (canManage ? workflow.error : null)}
        empty={!dossier.data}
      >
        {dossier.data ? (
          <IntakeWorkspace
            dossier={dossier.data}
            workflow={workflow.data ?? null}
            canManage={canManage}
            saveEndpoint={endpoint}
            compact
            allowPromotedEditing
            onDossierChange={dossier.mutate}
            onRefresh={reloadDossierSilently}
          />
        ) : null}
      </ResourceState>
    </Panel>
  );
}
