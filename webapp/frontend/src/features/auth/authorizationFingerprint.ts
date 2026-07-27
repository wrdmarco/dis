import type { User } from '../../types/api';

export function authorizationFingerprint(user: User | null): string {
  if (user === null) {
    return '';
  }

  const roles = (user.roles ?? [])
    .map((role) => {
      const permissions = (role.permissions ?? [])
        .map((permission) => `${permission.id}:${permission.name}`)
        .sort()
        .join(',');

      return [
        role.id,
        role.can_use_operator_app ? 'operator' : '',
        role.can_use_admin_app ? 'admin' : '',
        permissions,
      ].join(':');
    })
    .sort()
    .join('|');

  return `${user.id}|${roles}`;
}
