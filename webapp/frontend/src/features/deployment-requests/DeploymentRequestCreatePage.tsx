'use client';

import { Box, Loader2, PawPrint, RotateCcw, UserRoundSearch } from 'lucide-react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState, type ReactNode } from 'react';
import { Panel } from '../../components/Panel';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import {
  makeDeploymentRequestMutationId,
  type DeploymentRequest,
  type DeploymentRequestSubjectType,
  type DeploymentRequestWorkflowRevision,
} from './deploymentRequestWorkflow';

interface CreateAttempt {
  subjectType: DeploymentRequestSubjectType;
  mutationId: string;
}

export function DeploymentRequestCreatePage() {
  const router = useRouter();
  const { api } = useAuth();
  const workflow = useApiResource<DeploymentRequestWorkflowRevision>('/deployment-request-workflow/config');
  const [attempt, setAttempt] = useState<CreateAttempt | null>(null);
  const [error, setError] = useState<string | null>(null);

  const createDeploymentRequest = async (nextAttempt: CreateAttempt) => {
    setAttempt(nextAttempt);
    setError(null);
    try {
      const response = await api.post<DeploymentRequest>('/deployment-requests', {
        subject_type: nextAttempt.subjectType,
        client_mutation_id: nextAttempt.mutationId,
      });
      router.replace(`/aanvragen/${response.data.id}`);
    } catch (caught) {
      setError(caught instanceof ApiClientError ? caught.message : 'De aanvraag kon niet worden gestart.');
    }
  };

  return (
    <div className="page-stack deployment-request-create-page">
      <Panel
        title="Nieuwe aanvraag"
        action={<Link className="secondary-button" href="/aanvragen">Terug naar aanvragen</Link>}
      >
        <div className="deployment-request-create-state" aria-live="polite">
          {workflow.error ? (
            <>
              <strong>Het uitvraagformulier kon niet worden geladen.</strong>
              <span>{workflow.error}</span>
              <button className="secondary-button" type="button" onClick={() => void workflow.reload()}>
                <RotateCcw size={16} /> Opnieuw laden
              </button>
            </>
          ) : error ? (
            <>
              <strong>De aanvraag is nog niet gestart.</strong>
              <span>{error}</span>
              <button className="primary-button" type="button" onClick={() => attempt && void createDeploymentRequest(attempt)}>
                <RotateCcw size={16} /> Opnieuw proberen
              </button>
              <button className="secondary-button" type="button" onClick={() => {
                setAttempt(null);
                setError(null);
              }}>
                Ander type kiezen
              </button>
            </>
          ) : attempt ? (
            <>
              <Loader2 className="spin" size={24} aria-hidden />
              <strong>Aanvraag openen</strong>
              <span>De uitvraag wordt direct veilig op de server vastgelegd.</span>
            </>
          ) : workflow.data ? (
            <>
              <div className="deployment-request-create-state__heading">
                <strong>Wie of wat zoeken we?</strong>
                <span>Kies eerst het onderwerp. Daarna wordt de aanvraag op de server aangemaakt.</span>
              </div>
              <div className="deployment-request-create-subjects">
                {workflow.data.configuration.subject_types.map((subject) => (
                  <button
                    type="button"
                    key={subject.key}
                    onClick={() => void createDeploymentRequest({
                      subjectType: subject.key,
                      mutationId: makeDeploymentRequestMutationId(),
                    })}
                  >
                    {subjectIcon(subject.key)}
                    <span>{subject.label}</span>
                  </button>
                ))}
              </div>
            </>
          ) : (
            <>
              <Loader2 className="spin" size={24} aria-hidden />
              <strong>Uitvraag laden</strong>
            </>
          )}
        </div>
      </Panel>
    </div>
  );
}

function subjectIcon(subjectType: DeploymentRequestSubjectType): ReactNode {
  if (subjectType === 'person') return <UserRoundSearch size={26} aria-hidden />;
  if (subjectType === 'animal') return <PawPrint size={26} aria-hidden />;
  return <Box size={26} aria-hidden />;
}
