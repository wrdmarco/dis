import type { FcmToken } from '../types/api';

function isOperatorToken(token: FcmToken): boolean {
  return !(token.client_type ?? 'operator').startsWith('admin');
}

function deviceKey(token: FcmToken): string {
  return token.device_id?.trim() || token.token_hash?.trim() || token.id;
}

function compareLastSeenDesc(left: FcmToken, right: FcmToken): number {
  return (right.last_seen_at ?? '').localeCompare(left.last_seen_at ?? '');
}

function operatorDeviceGroups(tokens: FcmToken[]): FcmToken[][] {
  const groups = new Map<string, FcmToken[]>();

  for (const token of tokens) {
    if (!isOperatorToken(token)) {
      continue;
    }

    const key = deviceKey(token);
    groups.set(key, [...(groups.get(key) ?? []), token]);
  }

  return [...groups.values()];
}

export function uniqueOperatorDevices(tokens: FcmToken[]): FcmToken[] {
  return operatorDeviceGroups(tokens)
    .map((group) => [...group].sort(compareLastSeenDesc)[0])
    .filter((token): token is FcmToken => token !== undefined)
    .sort(compareLastSeenDesc);
}

export function onlineOperatorDeviceCount(tokens: FcmToken[]): number {
  return operatorDeviceGroups(tokens)
    .filter((group) => group.some((token) => token.is_active && token.is_online === true))
    .length;
}

export function activeOperatorDeviceCount(tokens: FcmToken[]): number {
  return operatorDeviceGroups(tokens)
    .filter((group) => group.some((token) => token.is_active))
    .length;
}

export function hasOnlineOperatorDevice(tokens: FcmToken[]): boolean {
  return onlineOperatorDeviceCount(tokens) > 0;
}

export function latestOperatorDevice(tokens: FcmToken[]): FcmToken | undefined {
  return uniqueOperatorDevices(tokens)[0];
}
