import { useEffect, useRef } from 'react';
import { createRealtime } from '../../lib/realtime';
import { useAuth } from '../auth/AuthContext';

export function RealtimeBridge({ onOperationalEvent }: { onOperationalEvent: () => void }) {
  const { isAuthenticated } = useAuth();
  const callbackRef = useRef(onOperationalEvent);

  useEffect(() => {
    callbackRef.current = onOperationalEvent;
  }, [onOperationalEvent]);

  useEffect(() => {
    if (!isAuthenticated) {
      return;
    }

    const echo = createRealtime({ onOperationalEvent: () => callbackRef.current() });

    return () => {
      if (echo === null) {
        return;
      }

      echo.leave('private-operations');
      echo.disconnect();
    };
  }, [isAuthenticated]);

  return null;
}
