'use client';

import { CalendarPage } from '../../src/features/calendar/CalendarPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell>
      <CalendarPage />
    </ProtectedShell>
  );
}
