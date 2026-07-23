import { type NextRequest, NextResponse } from 'next/server';
import { canonicalRedirectPath, validatedCanonicalRedirectOrigin } from './src/lib/redirectPolicy';
import { buildContentSecurityPolicy } from './src/lib/securityPolicy';

const PRIVATE_HTML_CACHE_CONTROL = 'private, no-store, max-age=0, must-revalidate';
const APPLICATION_SECURITY_HEADERS = {
  'Cross-Origin-Opener-Policy': 'same-origin',
  'Cross-Origin-Resource-Policy': 'same-origin',
  'Permissions-Policy': 'geolocation=(), microphone=(self), camera=()',
  'Referrer-Policy': 'no-referrer',
  'X-Content-Type-Options': 'nosniff',
  'X-Frame-Options': 'DENY',
} as const;

export function middleware(request: NextRequest) {
  const nonce = Buffer.from(crypto.randomUUID()).toString('base64');
  const contentSecurityPolicy = buildContentSecurityPolicy({
    nonce,
    development: process.env.NODE_ENV !== 'production',
    websocketHost: process.env.NEXT_PUBLIC_WEBSOCKET_HOST,
    configuredAeretFrameOrigins: process.env.CSP_AERET_FRAME_ORIGINS,
  });
  const requestHeaders = new Headers(request.headers);
  requestHeaders.set('x-nonce', nonce);
  requestHeaders.set('Content-Security-Policy', contentSecurityPolicy);

  let response: NextResponse;
  if (request.nextUrl.pathname.length > 1 && request.nextUrl.pathname.endsWith('/')) {
    const redirectOrigin = validatedCanonicalRedirectOrigin(process.env.NEXT_PUBLIC_APP_URL);
    if (redirectOrigin === null) {
      response = new NextResponse('Service unavailable.\n', {
        status: 503,
        headers: { 'Content-Type': 'text/plain; charset=utf-8' },
      });
    } else {
      const redirectPath = canonicalRedirectPath(request.nextUrl.pathname);
      response = NextResponse.redirect(
        new URL(`${redirectPath}${request.nextUrl.search}`, redirectOrigin),
        308,
      );
    }
  } else {
    response = NextResponse.next({
      request: {
        headers: requestHeaders,
      },
    });
  }
  response.headers.set('Content-Security-Policy', contentSecurityPolicy);
  response.headers.set('Cache-Control', PRIVATE_HTML_CACHE_CONTROL);
  for (const [name, value] of Object.entries(APPLICATION_SECURITY_HEADERS)) {
    response.headers.set(name, value);
  }

  return response;
}

export const config = {
  matcher: [
    '/((?!api(?:/|$)|_next/(?:static|image)(?:/|$)|favicon\\.ico$|command-center\\.jpg$|\\.well-known/security\\.txt$).*)',
  ],
};
