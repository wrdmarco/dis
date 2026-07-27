import { useEffect, useRef } from 'react';
import { createRealtime } from '../../lib/realtime';
import { useAuth } from '../auth/AuthContext';
import { authorizationFingerprint } from '../auth/authorizationFingerprint';

interface RealtimeBridgeProps {
  deploymentId?: string;
  onOperationalEvent?: () => void;
  onDeploymentRequestEvent?: () => void;
}

export function RealtimeBridge({
  deploymentId,
  onOperationalEvent,
  onDeploymentRequestEvent,
}: RealtimeBridgeProps) {
  const { isAuthenticated, user } = useAuth();
  const currentAuthorizationFingerprint = authorizationFingerprint(user);
  const operationalCallbackRef = useRef(onOperationalEvent);
  const deploymentRequestCallbackRef = useRef(onDeploymentRequestEvent);

  useEffect(() => {
    operationalCallbackRef.current = onOperationalEvent;
  }, [onOperationalEvent]);

  useEffect(() => {
    deploymentRequestCallbackRef.current = onDeploymentRequestEvent;
  }, [onDeploymentRequestEvent]);

  useEffect(() => {
    if (!isAuthenticated) {
      return;
    }

    const echo = createRealtime({
      onOperationalEvent: operationalCallbackRef.current === undefined
        ? undefined
        : () => operationalCallbackRef.current?.(),
      deploymentId,
      onDeploymentRequestEvent: deploymentRequestCallbackRef.current === undefined
        ? undefined
        : () => deploymentRequestCallbackRef.current?.(),
    });

    return () => {
      if (echo === null) {
        return;
      }

      echo.leave('private-operations');
      echo.leave('private-deployment-requests');
      if (deploymentId !== undefined) {
        echo.leave(`private-deployments.${deploymentId}`);
      }
      echo.disconnect();
    };
  }, [currentAuthorizationFingerprint, deploymentId, isAuthenticated]);

  return null;
}
