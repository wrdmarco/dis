const DEFAULT_AERET_FRAME_ORIGINS = [
  'https://aeret.kaartviewer.nl',
  'https://dronepreflight.nl',
] as const;

export interface ContentSecurityPolicyOptions {
  nonce: string;
  development: boolean;
  websocketHost?: string;
  configuredAeretFrameOrigins?: string;
}

export function buildContentSecurityPolicy(options: ContentSecurityPolicyOptions): string {
  const scriptSources = ["'self'", `'nonce-${options.nonce}'`, "'strict-dynamic'"];
  if (options.development) {
    scriptSources.push("'unsafe-eval'");
  }

  const connectSources = [
    "'self'",
    'https://api.pdok.nl',
    'https://photon.komoot.io',
  ];
  const websocketOrigin = validatedWebsocketOrigin(options.websocketHost, options.development);
  if (websocketOrigin !== null) {
    connectSources.push(websocketOrigin);
  }
  if (options.development) {
    connectSources.push('ws:');
  }

  const frameSources = unique([
    'https://www.openstreetmap.org',
    ...DEFAULT_AERET_FRAME_ORIGINS,
    ...validatedHttpsOrigins(options.configuredAeretFrameOrigins),
  ]);

  const directives = [
    "default-src 'self'",
    `script-src ${scriptSources.join(' ')}`,
    "script-src-attr 'none'",
    `style-src 'self' 'nonce-${options.nonce}'`,
    "style-src-attr 'none'",
    `connect-src ${connectSources.join(' ')}`,
    "img-src 'self' data: https://server.arcgisonline.com",
    "font-src 'self'",
    `frame-src ${frameSources.join(' ')}`,
    "worker-src 'self'",
    "manifest-src 'self'",
    "media-src 'none'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'none'",
  ];

  if (!options.development) {
    directives.push('upgrade-insecure-requests');
  }

  return `${directives.join('; ')};`;
}

export function validatedHttpsOrigins(value?: string): string[] {
  if (value === undefined || value.trim() === '') {
    return [];
  }

  return unique(value.split(',').map((candidate) => validatedOrigin(candidate)).filter(isString));
}

function validatedWebsocketOrigin(host: string | undefined, development: boolean): string | null {
  if (host === undefined || host.trim() === '' || /[\s/\\]/.test(host)) {
    return null;
  }

  try {
    const url = new URL(`${development ? 'ws' : 'wss'}://${host.trim()}`);
    if (url.username !== '' || url.password !== '' || url.pathname !== '/' || url.search !== '' || url.hash !== '') {
      return null;
    }

    return url.origin;
  } catch {
    return null;
  }
}

function validatedOrigin(candidate: string): string | null {
  try {
    const url = new URL(candidate.trim());
    if (
      url.protocol !== 'https:'
      || url.username !== ''
      || url.password !== ''
      || url.pathname !== '/'
      || url.search !== ''
      || url.hash !== ''
    ) {
      return null;
    }

    return url.origin;
  } catch {
    return null;
  }
}

function unique(values: readonly string[]): string[] {
  return [...new Set(values)];
}

function isString(value: string | null): value is string {
  return value !== null;
}
