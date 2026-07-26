export function adminTabChangeAllowed(options: {
  currentTab: string;
  nextTab: string;
  intakeWorkflowDirty: boolean;
  confirmLeave: () => boolean;
}): boolean {
  if (
    options.currentTab !== 'incidentIntake'
    || options.nextTab === options.currentTab
    || !options.intakeWorkflowDirty
  ) {
    return true;
  }

  return options.confirmLeave();
}
