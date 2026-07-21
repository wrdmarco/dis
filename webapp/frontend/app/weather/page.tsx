'use client';

import { WeatherPage } from '../../src/features/weather/WeatherPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell>
      <WeatherPage />
    </ProtectedShell>
  );
}
