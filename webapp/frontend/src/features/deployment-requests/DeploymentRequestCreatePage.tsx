'use client';

import { Box, Loader2, PawPrint, RotateCcw, UserRoundSearch } from 'lucide-react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useRef, useState, type FormEvent, type ReactNode } from 'react';
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
  title: string;
  subjectType: DeploymentRequestSubjectType;
  mutationId: string;
}

export function DeploymentRequestCreatePage() {
  const router = useRouter();
  const { api } = useAuth();
  const workflow = useApiResource<DeploymentRequestWorkflowRevision>('/deployment-request-workflow/config');
  const [title, setTitle] = useState('');
  const [subjectType, setSubjectType] = useState<DeploymentRequestSubjectType | null>(null);
  const [attempt, setAttempt] = useState<CreateAttempt | null>(null);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const creatingRef = useRef(false);

  const createDeploymentRequest = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (creatingRef.current || subjectType === null) return;

    const normalizedTitle = title.trim();
    if (normalizedTitle === '') {
      setError('Geef de aanvraag een herkenbare titel.');
      return;
    }

    const nextAttempt = attempt?.title === normalizedTitle && attempt.subjectType === subjectType
      ? attempt
      : {
          title: normalizedTitle,
          subjectType,
          mutationId: makeDeploymentRequestMutationId(),
        };
    setAttempt(nextAttempt);
    creatingRef.current = true;
    setCreating(true);
    setError(null);
    try {
      const response = await api.post<DeploymentRequest>('/deployment-requests', {
        title: nextAttempt.title,
        subject_type: nextAttempt.subjectType,
        client_mutation_id: nextAttempt.mutationId,
      });
      router.replace(`/aanvragen/${response.data.id}`);
    } catch (caught) {
      setError(caught instanceof ApiClientError ? caught.message : 'De aanvraag kon niet worden gestart.');
    } finally {
      creatingRef.current = false;
      setCreating(false);
    }
  };

  const updateTitle = (value: string) => {
    setTitle(value);
    setAttempt(null);
    setError(null);
  };

  const chooseSubjectType = (value: DeploymentRequestSubjectType) => {
    setSubjectType(value);
    setAttempt(null);
    setError(null);
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
          ) : workflow.data ? (
            <form className="deployment-request-create-form" onSubmit={createDeploymentRequest}>
              <div className="deployment-request-create-state__heading">
                <strong>Maak de aanvraag herkenbaar</strong>
                <span>De aanvraag wordt pas aangemaakt nadat je het formulier zelf verstuurt.</span>
              </div>
              <label className="deployment-request-create-title" htmlFor="deployment-request-create-title">
                <span>Titel *</span>
                <input
                  id="deployment-request-create-title"
                  type="text"
                  value={title}
                  required
                  maxLength={180}
                  autoComplete="off"
                  autoFocus
                  disabled={creating}
                  placeholder="Bijvoorbeeld Vermist persoon omgeving Stationsplein"
                  onChange={(event) => updateTitle(event.target.value)}
                />
                <small>Maximaal 180 tekens. Deze titel blijft zichtbaar in het aanvragenoverzicht en de gekoppelde inzet.</small>
              </label>
              <fieldset className="deployment-request-create-subject-fieldset">
                <legend>Wie of wat zoeken we? *</legend>
                <span>Kies het onderwerp dat bij deze aanvraag hoort.</span>
                <div className="deployment-request-create-subjects">
                  {workflow.data.configuration.subject_types.map((subject) => (
                    <button
                      type="button"
                      key={subject.key}
                      className={subjectType === subject.key ? 'deployment-request-create-subject--selected' : undefined}
                      aria-pressed={subjectType === subject.key}
                      disabled={creating}
                      onClick={() => chooseSubjectType(subject.key)}
                    >
                      {subjectIcon(subject.key)}
                      <span>{subject.label}</span>
                    </button>
                  ))}
                </div>
              </fieldset>
              {error ? <p className="form-error" role="alert">{error}</p> : null}
              <div className="actions-row deployment-request-create-actions">
                <Link className="secondary-button" href="/aanvragen">Annuleren</Link>
                <button
                  className="primary-button"
                  type="submit"
                  disabled={creating || subjectType === null || title.trim() === ''}
                >
                  {creating ? <Loader2 className="spin" size={17} aria-hidden /> : null}
                  {creating ? 'Aanvraag aanmaken...' : 'Aanvraag aanmaken'}
                </button>
              </div>
            </form>
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
