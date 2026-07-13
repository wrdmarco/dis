export type ManualMobileAppAccess = 'operator' | 'admin' | 'any';

export interface ManualAccessRule {
  permissions?: readonly string[];
  anyPermission?: boolean;
  mobileApp?: ManualMobileAppAccess;
}

export interface ManualStep {
  label: string;
  description: string;
}

export interface ManualGuide extends ManualAccessRule {
  id: string;
  title: string;
  intro: string;
  prerequisites?: readonly string[];
  steps: readonly ManualStep[];
  result: string;
  warning?: string;
}

export type ManualGuideMap = Readonly<Record<string, readonly ManualGuide[]>>;
