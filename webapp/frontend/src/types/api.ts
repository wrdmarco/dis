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
  meta?: PaginationMeta | {
    warnings?: string[];
    [key: string]: unknown;
  };
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
  description?: string | null;
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

export interface AddressBookEntry {
  id: string;
  name: string;
  phone_number?: string | null;
  city?: string | null;
  region?: string | null;
  country?: string | null;
}

export interface User {
  id: string;
  name: string;
  first_name?: string | null;
  last_name?: string | null;
  email: string;
  phone_number?: string | null;
  home_city?: string | null;
  home_region?: string | null;
  home_country?: string | null;
  home_latitude?: string | null;
  home_longitude?: string | null;
  home_geocoded_at?: string | null;
  home_geocode_source?: string | null;
  account_status: 'active' | 'suspended' | 'blocked' | 'store_review';
  last_login_at?: string | null;
  failed_login_attempts?: number;
  login_locked_until?: string | null;
  push_enabled: boolean;
  max_operator_devices: number;
  two_factor_enabled: boolean;
  mfa_required?: boolean;
  profile_completion_required?: boolean;
  missing_profile_fields?: string[];
  roles?: Role[];
  teams?: Team[];
  statuses?: AvailabilityStatus[];
  certifications?: UserCertification[];
  asset_assignments?: AssetAssignment[];
  fcm_tokens?: FcmToken[];
  mail_preferences?: {
    ui?: {
      theme?: 'dark' | 'light';
    };
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
  device_type?: 'phone' | 'tablet' | null;
  device_name?: string | null;
  device_manufacturer?: string | null;
  device_model?: string | null;
  android_version?: string | null;
  sdk_version?: string | null;
  platform: string;
  client_type?: 'operator' | 'admin' | string;
  app_version?: string | null;
  is_active: boolean;
  is_online?: boolean;
  last_seen_at?: string | null;
  revoked_at?: string | null;
  token_preview: string;
  token_hash: string;
  personal_access_token_id?: string | null;
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
  custom_fields?: Record<string, unknown>;
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

export interface OperationalMapLayers {
  command_centers: OperationalMapCommandCenter[];
  historical_incidents: OperationalMapHistoricalIncident[];
  pilot_homes: OperationalMapPilotHome[];
}

export interface OperationalMapCommandCenter {
  id: string;
  name: string;
  address?: string | null;
  latitude: number | string;
  longitude: number | string;
}

export interface OperationalMapHistoricalIncident {
  id: string;
  reference: string;
  title: string;
  status: Incident['status'];
  priority: Incident['priority'];
  location_label?: string | null;
  latitude: number | string;
  longitude: number | string;
  closed_at?: string | null;
}

export interface OperationalMapPilotHome {
  id: string;
  name: string;
  home_city?: string | null;
  latitude: number | string;
  longitude: number | string;
  teams: string[];
}

export type WallboardLayout = 'fullscreen_map';
export type WallboardDisplayProfile = 'auto' | '1080p' | '4k';
export type WallboardTheme = 'dark' | 'light';
export type WallboardPageType = 'map' | 'incident_list' | 'summary' | 'message' | 'news' | 'video' | 'photo_carousel';
export type WallboardDisplayMode = 'rotation' | 'static' | 'manual' | 'incident_override';
export type WallboardNewsItemTransition = 'fade' | 'dissolve' | 'slide' | 'flip' | 'zoom' | 'wipe' | 'none';
export type WallboardPageTransition = WallboardNewsItemTransition;
export type WallboardFlipDirection = 'left_to_right' | 'top_to_bottom' | 'bottom_to_top' | 'random';

export interface WallboardMapConfiguration {
  show_active_incidents: boolean;
  show_test_incidents: boolean;
  show_live_locations: boolean;
  show_routes: boolean;
  show_command_centers: boolean;
  show_historical_incidents: boolean;
  show_summary: boolean;
  show_incident_list: boolean;
  show_route_legend: boolean;
  auto_fit: boolean;
}

export interface WallboardConfiguration {
  theme: WallboardTheme;
  refresh_seconds: number;
  map: WallboardMapConfiguration;
  ticker: WallboardTickerConfiguration;
  focus: WallboardFocusConfiguration;
  pages: WallboardPage[];
  rotation_enabled: boolean;
  page_transition: WallboardPageTransition;
  page_transition_duration_ms: number;
  page_flip_direction: WallboardFlipDirection;
  /** Behouden voor oudere clients; nieuwe clients gebruiken page_transition. */
  page_fade_enabled: boolean;
  incident_override: WallboardIncidentOverride;
}

export type WallboardFocusKind = 'preannouncement' | 'real_alarm' | 'test_alarm';

export interface WallboardFocusTypeConfiguration {
  enabled: boolean;
  duration_seconds: number;
  show_response_feed: boolean;
}

export interface WallboardFocusConfiguration {
  preannouncement: WallboardFocusTypeConfiguration;
  real_alarm: WallboardFocusTypeConfiguration;
  test_alarm: WallboardFocusTypeConfiguration;
}

export type WallboardRichTextMark = 'bold' | 'italic';
export type WallboardRichTextAlignment = 'left' | 'center';

export interface WallboardRichTextRun {
  text: string;
  marks?: WallboardRichTextMark[];
}

interface WallboardRichTextTextBlockBase {
  align: WallboardRichTextAlignment;
  runs: WallboardRichTextRun[];
}

export type WallboardRichTextTextBlock =
  | (WallboardRichTextTextBlockBase & { type: 'heading' })
  | (WallboardRichTextTextBlockBase & { type: 'paragraph' })
  | (WallboardRichTextTextBlockBase & { type: 'quote' });

export interface WallboardRichTextListItem {
  runs: WallboardRichTextRun[];
}

interface WallboardRichTextListBlockBase {
  items: WallboardRichTextListItem[];
}

export type WallboardRichTextListBlock =
  | (WallboardRichTextListBlockBase & { type: 'bullet_list' })
  | (WallboardRichTextListBlockBase & { type: 'numbered_list' });

export type WallboardRichTextBlock = WallboardRichTextTextBlock | WallboardRichTextListBlock;

export interface WallboardRichTextDocument {
  version: 1;
  blocks: WallboardRichTextBlock[];
}

export interface WallboardPageOptions {
  /** Alleen aanwezig bij het inlezen van oudere configuraties. Nieuwe writes gebruiken content. */
  body?: string;
  content?: WallboardRichTextDocument;
  url?: string;
  video_duration_seconds?: number;
  media_playlist_id?: string;
  show_test_incidents?: boolean;
  sources?: WallboardNewsSource[];
  custom_sources?: WallboardCustomNewsSource[];
  max_items?: number;
  item_duration_seconds?: number;
  item_transition?: WallboardNewsItemTransition;
  item_transition_duration_ms?: number;
  item_flip_direction?: WallboardFlipDirection;
}

export interface WallboardPage {
  id: string;
  type: WallboardPageType;
  name: string;
  duration_seconds: number;
  transition?: WallboardPageTransition;
  transition_duration_ms?: number;
  flip_direction?: WallboardFlipDirection;
  options: WallboardPageOptions;
}

export interface WallboardIncidentOverride {
  enabled: boolean;
  page_id: string | null;
}

export type WallboardTickerSourceType = 'rss' | 'internal';

interface WallboardTickerSourceBase {
  id: string;
  label: string;
}

export interface WallboardRssTickerSource extends WallboardTickerSourceBase {
  type: 'rss';
  url: string;
  max_items: number;
}

export interface WallboardInternalTickerSource extends WallboardTickerSourceBase {
  type: 'internal';
  text: string;
}

export type WallboardTickerSource = WallboardRssTickerSource | WallboardInternalTickerSource;

export interface WallboardTickerConfiguration {
  enabled: boolean;
  sources: WallboardTickerSource[];
}

export interface WallboardTickerItem {
  source_id: string;
  source_type: WallboardTickerSourceType;
  source_label: string;
  text: string;
}

export type WallboardNewsSource = 'ndt' | 'dronewatch';

export interface WallboardCustomNewsSource {
  id: string;
  label: string;
  url: string;
}

export type WallboardNewsItemSource = WallboardNewsSource | 'custom';

export interface WallboardNewsItem {
  id: string;
  source: WallboardNewsItemSource;
  source_id: string;
  source_label: string;
  title: string;
  excerpt: string;
  url: string;
  image_url?: string | null;
  published_at: string;
}

export interface WallboardNewsState {
  pages: Record<string, WallboardNewsPageState>;
  generated_at: string;
}

export interface WallboardNewsPageState {
  items: WallboardNewsItem[];
  fallback_used: boolean;
  lookback_days: 7;
}

export interface WallboardDisplayState {
  mode: WallboardDisplayMode;
  page_id: string;
  incident_active: boolean;
  next_change_at?: string | null;
}

export interface WallboardPlaylistReference {
  id: string;
  name: string;
  version: number;
}

export interface WallboardPlaylist extends WallboardPlaylistReference {
  configuration: WallboardConfiguration;
  linked_wallboards_count: number;
  created_by?: string | null;
  updated_by?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface WallboardPlaylistAssignment {
  wallboard_id: string;
  playlist_id: string;
  configuration: WallboardConfiguration;
  config_version: number;
  control_version: number;
}

export interface Wallboard {
  id: string;
  name: string;
  layout: WallboardLayout;
  display_profile: WallboardDisplayProfile;
  configuration: WallboardConfiguration;
  playlist_id: string;
  playlist: WallboardPlaylistReference;
  is_enabled: boolean;
  is_online?: boolean;
  config_version: number;
  control_version?: number;
  refresh_version: number;
  manual_page_id?: string | null;
  manual_page_set_at?: string | null;
  display?: WallboardDisplayState | null;
  active_sessions_count: number;
  paired_at?: string | null;
  last_seen_at?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface WallboardPairingRequest {
  code: string;
  status: 'pending' | 'approved';
  expires_at: string | null;
  poll_after_seconds: number;
}

export interface WallboardPairingStatus {
  status: 'pending' | 'paired';
  expires_at?: string | null;
  poll_after_seconds?: number;
}

export interface WallboardStateIncident {
  id: string;
  reference: string;
  title: string;
  status: Incident['status'];
  priority: Incident['priority'];
  is_test: boolean;
  location_label?: string | null;
  latitude: number | string | null;
  longitude: number | string | null;
  opened_at?: string | null;
}

export interface WallboardStateCommandCenter {
  id: string;
  name: string;
  address?: string | null;
  latitude: number | string | null;
  longitude: number | string | null;
}

export interface WallboardStateHistoricalIncident {
  id: string;
  reference: string;
  title: string;
  status: Incident['status'];
  priority: Incident['priority'];
  location_label?: string | null;
  latitude: number | string | null;
  longitude: number | string | null;
  closed_at?: string | null;
}

export interface WallboardStateLiveLocation {
  incident_id: string;
  user_id: string;
  user?: Pick<User, 'id' | 'name'> | null;
  dispatch_response_status: 'accepted';
  operational_status: 'en_route' | 'on_scene' | null;
  sharing_status: string;
  location_is_current: boolean;
  latitude: number | string | null;
  longitude: number | string | null;
  accuracy_meters?: number | string | null;
  recorded_at?: string | null;
  eta_minutes?: number | null;
  eta_source?: 'navigation' | 'fallback' | 'unknown' | null;
  route?: IncidentLiveLocation['route'];
}

export interface WallboardPilotAvailability {
  available: number;
  total: number;
}

export interface WallboardFocusPilotCounts {
  available: number;
  relevant: number;
  contacted: number;
}

export interface WallboardStateActiveAlarm {
  id: string;
  reference: string;
  title: string;
  status: Extract<Incident['status'], 'dispatching' | 'in_progress'>;
  priority: Incident['priority'];
  location_label?: string | null;
  opened_at?: string | null;
}

export interface WallboardStateRecentIncident {
  id: string;
  reference: string;
  title: string;
  status: Extract<Incident['status'], 'resolved' | 'cancelled'>;
  priority: Incident['priority'];
  is_test: boolean;
  location_label?: string | null;
  closed_at?: string | null;
}

export interface WallboardTransientAlert {
  dispatch_id: string;
  incident_id: string;
  reference: string;
  title: string;
  priority: Incident['priority'];
  location_label?: string | null;
  received_at: string;
  expires_at: string;
  is_test: boolean;
}

export interface WallboardMaintenanceNotice {
  active: true;
  kind: 'update' | 'maintenance';
  title: string;
  message: string;
  started_at: string;
  expires_at: string;
  estimated_duration_seconds?: number | null;
  estimated_completion_at?: string | null;
  remaining_seconds?: number | null;
}

export type WallboardFocusResponseStatus = 'pending' | 'accepted' | 'declined' | 'no_response';

export interface WallboardFocusResponseCounts {
  contacted?: number;
  targeted: number;
  pending: number;
  accepted: number;
  declined: number;
  no_response: number;
}

export interface WallboardFocusResponseItem {
  name: string;
  response_status: WallboardFocusResponseStatus;
  responded_at?: string | null;
  eta_minutes?: number | null;
  eta_source?: string | null;
}

export interface WallboardFocusResponses {
  counts: WallboardFocusResponseCounts;
  items: WallboardFocusResponseItem[];
  coming?: WallboardFocusResponseItem[];
}

export interface WallboardFocusState {
  kind: WallboardFocusKind;
  focus_id: string;
  dispatch_id: string;
  incident_id: string;
  reference: string;
  title: string;
  priority: Incident['priority'];
  location_label?: string | null;
  started_at: string;
  expires_at?: string | null;
  visible: boolean;
  playlist_page_id?: string | null;
  next_change_at?: string | null;
  pilot_counts?: WallboardFocusPilotCounts | null;
  responses?: WallboardFocusResponses | null;
  is_preview?: boolean;
}

export interface WallboardOperationalSummary {
  pilot_availability: WallboardPilotAvailability;
  active_alarm: WallboardStateActiveAlarm | null;
  recent_incidents: WallboardStateRecentIncident[];
  transient_alert: WallboardTransientAlert | null;
  focus?: WallboardFocusState | null;
}

export interface WallboardState {
  generated_at: string;
  maintenance?: WallboardMaintenanceNotice | null;
  wallboard: Pick<Wallboard, 'id' | 'name' | 'layout' | 'display_profile' | 'configuration' | 'config_version' | 'control_version' | 'refresh_version' | 'display' | 'updated_at'>;
  map: {
    incidents: WallboardStateIncident[];
    command_centers: WallboardStateCommandCenter[];
    historical_incidents: WallboardStateHistoricalIncident[];
    live_locations: WallboardStateLiveLocation[];
  };
  operational_summary: WallboardOperationalSummary;
  ticker: {
    items: WallboardTickerItem[];
  };
  news: WallboardNewsState;
  media: {
    photo_pages: Record<string, WallboardMediaPageState>;
  };
}

export interface WallboardMediaPageStateItem {
  id: string;
  name: string;
  image_url: string;
  width: number;
  height: number;
}

export interface WallboardMediaPageState {
  media_playlist_id: string;
  media_playlist_version: number;
  item_duration_seconds: number;
  total_duration_seconds: number;
  items: WallboardMediaPageStateItem[];
}

export interface WallboardControlState {
  generated_at?: string;
  maintenance?: WallboardMaintenanceNotice | null;
  config_version: number;
  control_version: number;
  refresh_version: number;
  display_profile: WallboardDisplayProfile;
  display: WallboardDisplayState;
  transient_alert: WallboardTransientAlert | null;
  focus?: WallboardFocusState | null;
}

export interface IncidentInternalNotes {
  internal_notes?: string | null;
  updated_at?: string | null;
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
    eta_source?: 'navigation' | 'fallback' | 'unknown' | null;
    teams?: Array<Pick<Team, 'id' | 'code' | 'name'>>;
  }>;
  blocked_reason?: string | null;
  warnings?: string[];
}

export interface IncidentTimelineItem {
  id: string;
  type: 'status' | 'dispatch' | 'dispatch_response' | 'dispatch_message' | 'operator_status' | 'internal_notes' | 'audit';
  label: string;
  message?: string | null;
  created_at?: string | null;
}

export interface IncidentLiveLocation {
  user_id: string;
  user?: { id: string; name: string; email?: string | null } | null;
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
  eta_source?: 'navigation' | 'fallback' | 'unknown' | null;
  route?: {
    source: string;
    distance_meters: number | null;
    duration_seconds: number | null;
    geometry: {
      type: 'LineString';
      coordinates: Array<[number, number]>;
    };
  } | null;
}

export interface AvailabilityStatus {
  id: string;
  user_id: string;
  status: string;
  is_available: boolean;
  is_system_applied?: boolean;
  reason?: string | null;
  effective_at: string;
  next_availability_change?: {
    at: string;
    is_available: boolean;
    source: 'default' | 'pattern' | 'week_pattern' | 'override' | string;
    note?: string | null;
  } | null;
  next_available_at?: {
    at: string;
    source: 'default' | 'pattern' | 'week_pattern' | 'override' | string;
    note?: string | null;
  } | null;
  user?: User;
}

export interface AvailabilityScheduleDay {
  day_of_week: number;
  day_part?: 'all_day' | 'morning' | 'afternoon' | 'evening';
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
  week_day_parts?: AvailabilityScheduleDay[];
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

export type MobilePairingClientType = 'operator' | 'admin' | 'operator_android' | 'operator_ios' | 'admin_android' | 'admin_ios';

export interface MobilePairingCode {
  id: string;
  server_url: string;
  api_base_url: string;
  client_type: MobilePairingClientType;
  code: string;
  expires_at: string;
  ttl_seconds: number;
  deeplink_url: string;
  qr_payload: string;
}

export interface StoreReviewAccountStatus {
  platform: 'apple' | 'google';
  client_type: 'operator_ios' | 'operator_android';
  name: string;
  username: string;
  configured: boolean;
  enabled: boolean;
  last_login_at?: string | null;
  token_is_active: boolean;
  token_last_used_at?: string | null;
  token_expires_at?: string | null;
  recent_login_events: StoreReviewLoginEvent[];
}

export interface StoreReviewLoginEvent {
  id: string;
  result: 'success' | 'blocked';
  client_type?: string | null;
  device_name?: string | null;
  ip_address?: string | null;
  created_at: string;
}

export interface StoreReviewStatus {
  accounts: StoreReviewAccountStatus[];
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
  includes_system_updates?: boolean;
  estimated_duration_seconds?: number | null;
  estimated_completion_at?: string | null;
  remaining_seconds?: number | null;
  estimate_source?: 'historical' | 'fallback' | null;
}

export interface SystemMetrics {
  generated_at: string;
  uptime_seconds: number | null;
  cpu: {
    usage_percent: number | null;
    logical_processors: number | null;
    load_average_1m: number | null;
  };
  memory: {
    total_bytes: number | null;
    used_bytes: number | null;
    available_bytes: number | null;
    usage_percent: number | null;
  };
  disk: {
    label: string;
    total_bytes: number | null;
    used_bytes: number | null;
    available_bytes: number | null;
    usage_percent: number | null;
  };
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

export type OsrmManagementState = 'not_installed' | 'installed_inactive' | 'ready' | 'degraded';

export type OsrmManagementAction = 'install_activate' | 'update';

export type OsrmOperationState = 'queued' | 'running' | 'succeeded' | 'failed';

export type OsrmOperationStage =
  | 'validating'
  | 'downloading'
  | 'merging'
  | 'installing_package'
  | 'provisioning'
  | 'extracting'
  | 'partitioning'
  | 'customizing'
  | 'activating'
  | 'verifying'
  | 'configuring'
  | 'completed';

export interface OsrmOperationSummary {
  id: string;
  action: OsrmManagementAction;
  state: OsrmOperationState;
  stage: OsrmOperationStage;
  message: string;
  started_at?: string | null;
  finished_at?: string | null;
  exit_code?: number | null;
}

export interface OsrmConfiguredSource {
  id: 'netherlands' | 'belgium';
  label: string;
  latest_url: string;
}

export interface OsrmDatasetSource {
  id: 'netherlands' | 'belgium';
  label: string;
  filename: string;
  version_url: string;
  md5: string;
  size_bytes: number;
}

export interface OsrmManagementStatus {
  state: OsrmManagementState;
  installed: boolean;
  enabled: boolean;
  healthy: boolean;
  package: {
    version: string;
    verified_at?: string | null;
  } | null;
  dataset: {
    legacy: boolean;
    source_set_sha256: string | null;
    snapshot_date: string | null;
    source_timestamp: string | null;
    sources: OsrmDatasetSource[];
    imported_at?: string | null;
  } | null;
  configuration: {
    sources: OsrmConfiguredSource[];
    source_set_sha256: string | null;
    health_coordinate: {
      longitude: number;
      latitude: number;
    } | null;
  };
  next_action: OsrmManagementAction | null;
  blocker: {
    code: string;
    message: string;
  } | null;
  active_operation: OsrmOperationSummary | null;
  latest_operation: OsrmOperationSummary | null;
}

export interface OsrmOperationLogLine {
  seq: number;
  at: string;
  level: 'debug' | 'info' | 'warning' | 'error' | string;
  message: string;
}

export interface OsrmOperationFeed {
  operation: OsrmOperationSummary;
  lines: OsrmOperationLogLine[];
  next_cursor: number;
}

export interface OsrmOperationStarted {
  operation: OsrmOperationSummary;
}

export interface OsrmOperationRequest {
  action: OsrmManagementAction;
}

export interface FormFieldOption {
  label: string;
  value: string;
}

export interface ConfigurableFormField {
  key: string;
  label: string;
  type: 'section' | 'text' | 'textarea' | 'number' | 'phone' | 'flight_time' | 'select' | 'checkbox' | 'radio';
  visible: boolean;
  required: boolean;
  max_length?: number;
  max?: number;
  option_source?: 'manual' | 'user_drones';
  options?: FormFieldOption[];
  phone_countries?: string[];
  width?: 'half' | 'full';
  section?: string | null;
  locked?: boolean;
  expose_to_push?: boolean;
  available_in_operator_app?: boolean;
  is_custom?: boolean;
}

export type PilotReportFormField = ConfigurableFormField;

export interface PilotReportFormConfig {
  fields: PilotReportFormField[];
}

export interface PilotIncidentReport {
  id: string;
  incident_id: string;
  user_id: string;
  user_name?: string | null;
  status: 'draft' | 'submitted' | string;
  summary?: string | null;
  observations?: string | null;
  actions_taken?: string | null;
  result?: string | null;
  issues?: string | null;
  equipment_used?: string | null;
  flight_minutes?: number | null;
  custom_fields?: Record<string, unknown>;
  prepared_at?: string | null;
  submitted_at?: string | null;
  finalized_at?: string | null;
  can_edit?: boolean;
  updated_at?: string | null;
}

export interface IncidentFormConfig {
  fields: ConfigurableFormField[];
  layout?: IncidentFormLayoutItem[];
}

export interface IncidentFormLayoutItem {
  key: string;
  label: string;
  visible: boolean;
  width?: 'half' | 'full';
  locked?: boolean;
  required?: boolean;
  expose_to_push?: boolean;
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
  report_generated_at?: string | null;
  report_available?: boolean;
  report_status: 'draft' | 'final';
  latest_dispatch_sent_at?: string | null;
  recipient_count: number;
  accepted: number;
  declined: number;
  no_response: number;
  expected_pilot_report_count: number;
  submitted_pilot_report_count: number;
  missing_pilot_report_count: number;
  unfinalized_pilot_report_count?: number;
  missing_pilot_reports: Array<{
    user_id: string;
    name: string;
    email?: string | null;
    responded_at?: string | null;
  }>;
  unfinalized_pilot_reports?: Array<{
    user_id: string;
    name: string;
    email?: string | null;
    submitted_at?: string | null;
  }>;
}

export interface TwoFactorSetup {
  enabled: boolean;
  secret: string | null;
  provisioning_uri: string | null;
}

export interface TwoFactorEnableResult {
  authenticated: boolean;
  user: User;
  recovery_codes: string[];
}
