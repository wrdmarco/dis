const SECURITY_TEXT_PATH = '/.well-known/security.txt';
const SECURITY_TEXT_VALIDITY_DAYS = 180;

export interface SecurityTextConfiguration {
  contact?: string;
  publicUrl?: string;
  appUrl?: string;
  websocketHost?: string;
}

export interface SecurityTextDocument {
  body: string;
  canonical: string;
  contact: string;
  expires: Date;
}

export function createSecurityTextDocument(
  configuration: SecurityTextConfiguration,
  now = new Date(),
): SecurityTextDocument | null {
  const contact = validatedSecurityContact(configuration.contact);
  const publicOrigin = resolvedPublicOrigin(configuration);
  if (contact === null || publicOrigin === null || !Number.isFinite(now.getTime())) {
    return null;
  }

  const expires = new Date(now.getTime() + SECURITY_TEXT_VALIDITY_DAYS * 24 * 60 * 60 * 1000);
  const canonical = new URL(SECURITY_TEXT_PATH, publicOrigin).toString();
  const body = [
    `Contact: ${contact}`,
    `Expires: ${expires.toISOString()}`,
    `Canonical: ${canonical}`,
    'Preferred-Languages: nl, en',
    '',
  ].join('\n');

  return { body, canonical, contact, expires };
}

export function validatedSecurityContact(value?: string): string | null {
  if (value === undefined || value.trim() === '' || /[\r\n]/.test(value)) {
    return null;
  }

  try {
    const url = new URL(value.trim());
    if (url.protocol === 'mailto:') {
      const address = decodeURIComponent(url.pathname);
      if (url.hash !== '' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(address)) {
        return null;
      }
      return url.toString();
    }

    if (url.protocol !== 'https:' || url.username !== '' || url.password !== '' || url.hostname === '') {
      return null;
    }

    return url.toString();
  } catch {
    return null;
  }
}

export function resolvedPublicOrigin(configuration: SecurityTextConfiguration): string | null {
  const configuredCandidates = [configuration.publicUrl, configuration.appUrl];
  for (const candidate of configuredCandidates) {
    const origin = validatedPublicOrigin(candidate);
    if (origin !== null) {
      return origin;
    }
  }

  if (configuration.websocketHost === undefined || configuration.websocketHost.trim() === '') {
    return null;
  }

  return validatedPublicOrigin(`https://${configuration.websocketHost.trim()}`);
}

function validatedPublicOrigin(value?: string): string | null {
  if (value === undefined || value.trim() === '' || /[\r\n]/.test(value)) {
    return null;
  }

  try {
    const url = new URL(value.trim());
    if (url.protocol !== 'https:' || url.username !== '' || url.password !== '' || url.hostname === '') {
      return null;
    }

    return url.origin;
  } catch {
    return null;
  }
}
