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
  makeIntakeMutationId,
  type IntakeDossier,
  type IntakeSubjectType,
  type IntakeWorkflowRevision,
} from './intakeWorkflow';

interface CreateAttempt {
  subjectType: IntakeSubjectType;
  mutationId: string;
}

export function IntakeCreatePage() {
  const router = useRouter();
  const { api } = useAuth();
  const workflow = useApiResource<IntakeWorkflowRevision>('/intake-workflow/config');
  const [attempt, setAttempt] = useState<CreateAttempt | null>(null);
  const [error, setError] = useState<string | null>(null);

  const createDossier = async (nextAttempt: CreateAttempt) => {
    setAttempt(nextAttempt);
    setError(null);
    try {
      const response = await api.post<IntakeDossier>('/intake-dossiers', {
        subject_type: nextAttempt.subjectType,
        client_mutation_id: nextAttempt.mutationId,
      });
      router.replace(`/meldingen/${response.data.id}`);
    } catch (caught) {
      setError(caught instanceof ApiClientError ? caught.message : 'De melding kon niet worden gestart.');
    }
  };

  return (
    <div className="page-stack intake-create-page">
      <Panel
        title="Nieuwe melding"
        action={<Link className="secondary-button" href="/meldingen">Terug naar meldingen</Link>}
      >
        <div className="intake-create-state" aria-live="polite">
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
              <strong>De melding is nog niet gestart.</strong>
              <span>{error}</span>
              <button className="primary-button" type="button" onClick={() => attempt && void createDossier(attempt)}>
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
              <strong>Meldingsdossier openen</strong>
              <span>De uitvraag wordt direct veilig op de server vastgelegd.</span>
            </>
          ) : workflow.data ? (
            <>
              <div className="intake-create-state__heading">
                <strong>Wie of wat zoeken we?</strong>
                <span>Kies eerst het onderwerp. Daarna wordt het meldingsdossier op de server aangemaakt.</span>
              </div>
              <div className="intake-create-subjects">
                {workflow.data.configuration.subject_types.map((subject) => (
                  <button
                    type="button"
                    key={subject.key}
                    onClick={() => void createDossier({
                      subjectType: subject.key,
                      mutationId: makeIntakeMutationId(),
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

function subjectIcon(subjectType: IntakeSubjectType): ReactNode {
  if (subjectType === 'person') return <UserRoundSearch size={26} aria-hidden />;
  if (subjectType === 'animal') return <PawPrint size={26} aria-hidden />;
  return <Box size={26} aria-hidden />;
}
