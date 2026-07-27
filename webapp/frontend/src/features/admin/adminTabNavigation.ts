export function adminTabChangeAllowed(options: {
  currentTab: string;
  nextTab: string;
  deploymentRequestWorkflowDirty: boolean;
  confirmLeave: () => boolean;
}): boolean {
  if (
    options.currentTab !== 'deploymentRequest'
    || options.nextTab === options.currentTab
    || !options.deploymentRequestWorkflowDirty
  ) {
    return true;
  }

  return options.confirmLeave();
}
