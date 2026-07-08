import { FormEvent, useCallback, useEffect, useState } from 'react';
import { KeyRound, Plus, RefreshCw, ShieldCheck, Smartphone, Tablet, Trash2, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { TotpQrCode } from '../../components/TotpQrCode';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { droneTypeLabel } from '../../lib/droneTypes';
import { countryOptions, regionOptionsForCountry } from '../../lib/profileLocation';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { Asset, AvailabilityOverride, AvailabilitySchedule, AvailabilityScheduleDay, Certification, DroneType, FcmToken, MobilePairingClientType, MobilePairingCode, TwoFactorSetup, User, UserCertification } from '../../types/api';

type OwnAssetStatus = 'ready' | 'maintenance' | 'unavailable';
type AvailabilityDayPart = NonNullable<AvailabilityOverride['day_part']>;

const availabilityDayParts: AvailabilityDayPart[] = ['morning', 'afternoon', 'evening'];

interface ProfileFormState {
  firstName: string;
  lastName: string;
  phoneNumber: string;
  homeCity: string;
  homeRegion: string;
  homeCountry: string;
}

interface PairingClientOption {
  value: MobilePairingClientType;
  label: string;
  description: string;
}

const emptyProfileForm: ProfileFormState = {
  firstName: '',
  lastName: '',
  phoneNumber: '',
  homeCity: '',
  homeRegion: '',
  homeCountry: 'NL',
};

function emptyAssetForm() {
  return {
    id: null as string | null,
    name: '',
    type: 'drone',
    droneTypeId: '',
    hasSpotlight: false,
    hasSpeaker: false,
    status: 'ready' as OwnAssetStatus,
    serialNumber: '',
    maintenanceDueAt: '',
    notes: '',
  };
}

function emptyCertificationForm() {
  return {
    id: null as string | null,
    certificationId: '',
    issuedAt: todayInputValue(),
    expiresAt: '',
    certificateNumber: '',
    status: 'active' as UserCertification['status'],
  };
}

export function ProfilePage() {
  const { api, user, theme, setThemePreference, startTwoFactorSetup, enableTwoFactor, disableTwoFactor, refreshMe } = useAuth();
  const assets = useApiResource<Asset[]>('/assets/mine');
  const devices = useApiResource<FcmToken[]>('/devices');
  const schedule = useApiResource<AvailabilitySchedule>('/availability-schedule/me');
  const droneTypes = useApiResource<DroneType[]>('/drone-types');
  const certificationOptions = useApiResource<Certification[]>('/certifications/options');
  const userCertifications = useApiResource<UserCertification[]>('/certifications/me');
  const [setup, setSetup] = useState<TwoFactorSetup | null>(null);
  const [enableCode, setEnableCode] = useState('');
  const [disablePassword, setDisablePassword] = useState('');
  const [disableCode, setDisableCode] = useState('');
  const [workPlanOpen, setWorkPlanOpen] = useState(false);
  const [workPlanDraft, setWorkPlanDraft] = useState<AvailabilityScheduleDay[] | null>(null);
  const [addDeviceOpen, setAddDeviceOpen] = useState(false);
  const [deviceToDelete, setDeviceToDelete] = useState<FcmToken | null>(null);
  const [profileForm, setProfileForm] = useState<ProfileFormState>(emptyProfileForm);
  const [assetForm, setAssetForm] = useState(emptyAssetForm());
  const [certificationForm, setCertificationForm] = useState(emptyCertificationForm());
  const [pairingClientType, setPairingClientType] = useState<MobilePairingClientType | ''>('');
  const [pairing, setPairing] = useState<MobilePairingCode | null>(null);
  const [pairingSecondsLeft, setPairingSecondsLeft] = useState(0);
  const [savingSchedule, setSavingSchedule] = useState(false);
  const [savingProfile, setSavingProfile] = useState(false);
  const [savingAsset, setSavingAsset] = useState(false);
  const [savingCertification, setSavingCertification] = useState(false);
  const [savingDeviceId, setSavingDeviceId] = useState<string | null>(null);
  const [pairingLoading, setPairingLoading] = useState(false);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [themeSaving, setThemeSaving] = useState(false);
  const [autoSetupStarted, setAutoSetupStarted] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [themeMessage, setThemeMessage] = useState<string | null>(null);
  const [themeError, setThemeError] = useState<string | null>(null);
  const [profileMessage, setProfileMessage] = useState<string | null>(null);
  const [profileError, setProfileError] = useState<string | null>(null);
  const [scheduleMessage, setScheduleMessage] = useState<string | null>(null);
  const [scheduleError, setScheduleError] = useState<string | null>(null);
  const [assetMessage, setAssetMessage] = useState<string | null>(null);
  const [assetError, setAssetError] = useState<string | null>(null);
  const [deviceMessage, setDeviceMessage] = useState<string | null>(null);
  const [deviceError, setDeviceError] = useState<string | null>(null);
  const [pairingError, setPairingError] = useState<string | null>(null);
  const [certificationMessage, setCertificationMessage] = useState<string | null>(null);
  const [certificationError, setCertificationError] = useState<string | null>(null);
  const selectedAssetDroneType = droneTypes.data?.find((type) => type.id === assetForm.droneTypeId) ?? null;
  const assetSupportsSpotlight = assetForm.type === 'drone' && selectedAssetDroneType?.has_spotlight === true;
  const assetSupportsSpeaker = assetForm.type === 'drone' && selectedAssetDroneType?.has_speaker === true;
  const pairingOptions = pairingClientOptions(user);

  const mfaRequired = user?.mfa_required === true;

  useEffect(() => {
    if (!user || user.two_factor_enabled || !mfaRequired || setup !== null || autoSetupStarted) {
      return;
    }

    setAutoSetupStarted(true);
    void startSetup();
  }, [autoSetupStarted, mfaRequired, setup, user]);

  useEffect(() => {
    if (user === null) {
      return;
    }

    setProfileForm({
      firstName: user.first_name ?? firstNameFromDisplayName(user.name),
      lastName: user.last_name ?? lastNameFromDisplayName(user.name),
      phoneNumber: user.phone_number ?? '',
      homeCity: user.home_city ?? '',
      homeRegion: user.home_region ?? '',
      homeCountry: user.home_country ?? 'NL',
    });
  }, [user]);

  const loadPairingCode = useCallback(async (clientType: MobilePairingClientType | '') => {
    if (clientType === '') {
      return;
    }

    setPairingLoading(true);
    setPairingError(null);
    try {
      const response = await api.post<MobilePairingCode>('/auth/mobile-pairing', { client_type: clientType });
      setPairing(response.data);
      setPairingSecondsLeft(response.data.ttl_seconds);
    } catch (err) {
      setPairingError(err instanceof ApiClientError ? err.message : 'Koppelcode kon niet worden gemaakt.');
    } finally {
      setPairingLoading(false);
    }
  }, [api]);

  useEffect(() => {
    if (!addDeviceOpen || pairingClientType === '') {
      return undefined;
    }

    let cancelled = false;
    let refreshTimer: number | undefined;

    async function refreshPairingCode() {
      if (cancelled) {
        return;
      }
      await loadPairingCode(pairingClientType);
    }

    void refreshPairingCode();
    refreshTimer = window.setInterval(() => void refreshPairingCode(), 15_000);

    return () => {
      cancelled = true;
      if (refreshTimer !== undefined) {
        window.clearInterval(refreshTimer);
      }
    };
  }, [addDeviceOpen, loadPairingCode, pairingClientType]);

  useEffect(() => {
    if (!addDeviceOpen || pairing === null) {
      return undefined;
    }

    const timer = window.setInterval(() => {
      const nextSeconds = Math.max(0, Math.ceil((new Date(pairing.expires_at).getTime() - Date.now()) / 1000));
      setPairingSecondsLeft(nextSeconds);
    }, 500);

    return () => window.clearInterval(timer);
  }, [addDeviceOpen, pairing]);

  async function startSetup() {
    setBusy(true);
    setError(null);
    setMessage(null);
    setRecoveryCodes([]);
    try {
      setSetup(await startTwoFactorSetup());
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'MFA setup starten mislukt.');
    } finally {
      setBusy(false);
    }
  }

  async function confirmSetup(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setMessage(null);
    try {
      const result = await enableTwoFactor(enableCode);
      setRecoveryCodes(result.recovery_codes);
      setSetup(null);
      setEnableCode('');
      setMessage('MFA is ingeschakeld.');
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'MFA inschakelen mislukt.');
    } finally {
      setBusy(false);
    }
  }

  async function disable(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setMessage(null);
    try {
      await disableTwoFactor(disablePassword, disableCode);
      setDisablePassword('');
      setDisableCode('');
      setMessage('MFA is uitgeschakeld.');
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'MFA uitzetten mislukt.');
    } finally {
      setBusy(false);
    }
  }

  function openWorkPlan() {
    if (schedule.data === null) {
      return;
    }

    setScheduleError(null);
    setScheduleMessage(null);
    setWorkPlanDraft(weekDayPartPattern(schedule.data).map((day) => ({ ...day })));
    setWorkPlanOpen(true);
  }

  function updateWorkPlanDayPart(dayOfWeek: number, dayPart: AvailabilityDayPart, isAvailable: boolean) {
    setWorkPlanDraft((current) => current === null ? current : current.map((day) => (
      day.day_of_week === dayOfWeek && day.day_part === dayPart ? { ...day, is_available: isAvailable } : day
    )));
  }

  async function saveWorkPlan() {
    if (workPlanDraft === null) {
      return;
    }

    setSavingSchedule(true);
    setScheduleError(null);
    setScheduleMessage(null);
    try {
      const response = await api.patch<AvailabilitySchedule>('/availability-schedule/me/week-pattern', {
        patterns: workPlanDraft.map((day) => ({
          day_of_week: day.day_of_week,
          day_part: day.day_part,
          is_available: day.is_available,
          note: day.note ?? null,
        })),
      });
      schedule.mutate(response.data);
      setWorkPlanDraft(weekDayPartPattern(response.data).map((day) => ({ ...day })));
      setScheduleMessage('Vaste dagdelen opgeslagen.');
    } catch (err) {
      setScheduleError(err instanceof ApiClientError ? err.message : 'Vaste dagdelen konden niet worden opgeslagen.');
    } finally {
      setSavingSchedule(false);
    }
  }

  async function planDayPart(date: string, dayPart: AvailabilityDayPart, isAvailable: boolean) {
    setSavingSchedule(true);
    setScheduleError(null);
    setScheduleMessage(null);
    try {
      const response = await api.post<AvailabilitySchedule>('/availability-schedule/me/overrides', {
        starts_at: date,
        ends_at: date,
        day_part: dayPart,
        is_available: isAvailable,
        note: `Gepland via werkplanning: ${availabilityDayPartLabel(dayPart).toLowerCase()}`,
      });
      schedule.mutate(response.data);
      setScheduleMessage(`${shortDateLabel(date)} ${availabilityDayPartLabel(dayPart).toLowerCase()} gepland als ${isAvailable ? 'beschikbaar' : 'niet beschikbaar'}.`);
    } catch (err) {
      setScheduleError(err instanceof ApiClientError ? err.message : 'Dagdeel kon niet worden opgeslagen.');
    } finally {
      setSavingSchedule(false);
    }
  }

  async function submitProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSavingProfile(true);
    setProfileError(null);
    setProfileMessage(null);

    try {
      await api.patch('/auth/me', {
        first_name: profileForm.firstName.trim(),
        last_name: profileForm.lastName.trim(),
        phone_number: profileForm.phoneNumber.trim() === '' ? null : profileForm.phoneNumber.trim(),
        home_city: profileForm.homeCity.trim() === '' ? null : profileForm.homeCity.trim(),
        home_region: profileForm.homeRegion.trim() === '' ? null : profileForm.homeRegion.trim(),
        home_country: profileForm.homeCountry || null,
      });
      await refreshMe();
      setProfileMessage('Profielgegevens opgeslagen.');
    } catch (err) {
      setProfileError(err instanceof ApiClientError ? err.message : 'Profielgegevens konden niet worden opgeslagen.');
    } finally {
      setSavingProfile(false);
    }
  }

  async function submitAsset(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSavingAsset(true);
    setAssetError(null);
    setAssetMessage(null);

    const payload = {
      name: assetForm.name,
      type: assetForm.type,
      drone_type_id: assetForm.type === 'drone' ? assetForm.droneTypeId || null : null,
      has_spotlight: assetSupportsSpotlight ? assetForm.hasSpotlight : false,
      has_speaker: assetSupportsSpeaker ? assetForm.hasSpeaker : false,
      status: assetForm.status,
      serial_number: assetForm.serialNumber || null,
      maintenance_due_at: assetForm.maintenanceDueAt || null,
      notes: assetForm.notes || null,
    };

    try {
      if (assetForm.id === null) {
        await api.post<Asset>('/assets/mine', { ...payload, asset_tag: null });
        setAssetMessage('Asset opgeslagen.');
      } else {
        await api.patch<Asset>(`/assets/${assetForm.id}/mine`, payload);
        setAssetMessage('Asset aangepast.');
      }
      setAssetForm(emptyAssetForm());
      await assets.reload();
    } catch (err) {
      setAssetError(err instanceof ApiClientError ? err.message : 'Asset kon niet worden opgeslagen.');
    } finally {
      setSavingAsset(false);
    }
  }

  async function deleteAsset(asset: Asset) {
    setSavingAsset(true);
    setAssetError(null);
    setAssetMessage(null);
    try {
      await api.delete(`/assets/${asset.id}/mine`);
      if (assetForm.id === asset.id) {
        setAssetForm(emptyAssetForm());
      }
      setAssetMessage('Asset verwijderd.');
      await assets.reload();
    } catch (err) {
      setAssetError(err instanceof ApiClientError ? err.message : 'Asset kon niet worden verwijderd.');
    } finally {
      setSavingAsset(false);
    }
  }

  function setAssetFormFromAsset(asset: Asset) {
    setAssetError(null);
    setAssetMessage(null);
    setAssetForm({
      id: asset.id,
      name: asset.name,
      type: asset.type,
      droneTypeId: asset.drone_type_id ?? '',
      hasSpotlight: asset.has_spotlight,
      hasSpeaker: asset.has_speaker,
      status: ownAssetStatus(asset.status),
      serialNumber: asset.serial_number ?? '',
      maintenanceDueAt: normalizeDate(asset.maintenance_due_at),
      notes: asset.notes ?? '',
    });
  }

  async function submitCertification(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSavingCertification(true);
    setCertificationError(null);
    setCertificationMessage(null);

    const payload = {
      certification_id: certificationForm.certificationId,
      issued_at: certificationForm.issuedAt,
      expires_at: certificationForm.expiresAt || null,
      certificate_number: certificationForm.certificateNumber || null,
      status: certificationForm.status,
    };

    try {
      if (certificationForm.id === null) {
        await api.post<UserCertification>('/certifications/me', payload);
        setCertificationMessage('Certificaat opgeslagen.');
      } else {
        await api.patch<UserCertification>(`/certifications/me/${certificationForm.id}`, payload);
        setCertificationMessage('Certificaat aangepast.');
      }
      setCertificationForm(emptyCertificationForm());
      await userCertifications.reload();
    } catch (err) {
      setCertificationError(err instanceof ApiClientError ? err.message : 'Certificaat kon niet worden opgeslagen.');
    } finally {
      setSavingCertification(false);
    }
  }

  async function deleteCertification(certification: UserCertification) {
    setSavingCertification(true);
    setCertificationError(null);
    setCertificationMessage(null);
    try {
      await api.delete(`/certifications/me/${certification.id}`);
      if (certificationForm.id === certification.id) {
        setCertificationForm(emptyCertificationForm());
      }
      setCertificationMessage('Certificaat verwijderd.');
      await userCertifications.reload();
    } catch (err) {
      setCertificationError(err instanceof ApiClientError ? err.message : 'Certificaat kon niet worden verwijderd.');
    } finally {
      setSavingCertification(false);
    }
  }

  async function updateThemePreference(nextTheme: 'dark' | 'light') {
    setThemeSaving(true);
    setThemeError(null);
    setThemeMessage(null);
    try {
      await setThemePreference(nextTheme);
      setThemeMessage(`Weergave ingesteld op ${nextTheme === 'dark' ? 'donker' : 'licht'}.`);
    } catch (err) {
      setThemeError(err instanceof ApiClientError ? err.message : 'Weergave kon niet worden opgeslagen.');
    } finally {
      setThemeSaving(false);
    }
  }

  function openAddDeviceModal() {
    setDeviceError(null);
    setDeviceMessage(null);
    setPairingError(null);
    setPairing(null);
    setPairingSecondsLeft(0);
    setPairingClientType(pairingOptions[0]?.value ?? '');
    setAddDeviceOpen(true);
  }

  function closeAddDeviceModal() {
    setAddDeviceOpen(false);
    setPairing(null);
    setPairingError(null);
    setPairingSecondsLeft(0);
    void devices.reload();
    void refreshMe();
  }

  async function refreshPairingCodeManually() {
    await loadPairingCode(pairingClientType);
  }

  async function confirmDeleteDevice() {
    if (deviceToDelete === null) {
      return;
    }

    setSavingDeviceId(deviceToDelete.id);
    setDeviceError(null);
    setDeviceMessage(null);
    try {
      await api.delete<null>(`/devices/fcm-token/${deviceToDelete.id}`);
      devices.mutate((current) => current?.filter((token) => token.id !== deviceToDelete.id) ?? []);
      await Promise.all([devices.reload(), refreshMe()]);
      setDeviceMessage('Toestel verwijderd.');
      setDeviceToDelete(null);
    } catch (err) {
      setDeviceError(err instanceof ApiClientError ? err.message : 'Toestel kon niet worden verwijderd.');
    } finally {
      setSavingDeviceId(null);
    }
  }

  function setCertificationFormFromCertification(certification: UserCertification) {
    setCertificationError(null);
    setCertificationMessage(null);
    setCertificationForm({
      id: certification.id,
      certificationId: certification.certification_id,
      issuedAt: normalizeDate(certification.issued_at) || todayInputValue(),
      expiresAt: normalizeDate(certification.expires_at),
      certificateNumber: certification.certificate_number ?? '',
      status: certification.status === 'pending' ? 'active' : certification.status,
    });
  }

  return (
    <div className="page-stack profile-page">
      <Panel title="Profiel">
        <div className="definition-grid">
          <dt>Naam</dt>
          <dd>{user?.name ?? '-'}</dd>
          <dt>E-mail</dt>
          <dd>{user?.email ?? '-'}</dd>
          <dt>Rollen</dt>
          <dd>{user?.roles?.map((role) => role.display_name).join(', ') || '-'}</dd>
          <dt>MFA status</dt>
          <dd>{user?.two_factor_enabled ? 'Ingeschakeld' : 'Uitgeschakeld'}</dd>
          <dt>MFA verplicht</dt>
          <dd>{mfaRequired ? 'Ja, systeemwijd' : 'Nee'}</dd>
          <dt>Weergave</dt>
          <dd>
            <div className="segmented-control" role="group" aria-label="Weergave">
              <button className={theme === 'dark' ? 'segmented-control__item segmented-control__item--active' : 'segmented-control__item'} type="button" onClick={() => void updateThemePreference('dark')} disabled={themeSaving}>
                Donker
              </button>
              <button className={theme === 'light' ? 'segmented-control__item segmented-control__item--active' : 'segmented-control__item'} type="button" onClick={() => void updateThemePreference('light')} disabled={themeSaving}>
                Licht
              </button>
            </div>
          </dd>
        </div>
        {themeMessage ? <p className="form-note">{themeMessage}</p> : null}
        {themeError ? <p className="form-error">{themeError}</p> : null}
      </Panel>

      <Panel title="Mijn gegevens">
        <form className="form-grid" onSubmit={submitProfile}>
          <label>
            Voornaam
            <input value={profileForm.firstName} maxLength={80} required onChange={(event) => setProfileForm((current) => ({ ...current, firstName: event.target.value }))} />
          </label>
          <label>
            Achternaam
            <input value={profileForm.lastName} maxLength={120} required onChange={(event) => setProfileForm((current) => ({ ...current, lastName: event.target.value }))} />
          </label>
          <label>
            Telefoonnummer
            <input value={profileForm.phoneNumber} inputMode="tel" autoComplete="tel" placeholder="+31612345678" required onChange={(event) => setProfileForm((current) => ({ ...current, phoneNumber: event.target.value }))} />
            <small>Wordt bij opslaan internationaal gemaakt op basis van land.</small>
          </label>
          <label>
            Woonplaats
            <input value={profileForm.homeCity} maxLength={120} required onChange={(event) => setProfileForm((current) => ({ ...current, homeCity: event.target.value }))} />
          </label>
          <label>
            Land
            <select
              value={profileForm.homeCountry}
              required
              onChange={(event) => setProfileForm((current) => {
                const nextCountry = event.target.value;
                const nextRegions = regionOptionsForCountry(nextCountry);
                return {
                  ...current,
                  homeCountry: nextCountry,
                  homeRegion: nextRegions.includes(current.homeRegion) ? current.homeRegion : '',
                };
              })}
            >
              {countryOptions.map((country) => (
                <option key={country.value} value={country.value}>{country.label}</option>
              ))}
            </select>
          </label>
          <label>
            Provincie / regio
            <select
              value={profileForm.homeRegion}
              required={regionOptionsForCountry(profileForm.homeCountry).length > 0}
              disabled={regionOptionsForCountry(profileForm.homeCountry).length === 0}
              onChange={(event) => setProfileForm((current) => ({ ...current, homeRegion: event.target.value }))}
            >
              <option value="">Kies provincie/regio</option>
              {regionOptionsForCountry(profileForm.homeCountry).map((region) => (
                <option key={region} value={region}>{region}</option>
              ))}
            </select>
          </label>
          {profileError ? <p className="form-error form-grid__wide">{profileError}</p> : null}
          {profileMessage ? <p className="form-note form-grid__wide">{profileMessage}</p> : null}
          <div className="actions-row form-grid__wide">
            <button className="primary-button" type="submit" disabled={savingProfile}>
              {savingProfile ? 'Opslaan...' : 'Gegevens opslaan'}
            </button>
          </div>
        </form>
      </Panel>

      <Panel title="Mijn toestellen">
        <div className="device-management-card">
          <div>
            <strong>Gekoppelde toestellen</strong>
            <p>Beheer de mobiele toestellen die pushmeldingen voor jouw account mogen ontvangen.</p>
          </div>
          <button className="primary-button" type="button" onClick={openAddDeviceModal}>
            <Plus size={16} /> Toestel toevoegen
          </button>
        </div>
        {deviceError ? <p className="form-error">{deviceError}</p> : null}
        {deviceMessage ? <p className="form-note">{deviceMessage}</p> : null}
        <DeviceCards
          devices={devices.data ?? []}
          loading={devices.loading}
          error={devices.error}
          busyDeviceId={savingDeviceId}
          onDelete={(device) => setDeviceToDelete(device)}
        />
      </Panel>

      {addDeviceOpen ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal device-pairing-modal" role="dialog" aria-modal="true" aria-labelledby="device-pairing-title">
            <header className="modal__header">
              <div>
                <span className="modal__eyebrow">Profiel</span>
                <h2 id="device-pairing-title">Toestel toevoegen</h2>
              </div>
              <button className="icon-button" type="button" onClick={closeAddDeviceModal} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <div className="device-pairing-body">
              {pairingOptions.length === 0 ? (
                <p className="form-error">Je account heeft geen toegang tot de mobiele operator- of admin app.</p>
              ) : (
                <>
                  <div className="device-pairing-intro">
                    <Smartphone aria-hidden size={20} />
                    <div>
                      <strong>Open de mobiele app en scan de QR-code.</strong>
                      <span>De code is eenmalig en wordt automatisch vernieuwd.</span>
                    </div>
                  </div>
                  <label>
                    App
                    <select
                      value={pairingClientType}
                      onChange={(event) => {
                        const nextClientType = event.target.value as MobilePairingClientType;
                        setPairingClientType(nextClientType);
                        setPairing(null);
                        setPairingError(null);
                        setPairingSecondsLeft(0);
                      }}
                    >
                      {pairingOptions.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                      ))}
                    </select>
                    <small>{pairingOptions.find((option) => option.value === pairingClientType)?.description}</small>
                  </label>
                  {pairingError ? <p className="form-error">{pairingError}</p> : null}
                  {pairing ? (
                    <div className="device-pairing-grid">
                      <div className="device-pairing-manual">
                        <span>Handmatig koppelen</span>
                        <label>
                          Server
                          <input value={pairing.server_url} readOnly onFocus={(event) => event.currentTarget.select()} />
                        </label>
                        <label>
                          Koppelcode
                          <input className="mono" value={pairing.code} readOnly onFocus={(event) => event.currentTarget.select()} />
                        </label>
                        <small>Nog {pairingSecondsLeft} seconden geldig.</small>
                        <a className="primary-button" href={pairing.deeplink_url}>
                          Open app en koppel dit toestel
                        </a>
                      </div>
                      <div className="device-pairing-qr">
                        <TotpQrCode value={pairing.qr_payload} alt="QR-code toestel koppelen" helpText="Scan deze QR-code in de mobiele app." />
                      </div>
                    </div>
                  ) : (
                    <p className="resource-state resource-state--loading">{pairingLoading ? 'Koppelcode maken...' : 'Nog geen koppelcode gemaakt.'}</p>
                  )}
                </>
              )}
            </div>
            <div className="actions-row">
              <button className="secondary-button" type="button" onClick={closeAddDeviceModal}>
                Sluiten
              </button>
              {pairingOptions.length > 0 ? (
                <button className="primary-button" type="button" onClick={() => void refreshPairingCodeManually()} disabled={pairingLoading || pairingClientType === ''}>
                  <RefreshCw size={16} /> Vernieuwen
                </button>
              ) : null}
            </div>
          </section>
        </div>
      ) : null}

      {deviceToDelete ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal modal--narrow" role="dialog" aria-modal="true" aria-labelledby="delete-device-title">
            <header className="modal__header">
              <div>
                <span className="modal__eyebrow">Profiel</span>
                <h2 id="delete-device-title">Toestel verwijderen?</h2>
              </div>
              <button className="icon-button" type="button" onClick={() => setDeviceToDelete(null)} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <div className="confirm-dialog">
              <p>Weet je zeker dat je <strong>{deviceDisplayName(deviceToDelete)}</strong> wilt verwijderen?</p>
              <p>Dit toestel ontvangt daarna geen pushmeldingen meer en telt niet meer mee als gekoppeld toestel.</p>
            </div>
            <div className="actions-row">
              <button className="secondary-button" type="button" onClick={() => setDeviceToDelete(null)} disabled={savingDeviceId !== null}>
                Annuleren
              </button>
              <button className="danger-button" type="button" onClick={() => void confirmDeleteDevice()} disabled={savingDeviceId !== null}>
                {savingDeviceId === deviceToDelete.id ? 'Verwijderen...' : 'Toestel verwijderen'}
              </button>
            </div>
          </section>
        </div>
      ) : null}

      <Panel title="Mijn beschikbaarheid">
        <ResourceState loading={schedule.loading} error={schedule.error ?? scheduleError} empty={schedule.data === null}>
          {schedule.data !== null ? (
            <div className="panel-body">
              <div className="summary-grid">
                <SummaryItem label="Vandaag" value={schedule.data.today.is_available ? 'Beschikbaar' : 'Niet beschikbaar'} />
                <SummaryItem label="Bron" value={availabilitySourceLabel(schedule.data.today.source)} />
              </div>
              <div className="work-plan-card">
                <div className="work-plan-card__header">
                  <div>
                    <strong>Dagdelenplanning</strong>
                    <p>Plan je beschikbaarheid voor de komende twee weken per ochtend, middag en avond.</p>
                  </div>
                  <button className="primary-button" type="button" onClick={openWorkPlan} disabled={savingSchedule}>
                    Dagdelen plannen
                  </button>
                </div>
              </div>
              {scheduleError ? <p className="form-error">{scheduleError}</p> : null}
              {scheduleMessage ? <p className="form-note">{scheduleMessage}</p> : null}
            </div>
          ) : null}
        </ResourceState>
      </Panel>

      {workPlanOpen && workPlanDraft !== null && schedule.data !== null ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal work-plan-modal" role="dialog" aria-modal="true" aria-labelledby="work-plan-title">
            <header className="modal__header">
              <div>
                <span className="modal__eyebrow">Profiel</span>
                <h2 id="work-plan-title">Dagdelen plannen</h2>
              </div>
              <button className="icon-button" type="button" onClick={() => { setWorkPlanOpen(false); setWorkPlanDraft(null); }} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <div className="panel-body">
              <section className="stacked-section">
                <div className="section-heading">
                  <strong>Vaste weekdagen per dagdeel</strong>
                  <span>Stel je standaard beschikbaarheid in voor ochtend, middag en avond.</span>
                </div>
                <div className="week-daypart-grid">
                  {rangeDays().map((dayOfWeek) => (
                    <article className="week-daypart-row" key={dayOfWeek}>
                      <strong>{dayLabel(dayOfWeek)}</strong>
                      <div className="daypart-planner-actions">
                        {availabilityDayParts.map((dayPart) => {
                          const state = workPlanDraft.find((day) => day.day_of_week === dayOfWeek && day.day_part === dayPart);
                          const isAvailable = state?.is_available ?? true;
                          return (
                            <button
                              className={isAvailable ? 'secondary-button' : 'danger-button'}
                              type="button"
                              key={dayPart}
                              disabled={savingSchedule}
                              onClick={() => updateWorkPlanDayPart(dayOfWeek, dayPart, !isAvailable)}
                              title={`${dayLabel(dayOfWeek)} ${availabilityDayPartLabel(dayPart).toLowerCase()} standaard ${isAvailable ? 'niet beschikbaar' : 'beschikbaar'} zetten`}
                            >
                              {availabilityDayPartLabel(dayPart)}: {isAvailable ? 'Aan' : 'Uit'}
                            </button>
                          );
                        })}
                      </div>
                    </article>
                  ))}
                </div>
              </section>
              <section className="stacked-section">
                <div className="section-heading">
                  <strong>2 weken vooruit plannen</strong>
                  <span>Gebruik dit voor afwijkingen op je vaste weekdagen.</span>
                </div>
                <div className="daypart-planner">
                  {nextCalendarDays(14).map((date) => (
                    <article className="daypart-planner-row" key={date}>
                      <div>
                        <strong>{shortDateLabel(date)}</strong>
                        <span>{formatDate(date)}</span>
                      </div>
                      <div className="daypart-planner-actions">
                        {availabilityDayParts.map((dayPart) => {
                          const state = availabilityForDatePart(schedule.data!, date, dayPart);
                          return (
                            <button
                              className={state.is_available ? 'secondary-button' : 'danger-button'}
                              type="button"
                              key={dayPart}
                              disabled={savingSchedule}
                              onClick={() => void planDayPart(date, dayPart, !state.is_available)}
                              title={`${availabilityDayPartLabel(dayPart)} wisselen naar ${state.is_available ? 'niet beschikbaar' : 'beschikbaar'}`}
                            >
                              {availabilityDayPartLabel(dayPart)}: {state.is_available ? 'Aan' : 'Uit'}
                            </button>
                          );
                        })}
                      </div>
                    </article>
                  ))}
                </div>
              </section>
              {scheduleMessage ? <p className="form-note">{scheduleMessage}</p> : null}
              {scheduleError ? <p className="form-error">{scheduleError}</p> : null}
            </div>
            <div className="actions-row">
              <button className="secondary-button" type="button" onClick={() => { setWorkPlanOpen(false); setWorkPlanDraft(null); }} disabled={savingSchedule}>
                Sluiten
              </button>
              <button className="primary-button" type="button" onClick={() => void saveWorkPlan()} disabled={savingSchedule}>
                {savingSchedule ? 'Opslaan...' : 'Vaste dagdelen opslaan'}
              </button>
            </div>
          </section>
        </div>
      ) : null}

      <Panel title="Mijn assets">
        <form className="form-grid" onSubmit={submitAsset}>
          <label>
            Naam
            <input value={assetForm.name} onChange={(event) => setAssetForm((current) => ({ ...current, name: event.target.value }))} required />
          </label>
          <label>
            Type
            <select value={assetForm.type} onChange={(event) => setAssetForm((current) => ({ ...current, type: event.target.value, droneTypeId: '', hasSpotlight: false, hasSpeaker: false }))}>
              <option value="drone">Drone</option>
              <option value="battery">Batterij</option>
              <option value="sensor">Sensor</option>
              <option value="vehicle">Voertuig</option>
              <option value="support_equipment">Ondersteunend materieel</option>
            </select>
          </label>
          {assetForm.type === 'drone' ? (
            <label>
              Drone type
              <select
                value={assetForm.droneTypeId}
                onChange={(event) => {
                  const nextDroneType = droneTypes.data?.find((type) => type.id === event.target.value) ?? null;
                  setAssetForm((current) => ({
                    ...current,
                    droneTypeId: event.target.value,
                    hasSpotlight: nextDroneType?.has_spotlight === true ? current.hasSpotlight : false,
                    hasSpeaker: nextDroneType?.has_speaker === true ? current.hasSpeaker : false,
                  }));
                }}
                required
              >
                <option value="">Kies drone type</option>
                {droneTypes.data?.filter((type) => type.is_active || type.id === assetForm.droneTypeId).map((type) => (
                  <option key={type.id} value={type.id}>{droneTypeLabel(type)}</option>
                ))}
              </select>
            </label>
          ) : null}
          <label>
            Status
            <select value={assetForm.status} onChange={(event) => setAssetForm((current) => ({ ...current, status: event.target.value as OwnAssetStatus }))}>
              <option value="ready">Gereed</option>
              <option value="maintenance">Onderhoud</option>
              <option value="unavailable">Niet beschikbaar</option>
            </select>
          </label>
          <label>
            Serienummer
            <input value={assetForm.serialNumber} onChange={(event) => setAssetForm((current) => ({ ...current, serialNumber: event.target.value }))} />
          </label>
          <label>
            Onderhoud voor
            <input type="date" value={assetForm.maintenanceDueAt} onChange={(event) => setAssetForm((current) => ({ ...current, maintenanceDueAt: event.target.value }))} />
          </label>
          {assetSupportsSpotlight ? (
            <label className="check-label">
              <input type="checkbox" checked={assetForm.hasSpotlight} onChange={(event) => setAssetForm((current) => ({ ...current, hasSpotlight: event.target.checked }))} />
              Externe lamp
            </label>
          ) : null}
          {assetSupportsSpeaker ? (
            <label className="check-label">
              <input type="checkbox" checked={assetForm.hasSpeaker} onChange={(event) => setAssetForm((current) => ({ ...current, hasSpeaker: event.target.checked }))} />
              Speaker
            </label>
          ) : null}
          <label className="form-grid__wide">
            Notities
            <textarea value={assetForm.notes} onChange={(event) => setAssetForm((current) => ({ ...current, notes: event.target.value }))} />
          </label>
          {assetError ? <p className="form-error form-grid__wide">{assetError}</p> : null}
          {assetMessage ? <p className="form-note form-grid__wide">{assetMessage}</p> : null}
          <div className="actions-row form-grid__wide">
            {assetForm.id !== null ? <button className="secondary-button" type="button" onClick={() => setAssetForm(emptyAssetForm())}>Nieuw</button> : null}
            <button className="primary-button" type="submit" disabled={savingAsset || assetForm.name === '' || (assetForm.type === 'drone' && assetForm.droneTypeId === '')}>
              {savingAsset ? 'Opslaan...' : assetForm.id === null ? 'Asset toevoegen' : 'Asset aanpassen'}
            </button>
          </div>
        </form>
        <AssetTable assets={assets.data ?? []} loading={assets.loading || droneTypes.loading} error={assets.error ?? droneTypes.error} onEdit={setAssetFormFromAsset} onDelete={deleteAsset} />
      </Panel>

      <Panel title="Mijn certificaten">
        <form className="form-grid" onSubmit={submitCertification}>
          <label>
            Certificaat
            <select
              value={certificationForm.certificationId}
              onChange={(event) => setCertificationForm((current) => ({ ...current, certificationId: event.target.value }))}
              required
              disabled={certificationForm.id !== null}
            >
              <option value="">Kies certificaat</option>
              {certificationOptions.data?.map((certification) => (
                <option key={certification.id} value={certification.id}>{certification.name}</option>
              ))}
            </select>
          </label>
          <label>
            Afgifte
            <input type="date" value={certificationForm.issuedAt} onChange={(event) => setCertificationForm((current) => ({ ...current, issuedAt: event.target.value }))} required />
          </label>
          <label>
            Verloopt
            <input type="date" value={certificationForm.expiresAt} onChange={(event) => setCertificationForm((current) => ({ ...current, expiresAt: event.target.value }))} />
          </label>
          <label>
            Nummer
            <input value={certificationForm.certificateNumber} onChange={(event) => setCertificationForm((current) => ({ ...current, certificateNumber: event.target.value }))} />
          </label>
          <label>
            Status
            <select value={certificationForm.status} onChange={(event) => setCertificationForm((current) => ({ ...current, status: event.target.value as UserCertification['status'] }))}>
              <option value="active">Actief</option>
              <option value="expired">Verlopen</option>
              <option value="revoked">Ingetrokken</option>
            </select>
          </label>
          {certificationError ? <p className="form-error form-grid__wide">{certificationError}</p> : null}
          {certificationMessage ? <p className="form-note form-grid__wide">{certificationMessage}</p> : null}
          <div className="actions-row form-grid__wide">
            {certificationForm.id !== null ? <button className="secondary-button" type="button" onClick={() => setCertificationForm(emptyCertificationForm())}>Nieuw</button> : null}
            <button className="primary-button" type="submit" disabled={savingCertification || certificationForm.certificationId === '' || certificationForm.issuedAt === ''}>
              {savingCertification ? 'Opslaan...' : certificationForm.id === null ? 'Certificaat toevoegen' : 'Certificaat aanpassen'}
            </button>
          </div>
        </form>
        <CertificationTable
          certifications={userCertifications.data ?? []}
          loading={userCertifications.loading || certificationOptions.loading}
          error={userCertifications.error ?? certificationOptions.error}
          onEdit={setCertificationFormFromCertification}
          onDelete={deleteCertification}
        />
      </Panel>

      <Panel title="Multi-factor authenticatie">
        <div className="mfa-card">
          <div className="mfa-card__icon"><ShieldCheck size={22} /></div>
          <div>
            <strong>{user?.two_factor_enabled ? 'MFA actief' : 'MFA niet actief'}</strong>
            <p>{mfaRequired && !user?.two_factor_enabled ? 'Stel je Authenticator app in om verder te gaan.' : 'Gebruik een authenticator app met 6-cijferige TOTP-codes.'}</p>
          </div>
          {!user?.two_factor_enabled ? (
            <button className="primary-button" type="button" onClick={startSetup} disabled={busy}>
              <KeyRound size={16} /> MFA instellen
            </button>
          ) : null}
        </div>

        {setup?.secret ? (
          <form className="form-grid" onSubmit={confirmSetup}>
            <div className="form-grid__wide">
              <TotpQrCode value={setup.provisioning_uri} alt="MFA QR-code voor Authenticator app" helpText="Scan deze QR-code met je Authenticator app." />
            </div>
            <label className="form-grid__wide">
              Secret
              <input className="mono" value={setup.secret} readOnly />
            </label>
            <label className="form-grid__wide">
              Authenticator URI
              <textarea className="mono" value={setup.provisioning_uri ?? ''} readOnly />
            </label>
            <label>
              6-cijferige code
              <input inputMode="numeric" pattern="[0-9]{6}" value={enableCode} onChange={(event) => setEnableCode(event.target.value)} required />
            </label>
            <div className="actions-row form-grid__wide">
              <button className="primary-button" type="submit" disabled={busy || enableCode.length !== 6}>
                MFA bevestigen
              </button>
            </div>
          </form>
        ) : null}

        {user?.two_factor_enabled ? (
          <form className="form-grid" onSubmit={disable}>
            <label>
              Huidig wachtwoord
              <input type="password" value={disablePassword} onChange={(event) => setDisablePassword(event.target.value)} required />
            </label>
            <label>
              6-cijferige MFA-code
              <input inputMode="numeric" pattern="[0-9]{6}" value={disableCode} onChange={(event) => setDisableCode(event.target.value)} required />
            </label>
            <div className="actions-row form-grid__wide">
              <button className="secondary-button" type="submit" disabled={busy || mfaRequired}>
                MFA uitzetten
              </button>
            </div>
          </form>
        ) : null}

        {mfaRequired && user?.two_factor_enabled ? (
          <p className="error-text">MFA kan pas uit nadat de globale MFA-verplichting is uitgezet.</p>
        ) : null}
        {error ? <p className="error-text">{error}</p> : null}
        {message ? <p className="success-text">{message}</p> : null}
      </Panel>

      {recoveryCodes.length > 0 ? (
        <Panel title="Recovery codes">
          <pre>{recoveryCodes.join('\n')}</pre>
        </Panel>
      ) : null}
    </div>
  );
}

function SummaryItem({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

function DeviceCards({
  devices,
  loading,
  error,
  busyDeviceId,
  onDelete,
}: {
  devices: FcmToken[];
  loading: boolean;
  error: string | null;
  busyDeviceId: string | null;
  onDelete: (device: FcmToken) => void;
}) {
  return (
    <ResourceState loading={loading} error={error} empty={devices.length === 0}>
      <div className="device-card-grid">
        {devices.map((device) => {
          const DeviceIcon = device.device_type === 'tablet' ? Tablet : Smartphone;
          const status = deviceStatus(device);

          return (
            <article className="device-card" key={device.id}>
              <div className="device-card__icon">
                <DeviceIcon aria-hidden size={20} />
              </div>
              <div className="device-card__content">
                <div className="device-card__title">
                  <strong>{deviceDisplayName(device)}</strong>
                  <StatusPill value={status.label} tone={status.tone} />
                </div>
                <dl className="device-card__meta">
                  <div>
                    <dt>Type</dt>
                    <dd>{deviceTypeLabel(device.device_type)} / {deviceClientLabel(device.client_type)}</dd>
                  </div>
                  <div>
                    <dt>Platform</dt>
                    <dd>{devicePlatformLabel(device.platform)}{device.app_version ? ` ${device.app_version}` : ''}</dd>
                  </div>
                  <div>
                    <dt>Hardware</dt>
                    <dd>{deviceHardwareLabel(device)}</dd>
                  </div>
                  <div>
                    <dt>Laatst gezien</dt>
                    <dd>{formatDateTime(device.last_seen_at)}</dd>
                  </div>
                </dl>
              </div>
              <div className="device-card__actions">
                <button className="secondary-button" type="button" onClick={() => onDelete(device)} disabled={busyDeviceId === device.id}>
                  <Trash2 size={16} /> Verwijderen
                </button>
              </div>
            </article>
          );
        })}
      </div>
    </ResourceState>
  );
}

function pairingClientOptions(user?: User | null): PairingClientOption[] {
  const roles = user?.roles ?? [];
  const canUseOperatorApp = roles.some((role) => role.can_use_operator_app);
  const canUseAdminApp = roles.some((role) => role.can_use_admin_app);
  const options: PairingClientOption[] = [];

  if (canUseOperatorApp) {
    options.push({ value: 'operator', label: 'Operator app', description: 'Voor de operationele app op Android en iPhone.' });
  }

  if (canUseAdminApp) {
    options.push({ value: 'admin', label: 'Admin app', description: 'Voor de mobiele admin app op Android en iPhone.' });
  }

  return options;
}

function deviceDisplayName(device: FcmToken): string {
  return device.device_name?.trim() || deviceHardwareLabel(device);
}

function deviceHardwareLabel(device: FcmToken): string {
  const hardware = [device.device_manufacturer, device.device_model].filter(Boolean).join(' ').trim();

  return hardware || device.device_id;
}

function deviceTypeLabel(type?: string | null): string {
  switch (type) {
    case 'phone':
      return 'Telefoon';
    case 'tablet':
      return 'Tablet';
    default:
      return 'Onbekend';
  }
}

function deviceClientLabel(clientType?: string | null): string {
  switch (clientType) {
    case 'admin':
      return 'Admin app';
    case 'operator':
      return 'Operator app';
    default:
      return clientType ?? 'App';
  }
}

function devicePlatformLabel(platform?: string | null): string {
  switch (platform) {
    case 'android':
      return 'Android';
    case 'ios':
      return 'iOS';
    default:
      return platform ?? '-';
  }
}

function deviceStatus(device: FcmToken): { label: string; tone: 'good' | 'warn' | 'neutral' | 'bad' } {
  if (!device.is_active) {
    return { label: 'Verwijderd', tone: 'neutral' };
  }

  if (device.client_type === 'operator') {
    return device.is_online ? { label: 'Online', tone: 'good' } : { label: 'Offline', tone: 'warn' };
  }

  return { label: 'Actief', tone: 'neutral' };
}

function firstNameFromDisplayName(name: string): string {
  return name.trim().split(/\s+/, 1)[0] ?? '';
}

function lastNameFromDisplayName(name: string): string {
  const parts = name.trim().split(/\s+/);
  return parts.length > 1 ? parts.slice(1).join(' ') : '';
}

function AssetTable({
  assets,
  loading,
  error,
  onEdit,
  onDelete,
}: {
  assets: Asset[];
  loading: boolean;
  error: string | null;
  onEdit: (asset: Asset) => void;
  onDelete: (asset: Asset) => Promise<void>;
}) {
  return (
    <ResourceState loading={loading} error={error} empty={assets.length === 0}>
      <table className="data-table">
        <thead>
          <tr>
            <th scope="col">Naam</th>
            <th scope="col">Type</th>
            <th scope="col">Status</th>
            <th scope="col">Serienummer</th>
            <th scope="col">Onderhoud</th>
            <th scope="col">Actie</th>
          </tr>
        </thead>
        <tbody>
          {assets.map((asset) => (
            <tr key={asset.id}>
              <td>{asset.name}</td>
              <td>{asset.drone_type ? droneTypeLabel(asset.drone_type) : assetTypeLabel(asset.type)}</td>
              <td><StatusPill value={assetStatusLabel(asset.status)} tone={asset.status === 'ready' ? 'good' : asset.status === 'maintenance' ? 'warn' : 'neutral'} /></td>
              <td>{asset.serial_number ?? '-'}</td>
              <td>{formatDate(asset.maintenance_due_at)}</td>
              <td>
                <div className="actions-row">
                  <button className="secondary-button" type="button" onClick={() => onEdit(asset)}>Aanpassen</button>
                  <button className="secondary-button" type="button" onClick={() => void onDelete(asset)}><Trash2 size={16} /> Verwijderen</button>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </ResourceState>
  );
}

function CertificationTable({
  certifications,
  loading,
  error,
  onEdit,
  onDelete,
}: {
  certifications: UserCertification[];
  loading: boolean;
  error: string | null;
  onEdit: (certification: UserCertification) => void;
  onDelete: (certification: UserCertification) => Promise<void>;
}) {
  return (
    <ResourceState loading={loading} error={error} empty={certifications.length === 0}>
      <table className="data-table">
        <thead>
          <tr>
            <th scope="col">Certificaat</th>
            <th scope="col">Status</th>
            <th scope="col">Nummer</th>
            <th scope="col">Afgifte</th>
            <th scope="col">Verloopt</th>
            <th scope="col">Actie</th>
          </tr>
        </thead>
        <tbody>
          {certifications.map((certification) => (
            <tr key={certification.id}>
              <td>{certification.certification?.name ?? '-'}</td>
              <td><StatusPill value={certificationStatusLabel(certification.status)} tone={certification.status === 'active' ? 'good' : 'warn'} /></td>
              <td>{certification.certificate_number ?? '-'}</td>
              <td>{formatDate(certification.issued_at)}</td>
              <td>{formatDate(certification.expires_at)}</td>
              <td>
                <div className="actions-row">
                  <button className="secondary-button" type="button" onClick={() => onEdit(certification)}>Aanpassen</button>
                  <button className="secondary-button" type="button" onClick={() => void onDelete(certification)}><Trash2 size={16} /> Verwijderen</button>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </ResourceState>
  );
}

function todayInputValue(): string {
  const today = new Date();
  const year = today.getFullYear();
  const month = String(today.getMonth() + 1).padStart(2, '0');
  const day = String(today.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

function normalizeDate(value?: string | null): string {
  return dateParts(value)?.input ?? '';
}

function formatDate(value?: string | null): string {
  return dateParts(value)?.display ?? '-';
}

function dateParts(value?: string | null): { input: string; display: string } | null {
  const match = value?.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!match) {
    return null;
  }

  const [, year, month, day] = match;

  return {
    input: `${year}-${month}-${day}`,
    display: `${day}-${month}-${year}`,
  };
}

function dayLabel(dayOfWeek: number): string {
  return ['Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag', 'Zondag'][dayOfWeek - 1] ?? String(dayOfWeek);
}

function rangeDays(): number[] {
  return [1, 2, 3, 4, 5, 6, 7];
}

function weekDayPartPattern(schedule: AvailabilitySchedule): AvailabilityScheduleDay[] {
  return rangeDays().flatMap((dayOfWeek) => availabilityDayParts.map((dayPart) => {
    const specific = schedule.week_day_parts?.find((day) => day.day_of_week === dayOfWeek && day.day_part === dayPart);
    const fallback = schedule.week_pattern.find((day) => day.day_of_week === dayOfWeek);

    return {
      day_of_week: dayOfWeek,
      day_part: dayPart,
      is_available: specific?.is_available ?? fallback?.is_available ?? true,
      note: specific?.note ?? fallback?.note ?? null,
      source: specific?.source ?? fallback?.source ?? 'default',
    };
  }));
}

function availabilitySourceLabel(source: AvailabilitySchedule['today']['source']): string {
  switch (source) {
    case 'override':
      return 'Planning';
    case 'pattern':
    case 'week_pattern':
      return 'Werkplanning';
    default:
      return 'Standaard beschikbaar';
  }
}

function nextCalendarDays(count: number): string[] {
  const today = new Date();
  today.setHours(12, 0, 0, 0);

  return Array.from({ length: count }, (_, index) => {
    const date = new Date(today);
    date.setDate(today.getDate() + index);

    return inputDateValue(date);
  });
}

function inputDateValue(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

function shortDateLabel(value: string): string {
  const date = dateFromInput(value);
  if (date === null) {
    return value;
  }

  return new Intl.DateTimeFormat('nl-NL', { weekday: 'short', day: '2-digit', month: '2-digit' }).format(date);
}

function availabilityForDatePart(schedule: AvailabilitySchedule, dateValue: string, dayPart: AvailabilityDayPart): { is_available: boolean; source: string } {
  const override = schedule.overrides.find((candidate) => dateInRange(dateValue, candidate) && candidate.day_part === dayPart)
    ?? schedule.overrides.find((candidate) => dateInRange(dateValue, candidate) && (candidate.day_part ?? 'all_day') === 'all_day');
  if (override !== undefined) {
    return { is_available: override.is_available, source: 'planning' };
  }

  const date = dateFromInput(dateValue);
  if (date === null) {
    return { is_available: true, source: 'default' };
  }

  const dayOfWeek = date.getDay() === 0 ? 7 : date.getDay();
  const pattern = schedule.week_day_parts?.find((day) => day.day_of_week === dayOfWeek && day.day_part === dayPart)
    ?? schedule.week_pattern.find((day) => day.day_of_week === dayOfWeek);

  return {
    is_available: pattern?.is_available ?? true,
    source: pattern?.source ?? 'default',
  };
}

function availabilityDayPartLabel(dayPart: AvailabilityDayPart): string {
  switch (dayPart) {
    case 'morning':
      return 'Ochtend';
    case 'afternoon':
      return 'Middag';
    case 'evening':
      return 'Avond';
    case 'all_day':
    default:
      return 'Hele dag';
  }
}

function dateInRange(dateValue: string, override: AvailabilityOverride): boolean {
  return dateValue >= override.starts_at && dateValue <= override.ends_at;
}

function dateFromInput(value: string): Date | null {
  const parts = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (parts === null) {
    return null;
  }

  return new Date(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3]), 12, 0, 0, 0);
}

function ownAssetStatus(status: Asset['status']): OwnAssetStatus {
  return status === 'maintenance' || status === 'unavailable' ? status : 'ready';
}

function assetTypeLabel(type: string): string {
  switch (type) {
    case 'drone':
      return 'Drone';
    case 'battery':
      return 'Batterij';
    case 'sensor':
      return 'Sensor';
    case 'vehicle':
      return 'Voertuig';
    case 'support_equipment':
      return 'Ondersteunend materieel';
    default:
      return type;
  }
}

function assetStatusLabel(status: Asset['status']): string {
  switch (status) {
    case 'ready':
      return 'Gereed';
    case 'assigned':
      return 'Toegewezen';
    case 'maintenance':
      return 'Onderhoud';
    case 'unavailable':
      return 'Niet beschikbaar';
    case 'retired':
      return 'Uit dienst';
    default:
      return status;
  }
}

function certificationStatusLabel(status: UserCertification['status']): string {
  switch (status) {
    case 'active':
      return 'Actief';
    case 'expired':
      return 'Verlopen';
    case 'revoked':
      return 'Ingetrokken';
    case 'pending':
      return 'In behandeling';
    default:
      return status;
  }
}
