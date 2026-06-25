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
}

export interface User {
  id: string;
  name: string;
  email: string;
  phone_number?: string | null;
  account_status: 'active' | 'suspended' | 'blocked';
  push_enabled: boolean;
  two_factor_enabled: boolean;
  roles?: Role[];
  teams?: Team[];
}

export interface FcmToken {
  id: string;
  user_id: string;
  device_id: string;
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

export interface Incident {
  id: string;
  reference: string;
  title: string;
  description?: string | null;
  priority: 'low' | 'normal' | 'high' | 'critical';
  status: 'draft' | 'active' | 'dispatching' | 'in_progress' | 'resolved' | 'cancelled';
  location_label?: string | null;
  latitude?: string | null;
  longitude?: string | null;
  coordinator?: User | null;
  opened_at?: string | null;
  closed_at?: string | null;
}

export interface DispatchRequest {
  id: string;
  incident_id: string;
  status: string;
  priority: string;
  message: string;
  sent_at?: string | null;
  incident?: Incident;
  recipients?: DispatchRecipient[];
}

export interface DispatchRecipient {
  id: string;
  user_id: string;
  response_status: 'pending' | 'accepted' | 'declined' | 'no_response';
  user?: User;
}

export interface AvailabilityStatus {
  id: string;
  user_id: string;
  status: string;
  is_available: boolean;
  effective_at: string;
  user?: User;
}

export interface Asset {
  id: string;
  asset_tag: string;
  name: string;
  type: string;
  status: 'ready' | 'assigned' | 'maintenance' | 'unavailable' | 'retired';
  serial_number?: string | null;
  maintenance_due_at?: string | null;
  notes?: string | null;
}

export interface Certification {
  id: string;
  code: string;
  name: string;
  description?: string | null;
  is_required_for_dispatch: boolean;
  warning_days_before_expiry: number;
}

export interface AppVersion {
  id: string;
  platform: string;
  version_name: string;
  version_code: number;
  status: 'supported' | 'deprecated' | 'blocked';
  artifact_sha256?: string | null;
  download_url?: string | null;
}

export interface SystemSetting {
  key: string;
  value: unknown;
  is_sensitive: boolean;
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
