export interface ApiErrorBody {
  error: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
  };
}

export interface LaravelValidationErrorBody {
  message?: string;
  errors?: Record<string, string[]>;
}

export interface ApiResponse<T> {
  data: T;
  meta?: PaginationMeta;
  error?: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
  };
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface Role {
  id: string;
  name: string;
  display_name: string;
  description?: string | null;
  requires_two_factor: boolean;
  can_use_operator_app: boolean;
  can_use_admin_app: boolean;
  users_count?: number;
  permissions?: Permission[];
}

export interface Permission {
  id: string;
  name: string;
  category: string;
  display_name: string;
}

export interface Team {
  id: string;
  code: string;
  name: string;
  type: string;
  parent_team_id?: string | null;
  is_operational: boolean;
  parent?: Team | null;
  alert_teams?: Team[];
  required_certifications?: Certification[];
}

export interface User {
  id: string;
  name: string;
  email: string;
  phone_number?: string | null;
  home_city?: string | null;
  home_latitude?: string | null;
  home_longitude?: string | null;
  home_geocoded_at?: string | null;
  home_geocode_source?: string | null;
  account_status: 'active' | 'suspended' | 'blocked';
  last_login_at?: string | null;
  push_enabled: boolean;
  two_factor_enabled: boolean;
  roles?: Role[];
  teams?: Team[];
  statuses?: AvailabilityStatus[];
  certifications?: UserCertification[];
  asset_assignments?: AssetAssignment[];
  fcm_tokens?: FcmToken[];
  mail_preferences?: {
    backup_report?: {
      success?: boolean;
      failed?: boolean;
    };
  } | null;
}

export interface FcmToken {
  id: string;
  user_id: string;
  device_id: string;
  device_manufacturer?: string | null;
  device_model?: string | null;
  android_version?: string | null;
  sdk_version?: string | null;
  platform: string;
  app_version?: string | null;
  is_active: boolean;
  last_seen_at?: string | null;
  revoked_at?: string | null;
  token_preview: string;
  token_hash: string;
  user?: User | null;
}

export interface ManualPushResult {
  queued_tokens: number;
  recipient_users: number;
}

export interface PushDeliveryLog {
  id: string;
  user_id?: string | null;
  fcm_token_id?: string | null;
  dispatch_request_id?: string | null;
  message_type: string;
  status: string;
  provider_message_id?: string | null;
  error_code?: string | null;
  sent_at?: string | null;
  created_at?: string | null;
}

export interface Incident {
  id: string;
  reference: string;
  title: string;
  description?: string | null;
  reporter_name?: string | null;
  reporter_phone?: string | null;
  requesting_organization?: string | null;
  requesting_unit?: string | null;
  on_scene_contact_name?: string | null;
  on_scene_contact_phone?: string | null;
  on_scene_contact_role?: string | null;
  required_resources?: string | null;
  priority: 'low' | 'normal' | 'high' | 'critical';
  status: 'draft' | 'active' | 'dispatching' | 'in_progress' | 'resolved' | 'cancelled';
  is_test?: boolean;
  location_label?: string | null;
  latitude?: string | null;
  longitude?: string | null;
  drone_flight_context?: DroneFlightContext | null;
  coordinator?: User | null;
  team?: Team | null;
  teams?: Team[];
  opened_at?: string | null;
  closed_at?: string | null;
  active_dispatch?: {
    id: string;
    status: string;
    response_status?: DispatchRecipient['response_status'] | null;
  } | null;
}

export interface DroneFlightContext {
  generated_at?: string | null;
  location?: {
    label?: string | null;
    latitude?: number | string | null;
    longitude?: number | string | null;
  };
  map?: {
    provider?: string | null;
    status?: string | null;
    aeret_url?: string | null;
    openstreetmap_url?: string | null;
  };
  airspace?: {
    provider?: string | null;
    status?: string | null;
    summary?: string | null;
    no_fly_zones?: unknown[];
    notams?: unknown[];
    restrictions?: unknown[];
    errors?: string[];
  };
  weather?: {
    provider?: string | null;
    status?: string | null;
    measured_at?: string | null;
    temperature_c?: number | string | null;
    feels_like_c?: number | string | null;
    humidity_percent?: number | string | null;
    wind_speed_kmh?: number | string | null;
    wind_gust_kmh?: number | string | null;
    wind_direction_degrees?: number | string | null;
    precipitation_mm?: number | string | null;
    rain_mm?: number | string | null;
    cloud_cover_percent?: number | string | null;
    visibility_m?: number | string | null;
    pressure_hpa?: number | string | null;
    weather_code?: number | string | null;
    summary?: string | null;
    errors?: string[];
  };
  checklist?: string[];
}

export interface DispatchRequest {
  id: string;
  incident_id: string;
  target_team_id?: string | null;
  status: string;
  priority: string;
  message: string;
  sent_at?: string | null;
  created_at?: string | null;
  incident?: Incident;
  target_team?: Team | null;
  recipients?: DispatchRecipient[];
}

export interface DispatchRecipient {
  id: string;
  user_id: string;
  response_status: 'pending' | 'accepted' | 'declined' | 'no_response';
  response_note?: string | null;
  notified_at?: string | null;
  responded_at?: string | null;
  user?: User;
}

export interface DispatchPreview {
  team: Pick<Team, 'id' | 'code' | 'name'> | null;
  teams?: Array<Pick<Team, 'id' | 'code' | 'name'>>;
  recipients: Array<Pick<User, 'id' | 'name' | 'email' | 'home_city'> & {
    eta_minutes?: number | null;
    teams?: Array<Pick<Team, 'id' | 'code' | 'name'>>;
  }>;
  blocked_reason?: string | null;
}

export interface IncidentTimelineItem {
  id: string;
  type: 'status' | 'dispatch' | 'dispatch_response' | 'dispatch_message' | 'operator_status';
  label: string;
  message?: string | null;
  created_at?: string | null;
}

export interface IncidentLiveLocation {
  user_id: string;
  user?: Pick<User, 'id' | 'name' | 'email'> | null;
  sharing_status?: 'shared' | 'stale' | 'consented' | 'requested' | 'pending' | 'declined' | 'not_requested';
  location_is_current?: boolean;
  consent_active?: boolean;
  requested_at?: string | null;
  consented_at?: string | null;
  revoked_at?: string | null;
  declined_at?: string | null;
  refusal_reason?: string | null;
  latitude?: string | number | null;
  longitude?: string | number | null;
  accuracy_meters?: string | number | null;
  recorded_at?: string | null;
  eta_minutes?: number | null;
}

export interface AvailabilityStatus {
  id: string;
  user_id: string;
  status: string;
  is_available: boolean;
  effective_at: string;
  user?: User;
}

export interface AvailabilityScheduleDay {
  day_of_week: number;
  is_available: boolean;
  note?: string | null;
  source: 'default' | 'pattern';
}

export interface AvailabilityOverride {
  id: string;
  user_id: string;
  starts_at: string;
  ends_at: string;
  day_part?: 'all_day' | 'morning' | 'afternoon' | 'evening';
  is_available: boolean;
  note?: string | null;
}

export interface AvailabilitySchedule {
  user_id: string;
  week_pattern: AvailabilityScheduleDay[];
  overrides: AvailabilityOverride[];
  today: {
    is_available: boolean;
    source: 'default' | 'pattern' | 'week_pattern' | 'override';
    note?: string | null;
  };
}

export interface UserVacation {
  id: string;
  user_id: string;
  starts_at: string;
  ends_at: string;
  status: 'scheduled' | 'active' | 'cancelled' | 'completed';
  note?: string | null;
  user?: Pick<User, 'id' | 'name' | 'email'> | null;
}

export interface CalendarEvent {
  id: string;
  title: string;
  type: 'training' | 'open_day' | 'meeting' | 'exercise' | 'other';
  starts_at: string;
  ends_at?: string | null;
  location_label?: string | null;
  description?: string | null;
  team_id?: string | null;
  team?: Pick<Team, 'id' | 'code' | 'name' | 'type'> | null;
  created_by_name?: string | null;
  created_at?: string | null;
}

export interface StatusAuditEntry {
  id: string;
  action: 'status.updated' | 'status.system_updated';
  user?: Pick<User, 'id' | 'name' | 'email'> | null;
  actor?: Pick<User, 'id' | 'name' | 'email'> | null;
  from_status?: string | null;
  to_status?: string | null;
  is_system_applied: boolean;
  reason?: string | null;
  created_at?: string | null;
}

export interface AuditLogEntry {
  id: string;
  action: string;
  actor?: Pick<User, 'id' | 'name' | 'email'> | null;
  target_type: string;
  target_id?: string | null;
  target_user?: Pick<User, 'id' | 'name' | 'email'> | null;
  ip_address?: string | null;
  metadata?: Record<string, unknown>;
  reason?: string | null;
  created_at?: string | null;
}

export interface PublicStatusResponse {
  status: 'ok' | 'degraded' | 'failed';
  generated_at: string;
  services: Record<string, {
    status: 'ok' | 'degraded' | 'failed' | 'unknown';
    uptime_seconds?: number | null;
    driver?: string | null;
    connection?: string | null;
    store?: string | null;
  }>;
}

export interface Asset {
  id: string;
  asset_tag: string;
  name: string;
  type: string;
  drone_type_id?: string | null;
  drone_type?: DroneType | null;
  has_spotlight: boolean;
  has_speaker: boolean;
  status: 'ready' | 'assigned' | 'maintenance' | 'unavailable' | 'retired';
  serial_number?: string | null;
  maintenance_due_at?: string | null;
  notes?: string | null;
  active_assignment?: AssetAssignment | null;
}

export interface AssetAssignment {
  id: string;
  asset_id: string;
  incident_id?: string | null;
  user_id?: string | null;
  assigned_by?: string | null;
  assigned_at?: string | null;
  released_at?: string | null;
  asset?: Asset | null;
  user?: Pick<User, 'id' | 'name' | 'email'> | null;
}

export interface DroneType {
  id: string;
  manufacturer: string;
  model: string;
  has_thermal: boolean;
  has_spotlight: boolean;
  has_speaker: boolean;
  is_active: boolean;
  notes?: string | null;
}

export interface Certification {
  id: string;
  code: string;
  name: string;
  description?: string | null;
  is_required_for_dispatch: boolean;
  warning_days_before_expiry: number;
  user_certifications?: UserCertification[];
}

export interface UserCertification {
  id: string;
  user_id: string;
  certification_id: string;
  issued_at?: string | null;
  expires_at?: string | null;
  certificate_number?: string | null;
  status: 'active' | 'expired' | 'revoked' | 'pending';
  verified_by?: string | null;
  verified_at?: string | null;
  certification?: Certification | null;
  user?: Pick<User, 'id' | 'name' | 'email'> | null;
}

export interface AppVersion {
  id: string;
  platform: string;
  application_id: string;
  version_name: string;
  version_code: number;
  status: 'supported' | 'deprecated' | 'not_supported' | 'blocked';
  artifact_sha256?: string | null;
  download_url?: string | null;
}

export interface DeveloperAccessState {
  enabled: boolean;
  configured: boolean;
  scopes?: string[];
  available_scopes?: string[];
  expires_at?: string | null;
  expired?: boolean;
  allowed_ips?: string[];
  allowed_ips_count?: number;
  legacy_unscoped?: boolean;
  generated_at?: string | null;
  disabled_at?: string | null;
  api_key?: string;
}

export interface ExpiryOverview {
  days: number;
  until: string;
  assets: ExpiringAsset[];
  certifications: ExpiringCertification[];
}

export interface ExpiringAsset {
  id: string;
  name: string;
  asset_tag: string;
  type: string;
  status: Asset['status'];
  maintenance_due_at?: string | null;
  drone_type?: {
    manufacturer: string;
    model: string;
  } | null;
}

export interface ExpiringCertification {
  id: string;
  user_id: string;
  user_name?: string | null;
  user_email?: string | null;
  certification_id: string;
  certification_name?: string | null;
  certification_code?: string | null;
  status: UserCertification['status'];
  issued_at?: string | null;
  expires_at?: string | null;
  certificate_number?: string | null;
}

export interface SystemUpdateStatus {
  state: 'idle' | 'running' | 'succeeded' | 'failed';
  started_at?: string | null;
  finished_at?: string | null;
  exit_code?: number | null;
  message?: string | null;
  log?: string[];
  reboot_required?: boolean;
}

export interface SystemVersionState {
  app_version: string;
  git: {
    current_commit?: string | null;
    branch?: string | null;
    upstream?: string | null;
    latest_commit?: string | null;
    behind?: number | null;
    fetch_successful?: boolean | null;
    checkable?: boolean;
    errors?: string[];
    update_available: boolean;
  };
  system?: {
    reboot_required?: boolean;
  };
  updater: SystemUpdateStatus;
}

export interface SystemSetting {
  key: string;
  value: unknown;
  is_sensitive: boolean;
}

export interface DispatchStatisticsIncidentSummary {
  incident_id?: string;
  reference?: string;
  title?: string;
  sent_at?: string | null;
  response_status?: DispatchRecipient['response_status'];
  responded_at?: string | null;
}

export interface DispatchStatisticsUser {
  user: Pick<User, 'id' | 'name' | 'email'> | null;
  total_alerts: number;
  accepted: number;
  declined: number;
  no_response: number;
  no_response_rate: number;
  last_alert?: DispatchStatisticsIncidentSummary | null;
  last_deployment?: DispatchStatisticsIncidentSummary | null;
  recent_no_response: DispatchStatisticsIncidentSummary[];
}

export interface DispatchStatisticsIncident {
  id?: string;
  reference?: string;
  title?: string;
  sent_at?: string | null;
  total_alerts: number;
  accepted: number;
  declined: number;
  no_response: number;
  no_response_rate: number;
}

export interface DispatchStatistics {
  scope: {
    incident_limit: number;
    incident_count: number;
  };
  summary: {
    total_alerts: number;
    accepted: number;
    declined: number;
    no_response: number;
    accepted_rate: number;
    declined_rate: number;
    no_response_rate: number;
  };
  users: DispatchStatisticsUser[];
  incidents: DispatchStatisticsIncident[];
}

export interface ReportIncident {
  id: string;
  reference: string;
  title: string;
  status: Incident['status'];
  priority: Incident['priority'];
  team?: Pick<Team, 'id' | 'code' | 'name'> | null;
  coordinator?: Pick<User, 'id' | 'name' | 'email'> | null;
  opened_at?: string | null;
  closed_at?: string | null;
  latest_dispatch_sent_at?: string | null;
  recipient_count: number;
  accepted: number;
  declined: number;
  no_response: number;
}

export interface TwoFactorSetup {
  enabled: boolean;
  secret: string | null;
  provisioning_uri: string | null;
}

export interface TwoFactorEnableResult {
  token: string;
  user: User;
  recovery_codes: string[];
}
