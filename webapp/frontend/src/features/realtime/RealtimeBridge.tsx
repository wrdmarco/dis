import { useEffect } from 'react';
import { createRealtime } from '../../lib/realtime';
import { useAuth } from '../auth/AuthContext';

export function RealtimeBridge({ onOperationalEvent }: { onOperationalEvent: () => void }) {
  const { token } = useAuth();

  useEffect(() => {
    if (!token) {
      return;
    }

    const echo = createRealtime({ token, onOperationalEvent });

    return () => {
      echo.leave('private-operations');
      echo.disconnect();
    };
  }, [onOperationalEvent, token]);

  return null;
}

