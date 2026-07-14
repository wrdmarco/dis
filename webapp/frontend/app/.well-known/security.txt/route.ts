import { createSecurityTextDocument } from '../../../src/lib/securityText';

const PLAIN_TEXT_CONTENT_TYPE = 'text/plain; charset=utf-8';

export const dynamic = 'force-dynamic';
export const revalidate = 0;

export function GET() {
  const document = createSecurityTextDocument({
    contact: process.env.SECURITY_CONTACT,
    publicUrl: process.env.NEXT_PUBLIC_APP_URL,
    appUrl: process.env.APP_URL,
    websocketHost: process.env.NEXT_PUBLIC_WEBSOCKET_HOST,
  });

  if (document === null) {
    return new Response('Security contact configuration is unavailable.\n', {
      status: 503,
      headers: {
        'Cache-Control': 'no-store, max-age=0',
        'Content-Type': PLAIN_TEXT_CONTENT_TYPE,
      },
    });
  }

  return new Response(document.body, {
    status: 200,
    headers: {
      'Cache-Control': 'public, max-age=3600, must-revalidate',
      'Content-Type': PLAIN_TEXT_CONTENT_TYPE,
    },
  });
}
