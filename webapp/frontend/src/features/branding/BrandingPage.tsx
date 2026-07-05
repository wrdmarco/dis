import { useEffect, useMemo, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { useApiResource } from '../../lib/useApiResource';
import type { ConfigurableFormField, IncidentFormConfig, SystemSetting } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

type BrandingTab = 'general' | 'logo' | 'mailTemplates' | 'pushTemplates' | 'expiryTemplates' | 'expiry';

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
  { id: 'mailTemplates', label: 'Mail templates' },
  { id: 'pushTemplates', label: 'Push templates' },
  { id: 'expiryTemplates', label: 'Verloopmail templates' },
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
const PUSH_TEMPLATE_TOKEN_HELP = `{{reference}} = incidentnummer
{{title}} = incidenttitel
{{description}} = omschrijving
{{address}} = volledig adres of locatieveld
{{place}} = plaatsnaam uit het locatieveld
{{location}} = hetzelfde als adres, voor bestaande templates
{{latitude}} / {{longitude}} = losse coordinaten
{{coordinates}} = coordinaten samen
{{priority}} = prioriteit
{{status}} = incidentstatus
{{reporter_name}} / {{reporter_phone}} = melder
{{requesting_organization}} / {{requesting_unit}} = aanvrager
{{on_scene_contact_name}} / {{on_scene_contact_phone}} / {{on_scene_contact_role}} = contact ter plaatse
{{required_resources}} = benodigde middelen
{{coordinator_name}} = coordinator
{{created_by_name}} = aangemaakt door
{{created_at}} / {{opened_at}} / {{closed_at}} = tijden
{{message}} = standaard incidentbericht
{{reason}} = reden van opschaling
{{availability_reason}} = waarom iemand niet beschikbaar stond`;

const basePushVariables = [
  ['reference', 'Incidentnummer'],
  ['title', 'Incidenttitel'],
  ['description', 'Omschrijving'],
  ['address', 'Adres/locatie'],
  ['place', 'Plaatsnaam'],
  ['priority', 'Prioriteit'],
  ['status', 'Status'],
  ['reporter_name', 'Melder naam'],
  ['reporter_phone', 'Melder telefoon'],
  ['requesting_organization', 'Aanvragende organisatie'],
  ['requesting_unit', 'Dienst/eenheid'],
  ['on_scene_contact_name', 'Contact ter plaatse'],
  ['on_scene_contact_phone', 'Telefoon ter plaatse'],
  ['on_scene_contact_role', 'Rol ter plaatse'],
  ['required_resources', 'Benodigde middelen'],
  ['coordinator_name', 'Coordinator'],
  ['created_by_name', 'Aangemaakt door'],
  ['created_at', 'Aangemaakt op'],
  ['message', 'Standaard bericht'],
] as const;

export function BrandingPage() {
  const { api } = useAuth();
  const settings = useApiResource<SystemSetting[]>('/admin/settings');
  const incidentFormConfig = useApiResource<IncidentFormConfig>('/admin/incident-form/config');
  const initialForm = useMemo(() => toBrandingForm(settings.data ?? []), [settings.data]);
  const incidentFieldVariables = useMemo(() => incidentFormVariables(incidentFormConfig.data?.fields ?? []), [incidentFormConfig.data?.fields]);
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

          {activeTab === 'mailTemplates' ? (
            <div className="stacked-section">
              <div className="form-grid">
                <TemplateFields
                  title="Uitnodiging"
                  titleLabel="Onderwerp"
                  titleValue={form.welcomeSubject}
                  bodyLabel="Tekst"
                  bodyValue={form.welcomeBody}
                  bodyRows={9}
                  bodyMaxLength={4000}
                  onTitleChange={(value) => setForm((current) => ({ ...current, welcomeSubject: value }))}
                  onBodyChange={(value) => setForm((current) => ({ ...current, welcomeBody: value }))}
                />
              </div>
              <div className="metadata-example">
                <strong>Beschikbare tokens</strong>
                <pre>{'{{app_name}}, {{tenant_name}}, {{name}}, {{email}}, {{registration_url}}, {{admin_app_note}}, {{download_url}}'}</pre>
              </div>
            </div>
          ) : null}

          {activeTab === 'expiryTemplates' ? (
            <div className="stacked-section">
              <div className="form-grid">
                <TemplateFields
                  title="Certificaat verloop"
                  titleLabel="Onderwerp"
                  titleValue={form.certificationExpirySubject}
                  bodyLabel="Tekst"
                  bodyValue={form.certificationExpiryBody}
                  bodyRows={9}
                  bodyMaxLength={4000}
                  onTitleChange={(value) => setForm((current) => ({ ...current, certificationExpirySubject: value }))}
                  onBodyChange={(value) => setForm((current) => ({ ...current, certificationExpiryBody: value }))}
                />
                <TemplateFields
                  title="Asset verloop"
                  titleLabel="Onderwerp"
                  titleValue={form.assetExpirySubject}
                  bodyLabel="Tekst"
                  bodyValue={form.assetExpiryBody}
                  bodyRows={9}
                  bodyMaxLength={4000}
                  onTitleChange={(value) => setForm((current) => ({ ...current, assetExpirySubject: value }))}
                  onBodyChange={(value) => setForm((current) => ({ ...current, assetExpiryBody: value }))}
                />
              </div>
              <div className="metadata-example">
                <strong>Beschikbare tokens</strong>
                <pre>{'{{app_name}}, {{tenant_name}}, {{name}}, {{email}}, {{certification_name}}, {{certificate_number}}, {{asset_name}}, {{asset_tag}}, {{asset_type}}, {{serial_number}}, {{expires_at}}, {{days_until_expiry}}, {{expiry_status}}, {{status_text}}, {{download_url}}'}</pre>
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
                <TemplateFields
                  title="Vooraankondiging"
                  titleLabel="Titel"
                  titleValue={form.pushPreannouncementTitle}
                  bodyLabel="Beschikbaarheidsvraag"
                  bodyValue={form.pushPreannouncementBody}
                  variables={pushVariablesFor('preannouncement', incidentFieldVariables)}
                  onTitleChange={(value) => setForm((current) => ({ ...current, pushPreannouncementTitle: value }))}
                  onBodyChange={(value) => setForm((current) => ({ ...current, pushPreannouncementBody: value }))}
                />
                <TemplateFields
                  title="Alarmering"
                  titleLabel="Titel"
                  titleValue={form.pushDispatchTitle}
                  bodyLabel="Berichtopbouw"
                  bodyValue={form.pushDispatchBody}
                  variables={pushVariablesFor('dispatch', incidentFieldVariables)}
                  onTitleChange={(value) => setForm((current) => ({ ...current, pushDispatchTitle: value }))}
                  onBodyChange={(value) => setForm((current) => ({ ...current, pushDispatchBody: value }))}
                />
                <TemplateFields
                  title="Alarmering bij urgente opschaling ondanks niet beschikbaar"
                  titleLabel="Titel"
                  titleValue={form.pushUnavailableEscalationTitle}
                  bodyLabel="Bericht"
                  bodyValue={form.pushUnavailableEscalationBody}
                  variables={pushVariablesFor('unavailable', incidentFieldVariables)}
                  onTitleChange={(value) => setForm((current) => ({ ...current, pushUnavailableEscalationTitle: value }))}
                  onBodyChange={(value) => setForm((current) => ({ ...current, pushUnavailableEscalationBody: value }))}
                />
                <TemplateFields
                  title="Nadere info"
                  titleLabel="Titel"
                  titleValue={form.pushAdditionalInfoTitle}
                  bodyLabel="Bericht"
                  bodyValue={form.pushAdditionalInfoBody}
                  variables={pushVariablesFor('additional', incidentFieldVariables)}
                  onTitleChange={(value) => setForm((current) => ({ ...current, pushAdditionalInfoTitle: value }))}
                  onBodyChange={(value) => setForm((current) => ({ ...current, pushAdditionalInfoBody: value }))}
                />
                <TemplateFields
                  title="Annulering"
                  titleLabel="Titel"
                  titleValue={form.pushCancellationTitle}
                  bodyLabel="Bericht"
                  bodyValue={form.pushCancellationBody}
                  variables={pushVariablesFor('cancellation', incidentFieldVariables)}
                  onTitleChange={(value) => setForm((current) => ({ ...current, pushCancellationTitle: value }))}
                  onBodyChange={(value) => setForm((current) => ({ ...current, pushCancellationBody: value }))}
                />
              </div>
              <div className="metadata-example">
                <strong>Beschikbare tokens</strong>
                <pre>{PUSH_TEMPLATE_TOKEN_HELP}{incidentFieldVariables.length > 0 ? `\n${incidentFieldVariables.map((variable) => `{{${variable.key}}} = ${variable.label}`).join('\n')}` : ''}</pre>
              </div>
              <div className="metadata-example">
                <strong>Voorbeeld alarmering</strong>
                <pre>{'Melding {{reference}}\n{{title}}\nAdres: {{address}}\nPlaats: {{place}}\nContact: {{on_scene_contact_name}} - {{on_scene_contact_phone}}\nMiddelen: {{required_resources}}'}</pre>
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

function TemplateFields({
  title,
  titleLabel,
  titleValue,
  bodyLabel,
  bodyValue,
  bodyRows = 4,
  bodyMaxLength = 2000,
  variables = [],
  onTitleChange,
  onBodyChange,
}: {
  title: string;
  titleLabel: string;
  titleValue: string;
  bodyLabel: string;
  bodyValue: string;
  bodyRows?: number;
  bodyMaxLength?: number;
  variables?: Array<{ key: string; label: string }>;
  onTitleChange: (value: string) => void;
  onBodyChange: (value: string) => void;
}) {
  const insertVariable = (key: string, target: 'title' | 'body') => {
    const token = `{{${key}}}`;
    if (target === 'title') {
      onTitleChange(appendToken(titleValue, token));
      return;
    }

    onBodyChange(appendToken(bodyValue, token));
  };

  return (
    <section className="form-grid__wide push-template-card">
      <h3>{title}</h3>
      <div className="form-grid">
        <label>
          {titleLabel}
          <input maxLength={160} value={titleValue} onChange={(event) => onTitleChange(event.target.value)} />
        </label>
        <label className="form-grid__wide">
          {bodyLabel}
          <textarea rows={bodyRows} maxLength={bodyMaxLength} value={bodyValue} onChange={(event) => onBodyChange(event.target.value)} />
        </label>
      </div>
      {variables.length > 0 ? (
        <div className="template-variable-bank">
          <strong>Variabelen voor deze melding</strong>
          <div className="template-variable-bank__grid">
            {variables.map((variable) => (
              <div className="template-variable" key={variable.key}>
                <span><code>{`{{${variable.key}}}`}</code><small>{variable.label}</small></span>
                <div className="actions-row">
                  <button className="secondary-button" type="button" onClick={() => insertVariable(variable.key, 'title')}>Titel</button>
                  <button className="secondary-button" type="button" onClick={() => insertVariable(variable.key, 'body')}>Tekst</button>
                </div>
              </div>
            ))}
          </div>
        </div>
      ) : null}
    </section>
  );
}

function appendToken(value: string, token: string): string {
  if (value.trim() === '') {
    return token;
  }

  return `${value}${value.endsWith('\n') ? '' : ' '}${token}`;
}

function incidentFormVariables(fields: ConfigurableFormField[]): Array<{ key: string; label: string }> {
  return fields
    .filter((field) => field.visible && field.type !== 'section' && (field.expose_to_push ?? true))
    .map((field) => ({ key: `field_${field.key}`, label: `Formulierveld: ${field.label}` }));
}

function pushVariablesFor(kind: 'preannouncement' | 'dispatch' | 'unavailable' | 'additional' | 'cancellation', incidentFields: Array<{ key: string; label: string }>): Array<{ key: string; label: string }> {
  const extras = kind === 'unavailable'
    ? [{ key: 'reason', label: 'Reden opschaling' }, { key: 'availability_reason', label: 'Waarom niet beschikbaar' }]
    : [];
  const message = kind === 'preannouncement' || kind === 'cancellation'
    ? []
    : [{ key: 'message', label: 'Standaard bericht' }];

  const availableIncidentFieldKeys = new Set(incidentFields.map((field) => field.key.replace(/^field_/, '')));
  const controlledLegacyKeys = new Set(['reporter_name', 'reporter_phone', 'requesting_organization', 'requesting_unit', 'on_scene_contact_name', 'on_scene_contact_phone', 'on_scene_contact_role', 'required_resources']);
  const base = basePushVariables
    .filter(([key]) => key !== 'message')
    .filter(([key]) => !controlledLegacyKeys.has(key) || availableIncidentFieldKeys.has(key))
    .map(([key, label]) => ({ key, label }));

  return [...base, ...message, ...extras, ...incidentFields];
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
