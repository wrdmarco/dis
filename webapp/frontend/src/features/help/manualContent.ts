import { accountManualGuides } from './manuals/accountManual';
import { managementManualGuides } from './manuals/managementManual';
import { operationManualGuides } from './manuals/operationManual';
import { resourceManualGuides } from './manuals/resourceManual';
import type { ManualGuideMap } from './manualTypes';

export const manualGuides: ManualGuideMap = {
  ...accountManualGuides,
  ...operationManualGuides,
  ...resourceManualGuides,
  ...managementManualGuides,
};
