'use client';

import { UavForecastPage } from '../../src/features/weather/UavForecastPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.uavForecast}>
      <UavForecastPage />
    </ProtectedShell>
  );
}
