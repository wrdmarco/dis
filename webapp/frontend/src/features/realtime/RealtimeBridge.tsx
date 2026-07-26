import { useEffect, useRef } from 'react';
import { createRealtime } from '../../lib/realtime';
import { useAuth } from '../auth/AuthContext';

interface RealtimeBridgeProps {
  onOperationalEvent?: () => void;
  onIntakeEvent?: () => void;
}

export function RealtimeBridge({ onOperationalEvent, onIntakeEvent }: RealtimeBridgeProps) {
  const { isAuthenticated } = useAuth();
  const operationalCallbackRef = useRef(onOperationalEvent);
  const intakeCallbackRef = useRef(onIntakeEvent);

  useEffect(() => {
    operationalCallbackRef.current = onOperationalEvent;
  }, [onOperationalEvent]);

  useEffect(() => {
    intakeCallbackRef.current = onIntakeEvent;
  }, [onIntakeEvent]);

  useEffect(() => {
    if (!isAuthenticated) {
      return;
    }

    const echo = createRealtime({
      onOperationalEvent: operationalCallbackRef.current === undefined
        ? undefined
        : () => operationalCallbackRef.current?.(),
      onIntakeEvent: intakeCallbackRef.current === undefined
        ? undefined
        : () => intakeCallbackRef.current?.(),
    });

    return () => {
      if (echo === null) {
        return;
      }

      echo.leave('private-operations');
      echo.leave('private-intakes');
      echo.disconnect();
    };
  }, [isAuthenticated]);

  return null;
}
