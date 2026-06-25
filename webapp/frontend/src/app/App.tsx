import { Navigate, Route, Routes } from 'react-router-dom';
import { CommandLayout } from './CommandLayout';
import { ProtectedRoute } from '../routes/ProtectedRoute';
import { LoginPage } from '../features/auth/LoginPage';
import { AndroidDownloadPage } from '../features/public/AndroidDownloadPage';
import { SetupWizardPage } from '../features/setup/SetupWizardPage';
import { DashboardPage } from '../features/dashboard/DashboardPage';
import { IncidentsPage } from '../features/incidents/IncidentsPage';
import { IncidentDetailPage } from '../features/incidents/IncidentDetailPage';
import { UsersPage } from '../features/users/UsersPage';
import { TeamsPage } from '../features/teams/TeamsPage';
import { AssetsPage } from '../features/assets/AssetsPage';
import { CertificationsPage } from '../features/certifications/CertificationsPage';
import { UpdatesPage } from '../features/updates/UpdatesPage';
import { PushPage } from '../features/push/PushPage';
import { AdminPage } from '../features/admin/AdminPage';
import { StatusPage } from '../features/status/StatusPage';
import { SystemPage } from '../features/system/SystemPage';
import { ProfilePage } from '../features/profile/ProfilePage';

export function App() {
  return (
    <Routes>
      <Route path="/setup" element={<SetupWizardPage />} />
      <Route path="/download" element={<AndroidDownloadPage />} />
      <Route path="/login" element={<LoginPage />} />
      <Route
        path="/"
        element={
          <ProtectedRoute>
            <CommandLayout />
          </ProtectedRoute>
        }
      >
        <Route index element={<DashboardPage />} />
        <Route path="incidents" element={<IncidentsPage />} />
        <Route path="incidents/:incidentId" element={<IncidentDetailPage />} />
        <Route path="status" element={<StatusPage />} />
        <Route path="users" element={<UsersPage />} />
        <Route path="teams" element={<TeamsPage />} />
        <Route path="assets" element={<AssetsPage />} />
        <Route path="certifications" element={<CertificationsPage />} />
        <Route path="updates" element={<UpdatesPage />} />
        <Route path="push" element={<PushPage />} />
        <Route path="admin" element={<AdminPage />} />
        <Route path="system" element={<SystemPage />} />
        <Route path="profile" element={<ProfilePage />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
