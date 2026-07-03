import { useEffect, useMemo, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { useApiResource } from '../../lib/useApiResource';
import type { SystemSetting } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

type BrandingTab = 'general' | 'logo' | 'templates' | 'pushTemplates' | 'expiry';

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
  certificationWarningDaysBeforeExpiry: string;
  certificationExpirySubject: string;
  certificationExpiryBody: string;
  assetWarningDaysBeforeExpiry: string;
  assetExpirySubject: string;
  assetExpiryBody: string;
  pushPreannouncementTitle: string;
  pushPreannouncementBody: string;
  pushDispatchTitle: string;
  pushDispatchBody: string;
  pushUnavailableEscalationTitle: string;
  pushUnavailableEscalationBody: string;
  pushAdditionalInfoTitle: string;
  pushAdditionalInfoBody: string;
  pushCancellationTitle: string;
  pushCancellationBody: string;
}

const brandingTabs: Array<{ id: BrandingTab; label: string }> = [
  { id: 'general', label: 'Algemeen' },
  { id: 'logo', label: 'Logo' },
  { id: 'templates', label: 'Mail templates' },
  { id: 'pushTemplates', label: 'Push templates' },
  { id: 'expiry', label: 'Verloopmails' },
];

const DEFAULT_WELCOME_SUBJECT = 'Welkom bij {{app_name}}';
const DEFAULT_WELCOME_BODY = `Beste {{name}},

Er is een account voor je aangemaakt in {{app_name}}. Rond je registratie af via onderstaande link:

{{registration_url}}

Je stelt zelf je wachtwoord in en doorloopt direct de MFA-setup wanneer dat voor je rol verplicht is.

{{admin_app_note}}

Deze link is tijdelijk geldig. Vraag een beheerder om een nieuwe uitnodiging als de link verlopen is.`;
const DEFAULT_CERTIFICATION_EXPIRY_SUBJECT = '{{certification_name}} - {{status_text}}';
const DEFAULT_CERTIFICATION_EXPIRY_BODY = `Beste {{name}},

Je certificaat {{certification_name}} {{expiry_status}}.

Certificaatnummer: {{certificate_number}}
Verloopdatum: {{expires_at}}
Status: {{status_text}}

Werk je certificaat bij in de app zodra de verlenging rond is. Zonder geldig vereist certificaat kun je niet meegenomen worden in alarmeringen waarvoor dit certificaat verplicht is.`;
const DEFAULT_ASSET_EXPIRY_SUBJECT = '{{asset_name}} - {{status_text}}';
const DEFAULT_ASSET_EXPIRY_BODY = `Beste {{name}},

De verloopdatum of onderhoudsdatum van asset {{asset_name}} {{expiry_status}}.

Asset tag: {{asset_tag}}
Serienummer: {{serial_number}}
Verloopdatum: {{expires_at}}
Status: {{status_text}}

Werk de assetgegevens bij zodra dit is afgehandeld.`;
const DEFAULT_PUSH_PREANNOUNCEMENT_TITLE = 'D.I.S vooraankondiging';
const DEFAULT_PUSH_PREANNOUNCEMENT_BODY = 'Ben je beschikbaar voor een melding in {{place}}?';
const DEFAULT_PUSH_DISPATCH_TITLE = 'NDT Alarmering';
const DEFAULT_PUSH_DISPATCH_BODY = '{{message}}';
const DEFAULT_PUSH_UNAVAILABLE_ESCALATION_TITLE = 'NDT urgente opschaling';
const DEFAULT_PUSH_UNAVAILABLE_ESCALATION_BODY = '{{reason}} {{availability_reason}} {{message}}';
const DEFAULT_PUSH_ADDITIONAL_INFO_TITLE = 'D.I.S aanvullende info';
const DEFAULT_PUSH_ADDITIONAL_INFO_BODY = '{{message}}';
const DEFAULT_PUSH_CANCELLATION_TITLE = 'D.I.S geannuleerd';
const DEFAULT_PUSH_CANCELLATION_BODY = 'De vooraankondiging in {{place}} is geannuleerd.';

export function BrandingPage() {
  const { api } = useAuth();
  const settings = useApiResource<SystemSetting[]>('/admin/settings');
  const initialForm = useMemo(() => toBrandingForm(settings.data ?? []), [settings.data]);
  const [form, setForm] = useState<BrandingForm>(initialForm);
  const [activeTab, setActiveTab] = useState<BrandingTab>('general');
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
          'mail.template.welcome_subject': textSetting(form.welcomeSubject, DEFAULT_WELCOME_SUBJECT),
          'mail.template.welcome_body': textSetting(form.welcomeBody, DEFAULT_WELCOME_BODY),
          'certification.warning_days_before_expiry': numberSetting(form.certificationWarningDaysBeforeExpiry, 30),
          'mail.template.certification_expiry_subject': textSetting(form.certificationExpirySubject, DEFAULT_CERTIFICATION_EXPIRY_SUBJECT),
          'mail.template.certification_expiry_body': textSetting(form.certificationExpiryBody, DEFAULT_CERTIFICATION_EXPIRY_BODY),
          'asset.warning_days_before_expiry': numberSetting(form.assetWarningDaysBeforeExpiry, 30),
          'mail.template.asset_expiry_subject': textSetting(form.assetExpirySubject, DEFAULT_ASSET_EXPIRY_SUBJECT),
          'mail.template.asset_expiry_body': textSetting(form.assetExpiryBody, DEFAULT_ASSET_EXPIRY_BODY),
          'push.template.preannouncement_title': textSetting(form.pushPreannouncementTitle, DEFAULT_PUSH_PREANNOUNCEMENT_TITLE),
          'push.template.preannouncement_body': textSetting(form.pushPreannouncementBody, DEFAULT_PUSH_PREANNOUNCEMENT_BODY),
          'push.template.dispatch_title': textSetting(form.pushDispatchTitle, DEFAULT_PUSH_DISPATCH_TITLE),
          'push.template.dispatch_body': textSetting(form.pushDispatchBody, DEFAULT_PUSH_DISPATCH_BODY),
          'push.template.dispatch_unavailable_escalation_title': textSetting(form.pushUnavailableEscalationTitle, DEFAULT_PUSH_UNAVAILABLE_ESCALATION_TITLE),
          'push.template.dispatch_unavailable_escalation_body': textSetting(form.pushUnavailableEscalationBody, DEFAULT_PUSH_UNAVAILABLE_ESCALATION_BODY),
          'push.template.additional_info_title': textSetting(form.pushAdditionalInfoTitle, DEFAULT_PUSH_ADDITIONAL_INFO_TITLE),
          'push.template.additional_info_body': textSetting(form.pushAdditionalInfoBody, DEFAULT_PUSH_ADDITIONAL_INFO_BODY),
          'push.template.cancellation_title': textSetting(form.pushCancellationTitle, DEFAULT_PUSH_CANCELLATION_TITLE),
          'push.template.cancellation_body': textSetting(form.pushCancellationBody, DEFAULT_PUSH_CANCELLATION_BODY),
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
          <div className="admin-tabs" role="tablist" aria-label="Branding onderdelen">
            {brandingTabs.map((tab) => (
              <button
                className={activeTab === tab.id ? 'admin-tab admin-tab--active' : 'admin-tab'}
                key={tab.id}
                type="button"
                role="tab"
                aria-selected={activeTab === tab.id}
                onClick={() => setActiveTab(tab.id)}
              >
                {tab.label}
              </button>
            ))}
          </div>

          {activeTab === 'general' ? (
            <div className="stacked-section">
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
          ) : null}

          {activeTab === 'logo' ? (
            <div className="stacked-section">
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
          ) : null}

          {activeTab === 'templates' ? (
            <div className="stacked-section">
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
          ) : null}

          {activeTab === 'expiry' ? (
            <div className="stacked-section">
              <div className="form-grid">
                <label>
                  Certificaat waarschuwing
                  <input type="number" min={1} max={365} value={form.certificationWarningDaysBeforeExpiry} onChange={(event) => setForm((current) => ({ ...current, certificationWarningDaysBeforeExpiry: event.target.value }))} />
                </label>
                <label>
                  Asset waarschuwing
                  <input type="number" min={1} max={365} value={form.assetWarningDaysBeforeExpiry} onChange={(event) => setForm((current) => ({ ...current, assetWarningDaysBeforeExpiry: event.target.value }))} />
                </label>
              </div>
              <div className="metadata-example">
                <strong>Verzendmomenten</strong>
                <pre>{`Certificaten: ${form.certificationWarningDaysBeforeExpiry || 30} dag(en) voor verlopen en op de verloopdatum.\nAssets: ${form.assetWarningDaysBeforeExpiry || 30} dag(en) voor verlopen en op de verloopdatum.`}</pre>
              </div>
            </div>
          ) : null}

          {activeTab === 'pushTemplates' ? (
            <div className="stacked-section">
              <div className="form-grid">
                <PushTemplateFields
                  title="Vooraankondiging"
                  titleValue={form.pushPreannouncementTitle}
                  bodyValue={form.pushPreannouncementBody}
                  onTitleChange={(value) => setForm((current) => ({ ...current, pushPreannouncementTitle: value }))}
                  onBodyChange={(value) => setForm((current) => ({ ...current, pushPreannouncementBody: value }))}
                />
                <PushTemplateFields
                  title="Alarmering"
                  titleValue={form.pushDispatchTitle}
                  bodyValue={form.pushDispatchBody}
                  onTitleChange={(value) => setForm((current) => ({ ...current, pushDispatchTitle: value }))}
                  onBodyChange={(value) => setForm((current) => ({ ...current, pushDispatchBody: value }))}
                />
                <PushTemplateFields
                  title="Alarmering bij urgente opschaling ondanks niet beschikbaar"
                  titleValue={form.pushUnavailableEscalationTitle}
                  bodyValue={form.pushUnavailableEscalationBody}
                  onTitleChange={(value) => setForm((current) => ({ ...current, pushUnavailableEscalationTitle: value }))}
                  onBodyChange={(value) => setForm((current) => ({ ...current, pushUnavailableEscalationBody: value }))}
                />
                <PushTemplateFields
                  title="Nadere info"
                  titleValue={form.pushAdditionalInfoTitle}
                  bodyValue={form.pushAdditionalInfoBody}
                  onTitleChange={(value) => setForm((current) => ({ ...current, pushAdditionalInfoTitle: value }))}
                  onBodyChange={(value) => setForm((current) => ({ ...current, pushAdditionalInfoBody: value }))}
                />
                <PushTemplateFields
                  title="Annulering"
                  titleValue={form.pushCancellationTitle}
                  bodyValue={form.pushCancellationBody}
                  onTitleChange={(value) => setForm((current) => ({ ...current, pushCancellationTitle: value }))}
                  onBodyChange={(value) => setForm((current) => ({ ...current, pushCancellationBody: value }))}
                />
              </div>
              <div className="metadata-example">
                <strong>Beschikbare tokens</strong>
                <pre>{'{{place}}, {{message}}, {{reference}}, {{title}}, {{location}}, {{priority}}, {{reason}}, {{availability_reason}}'}</pre>
              </div>
              <div className="metadata-example">
                <strong>Reason bij niet beschikbaar maar opgeschaald</strong>
                <pre>{'{{reason}} = Urgente opschaling: de coordinator heeft gekozen om ook niet-beschikbare teamleden te alarmeren.\n{{availability_reason}} = persoonlijke beschikbaarheidsreden, bijvoorbeeld status niet beschikbaar of vast weekpatroon.'}</pre>
              </div>
            </div>
          ) : null}

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
    welcomeSubject: asString(byKey.get('mail.template.welcome_subject')) || DEFAULT_WELCOME_SUBJECT,
    welcomeBody: asString(byKey.get('mail.template.welcome_body')) || DEFAULT_WELCOME_BODY,
    certificationWarningDaysBeforeExpiry: String(asNumber(byKey.get('certification.warning_days_before_expiry'), 30)),
    certificationExpirySubject: asString(byKey.get('mail.template.certification_expiry_subject')) || DEFAULT_CERTIFICATION_EXPIRY_SUBJECT,
    certificationExpiryBody: asString(byKey.get('mail.template.certification_expiry_body')) || DEFAULT_CERTIFICATION_EXPIRY_BODY,
    assetWarningDaysBeforeExpiry: String(asNumber(byKey.get('asset.warning_days_before_expiry'), 30)),
    assetExpirySubject: asString(byKey.get('mail.template.asset_expiry_subject')) || DEFAULT_ASSET_EXPIRY_SUBJECT,
    assetExpiryBody: asString(byKey.get('mail.template.asset_expiry_body')) || DEFAULT_ASSET_EXPIRY_BODY,
    pushPreannouncementTitle: asString(byKey.get('push.template.preannouncement_title')) || DEFAULT_PUSH_PREANNOUNCEMENT_TITLE,
    pushPreannouncementBody: asString(byKey.get('push.template.preannouncement_body')) || DEFAULT_PUSH_PREANNOUNCEMENT_BODY,
    pushDispatchTitle: asString(byKey.get('push.template.dispatch_title')) || DEFAULT_PUSH_DISPATCH_TITLE,
    pushDispatchBody: asString(byKey.get('push.template.dispatch_body')) || DEFAULT_PUSH_DISPATCH_BODY,
    pushUnavailableEscalationTitle: asString(byKey.get('push.template.dispatch_unavailable_escalation_title')) || DEFAULT_PUSH_UNAVAILABLE_ESCALATION_TITLE,
    pushUnavailableEscalationBody: asString(byKey.get('push.template.dispatch_unavailable_escalation_body')) || DEFAULT_PUSH_UNAVAILABLE_ESCALATION_BODY,
    pushAdditionalInfoTitle: asString(byKey.get('push.template.additional_info_title')) || DEFAULT_PUSH_ADDITIONAL_INFO_TITLE,
    pushAdditionalInfoBody: asString(byKey.get('push.template.additional_info_body')) || DEFAULT_PUSH_ADDITIONAL_INFO_BODY,
    pushCancellationTitle: asString(byKey.get('push.template.cancellation_title')) || DEFAULT_PUSH_CANCELLATION_TITLE,
    pushCancellationBody: asString(byKey.get('push.template.cancellation_body')) || DEFAULT_PUSH_CANCELLATION_BODY,
  };
}

function PushTemplateFields({
  title,
  titleValue,
  bodyValue,
  onTitleChange,
  onBodyChange,
}: {
  title: string;
  titleValue: string;
  bodyValue: string;
  onTitleChange: (value: string) => void;
  onBodyChange: (value: string) => void;
}) {
  return (
    <section className="form-grid__wide push-template-card">
      <h3>{title}</h3>
      <div className="form-grid">
        <label>
          Titel
          <input maxLength={160} value={titleValue} onChange={(event) => onTitleChange(event.target.value)} />
        </label>
        <label className="form-grid__wide">
          Bericht
          <textarea rows={4} maxLength={2000} value={bodyValue} onChange={(event) => onBodyChange(event.target.value)} />
        </label>
      </div>
    </section>
  );
}

function asString(value: unknown): string {
  return typeof value === 'string' ? value : '';
}

function textSetting(value: unknown, fallback = ''): string {
  const text = typeof value === 'string' ? value.trim() : '';

  return text || fallback;
}

function numberSetting(value: string, fallback: number): number {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function asNumber(value: unknown, fallback: number): number {
  return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}
