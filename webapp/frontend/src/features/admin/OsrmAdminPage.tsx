import { useEffect, useState } from 'react';
import { createRealtime } from '../../lib/realtime';
import type { OsrmOperationSummary } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { OsrmAdminPanel } from './OsrmAdminPanel';
import { isOsrmOperationSummary } from './osrmAdminPresentation';

export function OsrmAdminPage() {
  const { isAuthenticated, hasPermission } = useAuth();
  const canManage = hasPermission('system.routing.manage');
  const canView = canManage || hasPermission('system.health.view');
  const [realtimeOperation, setRealtimeOperation] = useState<OsrmOperationSummary | null>(null);

  useEffect(() => {
    if (!isAuthenticated || !canView) {
      return undefined;
    }

    const echo = createRealtime({
      onOsrmOperationStatus: (payload) => {
        if (isOsrmOperationSummary(payload)) {
          setRealtimeOperation(payload);
        }
      },
    });

    return () => {
      echo?.leave('private-admin.system');
    };
  }, [canView, isAuthenticated]);

  return (
    <div className="page-stack osrm-page">
      <OsrmAdminPanel
        enabled={canView}
        canManage={canManage}
        realtimeOperation={realtimeOperation}
      />
    </div>
  );
}
