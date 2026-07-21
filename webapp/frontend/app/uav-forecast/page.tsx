'use client';

import { UavForecastPage } from '../../src/features/weather/UavForecastPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell>
      <UavForecastPage />
    </ProtectedShell>
  );
}
