import { useEffect, useMemo, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { useApiResource } from '../../lib/useApiResource';
import type { SystemSetting } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

interface BrandingForm {
  brandName: string;
  brandShortName: string;
  tenantName: string;
  mfaIssuerName: string;
  mailFromName: string;
}

export function BrandingPage() {
  const { api } = useAuth();
  const settings = useApiResource<SystemSetting[]>('/admin/settings');
  const initialForm = useMemo(() => toBrandingForm(settings.data ?? []), [settings.data]);
  const [form, setForm] = useState<BrandingForm>(initialForm);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setForm(initialForm);
  }, [initialForm]);

  async function saveBranding() {
    setSaving(true);
    setMessage(null);
    setError(null);

    try {
      await api.patch('/admin/settings', {
        settings: {
          'app.brand_name': form.brandName.trim() || 'D.I.S Operationeel Beeld',
          'app.brand_short_name': form.brandShortName.trim() || 'DIS',
          'mobile.tenant_name': form.tenantName.trim() || 'Nationaal Droneteam',
          'security.mfa_issuer_name': form.mfaIssuerName.trim() || 'D.I.S',
          'mail.from_name': form.mailFromName.trim() || form.tenantName.trim() || 'D.I.S',
        },
      });
      await settings.reload();
      setMessage('Branding is opgeslagen.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Branding opslaan mislukt.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="page-stack">
      <Panel title="Branding">
        <ResourceState loading={settings.loading} error={settings.error} empty={settings.data === null}>
          <div className="form-grid">
            <label>
              Applicatienaam
              <input maxLength={120} value={form.brandName} onChange={(event) => setForm((current) => ({ ...current, brandName: event.target.value }))} />
            </label>
            <label>
              Logo tekst
              <input maxLength={12} value={form.brandShortName} onChange={(event) => setForm((current) => ({ ...current, brandShortName: event.target.value }))} />
            </label>
            <label>
              Organisatienaam
              <input maxLength={120} value={form.tenantName} onChange={(event) => setForm((current) => ({ ...current, tenantName: event.target.value }))} />
            </label>
            <label>
              Authenticator naam
              <input maxLength={64} value={form.mfaIssuerName} onChange={(event) => setForm((current) => ({ ...current, mfaIssuerName: event.target.value }))} />
            </label>
            <label className="form-grid__wide">
              Mail afzendernaam
              <input maxLength={255} value={form.mailFromName} onChange={(event) => setForm((current) => ({ ...current, mailFromName: event.target.value }))} />
            </label>
          </div>
          <div className="metadata-example">
            <strong>Voorbeeld</strong>
            <pre>{`${form.brandShortName || 'DIS'}\n${form.tenantName || 'Nationaal Droneteam'}\n${form.brandName || 'D.I.S Operationeel Beeld'}`}</pre>
          </div>
          {error ? <p className="form-error">{error}</p> : null}
          {message ? <p className="form-note">{message}</p> : null}
          <div className="actions-row">
            <button className="primary-button" type="button" onClick={() => void saveBranding()} disabled={saving}>
              {saving ? 'Opslaan...' : 'Branding opslaan'}
            </button>
          </div>
        </ResourceState>
      </Panel>
    </div>
  );
}

function toBrandingForm(settings: SystemSetting[]): BrandingForm {
  const byKey = new Map(settings.map((setting) => [setting.key, setting.value]));

  return {
    brandName: asString(byKey.get('app.brand_name')) || 'D.I.S Operationeel Beeld',
    brandShortName: asString(byKey.get('app.brand_short_name')) || 'DIS',
    tenantName: asString(byKey.get('mobile.tenant_name')) || 'Nationaal Droneteam',
    mfaIssuerName: asString(byKey.get('security.mfa_issuer_name')) || 'D.I.S',
    mailFromName: asString(byKey.get('mail.from_name')) || 'D.I.S',
  };
}

function asString(value: unknown): string {
  return typeof value === 'string' ? value : '';
}
