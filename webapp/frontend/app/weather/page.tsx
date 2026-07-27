'use client';

import { WeatherPage } from '../../src/features/weather/WeatherPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.weather}>
      <WeatherPage />
    </ProtectedShell>
  );
}
