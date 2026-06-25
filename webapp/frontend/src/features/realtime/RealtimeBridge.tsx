import { useEffect, useRef } from 'react';
import { createRealtime } from '../../lib/realtime';
import { useAuth } from '../auth/AuthContext';

export function RealtimeBridge({ onOperationalEvent }: { onOperationalEvent: () => void }) {
  const { token } = useAuth();
  const callbackRef = useRef(onOperationalEvent);

  useEffect(() => {
    callbackRef.current = onOperationalEvent;
  }, [onOperationalEvent]);

  useEffect(() => {
    if (!token) {
      return;
    }

    const echo = createRealtime({ token, onOperationalEvent: () => callbackRef.current() });

    return () => {
      echo.leave('private-operations');
      echo.disconnect();
    };
  }, [token]);

  return null;
}
