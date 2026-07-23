import type { IncidentTimelineItem } from '../../types/api';

export interface IncidentTimelinePresentation {
  action: string;
  actorLabel: string | null;
  detail: string | null;
}

export function presentIncidentTimelineItem(item: IncidentTimelineItem): IncidentTimelinePresentation {
  const label = cleanText(item.label);
  const description = cleanText(item.description);
  const action = description ?? label ?? 'Logboekregel';
  const actorName = cleanText(item.actor?.name) ?? cleanText(item.actor_name);
  const message = cleanText(item.message);

  return {
    action,
    actorLabel: actorName !== null && !containsText(action, actorName) ? `Door ${actorName}` : null,
    detail: message !== null && !sameText(message, action) ? message : null,
  };
}

function cleanText(value: string | null | undefined): string | null {
  if (typeof value !== 'string') {
    return null;
  }

  const cleaned = value.trim().replace(/\s+/g, ' ');

  return cleaned === '' ? null : cleaned;
}

function containsText(value: string, expected: string): boolean {
  return comparableText(value).includes(comparableText(expected));
}

function sameText(left: string, right: string): boolean {
  return comparableText(left) === comparableText(right);
}

function comparableText(value: string): string {
  return value.normalize('NFKC').toLocaleLowerCase('nl-NL').replace(/\s+/g, ' ').trim();
}
