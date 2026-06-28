import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { CommandLayout } from './CommandLayout';
import { ProtectedRoute } from '../routes/ProtectedRoute';

const LoginPage = lazy(() => import('../features/auth/LoginPage').then((module) => ({ default: module.LoginPage })));
const AndroidDownloadPage = lazy(() => import('../features/public/AndroidDownloadPage').then((module) => ({ default: module.AndroidDownloadPage })));
const RegisterWizardPage = lazy(() => import('../features/registration/RegisterWizardPage').then((module) => ({ default: module.RegisterWizardPage })));
const SetupWizardPage = lazy(() => import('../features/setup/SetupWizardPage').then((module) => ({ default: module.SetupWizardPage })));
const DashboardPage = lazy(() => import('../features/dashboard/DashboardPage').then((module) => ({ default: module.DashboardPage })));
const IncidentsPage = lazy(() => import('../features/incidents/IncidentsPage').then((module) => ({ default: module.IncidentsPage })));
const IncidentDetailPage = lazy(() => import('../features/incidents/IncidentDetailPage').then((module) => ({ default: module.IncidentDetailPage })));
const UsersPage = lazy(() => import('../features/users/UsersPage').then((module) => ({ default: module.UsersPage })));
const TeamsPage = lazy(() => import('../features/teams/TeamsPage').then((module) => ({ default: module.TeamsPage })));
const AssetsPage = lazy(() => import('../features/assets/AssetsPage').then((module) => ({ default: module.AssetsPage })));
const CertificationsPage = lazy(() => import('../features/certifications/CertificationsPage').then((module) => ({ default: module.CertificationsPage })));
const UpdatesPage = lazy(() => import('../features/updates/UpdatesPage').then((module) => ({ default: module.UpdatesPage })));
const PushPage = lazy(() => import('../features/push/PushPage').then((module) => ({ default: module.PushPage })));
const ReportsPage = lazy(() => import('../features/reports/ReportsPage').then((module) => ({ default: module.ReportsPage })));
const TestAlertPage = lazy(() => import('../features/test-alerts/TestAlertPage').then((module) => ({ default: module.TestAlertPage })));
const AdminPage = lazy(() => import('../features/admin/AdminPage').then((module) => ({ default: module.AdminPage })));
const StatusPage = lazy(() => import('../features/status/StatusPage').then((module) => ({ default: module.StatusPage })));
const SystemPage = lazy(() => import('../features/system/SystemPage').then((module) => ({ default: module.SystemPage })));
const ProfilePage = lazy(() => import('../features/profile/ProfilePage').then((module) => ({ default: module.ProfilePage })));

export function App() {
  return (
    <Suspense fallback={<div className="resource-state">Laden...</div>}>
      <Routes>
        <Route path="/setup" element={<SetupWizardPage />} />
        <Route path="/download" element={<AndroidDownloadPage />} />
        <Route path="/register" element={<RegisterWizardPage />} />
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
          <Route path="incidents" element={<IncidentsPage mode="active" />} />
          <Route path="incidents/archive" element={<IncidentsPage mode="archive" />} />
          <Route path="incidents/:incidentId" element={<IncidentDetailPage />} />
          <Route path="status" element={<StatusPage />} />
          <Route path="users" element={<UsersPage />} />
          <Route path="teams" element={<TeamsPage />} />
          <Route path="assets" element={<AssetsPage />} />
          <Route path="certifications" element={<CertificationsPage />} />
          <Route path="updates" element={<UpdatesPage />} />
          <Route path="push" element={<PushPage />} />
          <Route path="reports" element={<ReportsPage />} />
          <Route path="proefalarmering" element={<TestAlertPage />} />
          <Route path="admin" element={<AdminPage />} />
          <Route path="system" element={<SystemPage />} />
          <Route path="systeem" element={<SystemPage />} />
          <Route path="profile" element={<ProfilePage />} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Suspense>
  );
}
