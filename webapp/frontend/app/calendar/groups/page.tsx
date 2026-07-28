'use client';

import { CalendarGroupsPage } from '../../../src/features/calendar/CalendarGroupsPage';
import { webRouteAccess } from '../../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.calendarGroups}>
      <CalendarGroupsPage />
    </ProtectedShell>
  );
}
