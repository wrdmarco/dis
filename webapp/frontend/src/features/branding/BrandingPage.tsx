import { useEffect, useMemo, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { useApiResource } from '../../lib/useApiResource';
import type { SystemSetting } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

interface BrandingForm {
  brandName: string;
  brandShortName: string;
  loginTitle: string;
  loginSubtitle: string;
  logoDataUrl: string;
  tenantName: string;
  mfaIssuerName: string;
  mailFromName: string;
  welcomeSubject: string;
  welcomeBody: string;
  certificationExpirySubject: string;
  certificationExpiryBody: string;
  assetWarningDaysBeforeExpiry: string;
  assetExpirySubject: string;
  assetExpiryBody: string;
}

export function BrandingPage() {
  const { api } = useAuth();
  const settings = useApiResource<SystemSetting[]>('/admin/settings');
  const initialForm = useMemo(() => toBrandingForm(settings.data ?? []), [settings.data]);
  const [form, setForm] = useState<BrandingForm>(initialForm);
  const [saving, setSaving] = useState(false);
  const [uploadingLogo, setUploadingLogo] = useState(false);
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
          'app.brand_name': textSetting(form.brandName, 'D.I.S Operationeel Beeld'),
          'app.brand_short_name': textSetting(form.brandShortName, 'DIS'),
          'app.login_title': textSetting(form.loginTitle, 'D.I.S Command Center'),
          'app.login_subtitle': textSetting(form.loginSubtitle),
          'mobile.tenant_name': textSetting(form.tenantName, 'Nationaal Droneteam'),
          'security.mfa_issuer_name': textSetting(form.mfaIssuerName, 'D.I.S'),
          'mail.from_name': textSetting(form.mailFromName, textSetting(form.tenantName, 'D.I.S')),
          'mail.template.welcome_subject': textSetting(form.welcomeSubject, 'Welkom bij {{app_name}}'),
          'mail.template.welcome_body': textSetting(form.welcomeBody),
          'mail.template.certification_expiry_subject': textSetting(form.certificationExpirySubject, '{{certification_name}} - {{status_text}}'),
          'mail.template.certification_expiry_body': textSetting(form.certificationExpiryBody),
          'asset.warning_days_before_expiry': Number(form.assetWarningDaysBeforeExpiry || 30),
          'mail.template.asset_expiry_subject': textSetting(form.assetExpirySubject, '{{asset_name}} - {{status_text}}'),
          'mail.template.asset_expiry_body': textSetting(form.assetExpiryBody),
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

  async function uploadLogo(file: File | null) {
    if (file === null) {
      return;
    }

    setUploadingLogo(true);
    setMessage(null);
    setError(null);

    try {
      const payload = new FormData();
      payload.append('logo', file);
      await api.postForm('/admin/branding/logo', payload);
      await settings.reload();
      setMessage('Logo is opgeslagen.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Logo opslaan mislukt.');
    } finally {
      setUploadingLogo(false);
    }
  }

  async function deleteLogo() {
    setUploadingLogo(true);
    setMessage(null);
    setError(null);

    try {
      await api.delete('/admin/branding/logo');
      await settings.reload();
      setMessage('Logo is verwijderd.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Logo verwijderen mislukt.');
    } finally {
      setUploadingLogo(false);
    }
  }

  return (
    <div className="page-stack">
      <Panel title="Branding">
        <ResourceState loading={settings.loading} error={settings.error} empty={settings.data === null}>
          <div className="stacked-section">
            <h3>Algemeen</h3>
            <div className="form-grid">
              <label>
                Applicatienaam
                <input maxLength={120} value={form.brandName} onChange={(event) => setForm((current) => ({ ...current, brandName: event.target.value }))} />
              </label>
              <label>
                Korte naam
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
              <label>
                Titel inlogvenster
                <input maxLength={120} value={form.loginTitle} onChange={(event) => setForm((current) => ({ ...current, loginTitle: event.target.value }))} />
              </label>
              <label>
                Subtitel inlogvenster
                <input maxLength={240} value={form.loginSubtitle} onChange={(event) => setForm((current) => ({ ...current, loginSubtitle: event.target.value }))} />
              </label>
              <label className="form-grid__wide">
                Mail afzendernaam
                <input maxLength={255} value={form.mailFromName} onChange={(event) => setForm((current) => ({ ...current, mailFromName: event.target.value }))} />
              </label>
            </div>
          </div>

          <div className="stacked-section">
            <h3>Logo</h3>
            <div className="branding-logo-row">
              <div className="branding-logo-preview">
                {form.logoDataUrl ? <img src={form.logoDataUrl} alt="Huidig logo" /> : <span>{form.brandShortName || 'DIS'}</span>}
              </div>
              <div className="actions-row">
                <label className="secondary-button file-button">
                  Logo uploaden
                  <input accept="image/png,image/jpeg,image/webp" type="file" onChange={(event) => void uploadLogo(event.target.files?.[0] ?? null)} disabled={uploadingLogo} />
                </label>
                <button className="secondary-button" type="button" onClick={() => void deleteLogo()} disabled={uploadingLogo || !form.logoDataUrl}>
                  Logo verwijderen
                </button>
              </div>
            </div>
          </div>

          <div className="stacked-section">
            <h3>Mail templates</h3>
            <div className="form-grid">
              <label className="form-grid__wide">
                Uitnodiging onderwerp
                <input maxLength={160} value={form.welcomeSubject} onChange={(event) => setForm((current) => ({ ...current, welcomeSubject: event.target.value }))} />
              </label>
              <label className="form-grid__wide">
                Uitnodiging tekst
                <textarea rows={9} maxLength={4000} value={form.welcomeBody} onChange={(event) => setForm((current) => ({ ...current, welcomeBody: event.target.value }))} />
              </label>
              <label className="form-grid__wide">
                Certificaat verloop onderwerp
                <input maxLength={160} value={form.certificationExpirySubject} onChange={(event) => setForm((current) => ({ ...current, certificationExpirySubject: event.target.value }))} />
              </label>
              <label className="form-grid__wide">
                Certificaat verloop tekst
                <textarea rows={9} maxLength={4000} value={form.certificationExpiryBody} onChange={(event) => setForm((current) => ({ ...current, certificationExpiryBody: event.target.value }))} />
              </label>
              <label>
                Asset waarschuwing vanaf dagen
                <input type="number" min={1} max={365} value={form.assetWarningDaysBeforeExpiry} onChange={(event) => setForm((current) => ({ ...current, assetWarningDaysBeforeExpiry: event.target.value }))} />
              </label>
              <label className="form-grid__wide">
                Asset verloop onderwerp
                <input maxLength={160} value={form.assetExpirySubject} onChange={(event) => setForm((current) => ({ ...current, assetExpirySubject: event.target.value }))} />
              </label>
              <label className="form-grid__wide">
                Asset verloop tekst
                <textarea rows={9} maxLength={4000} value={form.assetExpiryBody} onChange={(event) => setForm((current) => ({ ...current, assetExpiryBody: event.target.value }))} />
              </label>
            </div>
            <div className="metadata-example">
              <strong>Beschikbare tokens</strong>
              <pre>{'{{app_name}}, {{tenant_name}}, {{name}}, {{email}}, {{registration_url}}, {{admin_app_note}}, {{certification_name}}, {{certificate_number}}, {{asset_name}}, {{asset_tag}}, {{asset_type}}, {{serial_number}}, {{expires_at}}, {{days_until_expiry}}, {{expiry_status}}, {{status_text}}, {{download_url}}'}</pre>
            </div>
          </div>

          <div className="metadata-example">
            <strong>Voorbeeld</strong>
            <pre>{`${form.brandShortName || 'DIS'}\n${form.tenantName || 'Nationaal Droneteam'}\n${form.brandName || 'D.I.S Operationeel Beeld'}\n${form.loginTitle || 'D.I.S Command Center'}`}</pre>
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
    loginTitle: asString(byKey.get('app.login_title')) || 'D.I.S Command Center',
    loginSubtitle: asString(byKey.get('app.login_subtitle')),
    logoDataUrl: asString(byKey.get('app.logo_data_url')),
    tenantName: asString(byKey.get('mobile.tenant_name')) || 'Nationaal Droneteam',
    mfaIssuerName: asString(byKey.get('security.mfa_issuer_name')) || 'D.I.S',
    mailFromName: asString(byKey.get('mail.from_name')) || 'D.I.S',
    welcomeSubject: asString(byKey.get('mail.template.welcome_subject')) || 'Welkom bij {{app_name}}',
    welcomeBody: asString(byKey.get('mail.template.welcome_body')) || '',
    certificationExpirySubject: asString(byKey.get('mail.template.certification_expiry_subject')) || '{{certification_name}} - {{status_text}}',
    certificationExpiryBody: asString(byKey.get('mail.template.certification_expiry_body')) || '',
    assetWarningDaysBeforeExpiry: String(asNumber(byKey.get('asset.warning_days_before_expiry'), 30)),
    assetExpirySubject: asString(byKey.get('mail.template.asset_expiry_subject')) || '{{asset_name}} - {{status_text}}',
    assetExpiryBody: asString(byKey.get('mail.template.asset_expiry_body')) || '',
  };
}

function asString(value: unknown): string {
  return typeof value === 'string' ? value : '';
}

function textSetting(value: unknown, fallback = ''): string {
  const text = typeof value === 'string' ? value.trim() : '';

  return text || fallback;
}

function asNumber(value: unknown, fallback: number): number {
  return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}
