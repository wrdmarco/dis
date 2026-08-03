import { expect, test } from 'playwright/test';
import {
  allowsDeploymentPilotMutations,
  deploymentAdditionalInfoRecipientCount,
  deploymentPilotCandidatePagination,
  deploymentPilotLinkSuccessMessage,
  deploymentPilotTeamsLabel,
  filterDeploymentPilotCandidates,
} from '../src/features/deployments/deploymentPilotPresentation';
import type { DeploymentPilotCandidate, DeploymentPilotLinkResult, DispatchRequest } from '../src/types/api';

const ocpTeam = {
  id: 'team-ocp',
  code: 'OCP',
  name: 'Operationeel Coördinatie Platform',
  type: 'operational',
  is_operational: true,
};

const candidates: DeploymentPilotCandidate[] = [
  {
    id: 'pilot-noor',
    name: 'Noor de Vries',
    email: 'noor@example.test',
    teams: [ocpTeam],
  },
  {
    id: 'pilot-samira',
    name: 'Samira Jansen',
    email: 'samira@example.test',
    teams: [],
  },
];

test('filters pilot candidates by name and email without changing the server list', () => {
  expect(filterDeploymentPilotCandidates(candidates, ' noor ')).toEqual([candidates[0]]);
  expect(filterDeploymentPilotCandidates(candidates, 'SAMIRA@')).toEqual([candidates[1]]);
  expect(filterDeploymentPilotCandidates(candidates, '')).toBe(candidates);
  expect(filterDeploymentPilotCandidates(candidates, 'niet gevonden')).toEqual([]);
});

test('normalizes candidate pagination metadata with a backwards-compatible fallback', () => {
  expect(deploymentPilotCandidatePagination({
    current_page: 2,
    last_page: 4,
    per_page: 25,
    total: 82,
  }, 25)).toEqual({
    current_page: 2,
    last_page: 4,
    per_page: 25,
    total: 82,
  });
  expect(deploymentPilotCandidatePagination(undefined, 3)).toEqual({
    current_page: 1,
    last_page: 1,
    per_page: 3,
    total: 3,
  });
  expect(deploymentPilotCandidatePagination({
    current_page: 2,
    last_page: 1,
    per_page: 25,
    total: 25,
  }, 0)).toEqual({
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 25,
  });
});

test('allows pilot mutations only for active, dispatching and in-progress operational deployments', () => {
  expect(allowsDeploymentPilotMutations({ status: 'active', is_test: false })).toBe(true);
  expect(allowsDeploymentPilotMutations({ status: 'dispatching', is_test: false })).toBe(true);
  expect(allowsDeploymentPilotMutations({ status: 'in_progress', is_test: false })).toBe(true);
  expect(allowsDeploymentPilotMutations({ status: 'draft', is_test: false })).toBe(false);
  expect(allowsDeploymentPilotMutations({ status: 'resolved', is_test: false })).toBe(false);
  expect(allowsDeploymentPilotMutations({ status: 'cancelled', is_test: false })).toBe(false);
  expect(allowsDeploymentPilotMutations({ status: 'active', is_test: true })).toBe(false);
  expect(allowsDeploymentPilotMutations(null)).toBe(false);
});

test('counts unique dispatch attendees and manually linked pilots for additional information', () => {
  const dispatch = {
    recipients: [
      { user_id: 'pilot-noor', response_status: 'accepted' },
      { user_id: 'pilot-noor', response_status: 'accepted' },
      { user_id: 'pilot-samira', response_status: 'declined', user: { statuses: [{ status: 'en_route' }] } },
      { user_id: 'pilot-stays-home', response_status: 'declined' },
    ],
  } as DispatchRequest;
  const pilots = [
    linkResult({ user_id: 'pilot-noor', source: 'manual' }),
    linkResult({ id: 'assignment-manual', user_id: 'pilot-manual', source: 'manual' }),
    linkResult({ id: 'assignment-deleted', user_id: null, source: 'manual' }),
  ];

  expect(deploymentAdditionalInfoRecipientCount(dispatch, pilots)).toBe(3);
});

test('presents team membership compactly', () => {
  expect(deploymentPilotTeamsLabel([ocpTeam])).toBe('OCP');
  expect(deploymentPilotTeamsLabel([])).toBe('Geen team vermeld');
  expect(deploymentPilotTeamsLabel()).toBe('Geen team vermeld');
});

test('reports queued, unreachable and backwards-compatible notification outcomes without calling it an alarm', () => {
  expect(deploymentPilotLinkSuccessMessage(linkResult(), 'Noor', { notification_queued_tokens: 2 }))
    .toBe('Noor is gekoppeld en het informatieve pushbericht is ingepland; er klinkt geen alarm.');
  expect(deploymentPilotLinkSuccessMessage(linkResult(), 'Noor', { notification_queued_tokens: 0, warnings: ['Melding niet ingepland.'] }))
    .toBe('Noor is gekoppeld, maar het informatieve pushbericht kon niet worden ingepland.');
  expect(deploymentPilotLinkSuccessMessage(linkResult({ notification_queued_tokens: 1 }), 'Noor'))
    .toContain('pushbericht is ingepland');
  expect(deploymentPilotLinkSuccessMessage(linkResult({ notification: { queued_tokens: 1 } }), 'Noor'))
    .toContain('pushbericht is ingepland');
  expect(deploymentPilotLinkSuccessMessage(linkResult(), 'Noor'))
    .toBe('Noor is gekoppeld; het informatieve pushbericht wordt indien bereikbaar verzonden zonder alarm.');
  expect(deploymentPilotLinkSuccessMessage(linkResult({ notification_queued_tokens: -1 }), 'Noor'))
    .toBe('Noor is gekoppeld; het informatieve pushbericht wordt indien bereikbaar verzonden zonder alarm.');
});

function linkResult(overrides: Partial<DeploymentPilotLinkResult> = {}): DeploymentPilotLinkResult {
  return {
    id: 'assignment-noor',
    user_id: 'pilot-noor',
    source: 'manual',
    linked_at: '2026-08-03T12:00:00Z',
    user: {
      id: 'pilot-noor',
      name: 'Noor de Vries',
      email: 'noor@example.test',
      account_status: 'active',
      push_enabled: true,
      max_operator_devices: 3,
      two_factor_enabled: true,
    },
    ...overrides,
  };
}
