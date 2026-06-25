export interface ImportedFirebaseConfig {
  projectId?: string;
  applicationId?: string;
  apiKey?: string;
  messagingSenderId?: string;
  storageBucket?: string;
  serviceAccount?: {
    clientEmail?: string;
    privateKey?: string;
    privateKeyId?: string;
    clientId?: string;
    clientX509CertUrl?: string;
  };
}

export function parseFirebaseJson(text: string): ImportedFirebaseConfig {
  const parsed = JSON.parse(text) as unknown;

  if (!isRecord(parsed)) {
    throw new Error('Firebase JSON is ongeldig.');
  }

  if (asString(parsed.type) === 'service_account') {
    return {
      projectId: asString(parsed.project_id),
      serviceAccount: {
        clientEmail: asString(parsed.client_email),
        privateKey: asString(parsed.private_key),
        privateKeyId: asString(parsed.private_key_id),
        clientId: asString(parsed.client_id),
        clientX509CertUrl: asString(parsed.client_x509_cert_url),
      },
    };
  }

  const projectInfo = isRecord(parsed.project_info) ? parsed.project_info : {};
  const clients = Array.isArray(parsed.client) ? parsed.client : [];
  const firstClient = isRecord(clients[0]) ? clients[0] : {};
  const clientInfo = isRecord(firstClient.client_info) ? firstClient.client_info : {};
  const apiKeys = Array.isArray(firstClient.api_key) ? firstClient.api_key : [];
  const firstApiKey = isRecord(apiKeys[0]) ? apiKeys[0] : {};

  if (Object.keys(projectInfo).length > 0 || Object.keys(clientInfo).length > 0) {
    return {
      projectId: asString(projectInfo.project_id),
      applicationId: asString(clientInfo.mobilesdk_app_id),
      apiKey: asString(firstApiKey.current_key),
      messagingSenderId: asString(projectInfo.project_number),
      storageBucket: asString(projectInfo.storage_bucket),
    };
  }

  throw new Error('Dit JSON-bestand is geen ondersteunde Firebase config.');
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function asString(value: unknown): string | undefined {
  return typeof value === 'string' && value.trim() !== '' ? value : undefined;
}
