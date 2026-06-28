import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { CommandLayout } from './CommandLayout';
import { ProtectedRoute } from '../routes/ProtectedRoute';
import { PermissionRoute } from '../routes/PermissionRoute';

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
const ExpiryPage = lazy(() => import('../features/expiry/ExpiryPage').then((module) => ({ default: module.ExpiryPage })));
const UpdatesPage = lazy(() => import('../features/updates/UpdatesPage').then((module) => ({ default: module.UpdatesPage })));
const PushPage = lazy(() => import('../features/push/PushPage').then((module) => ({ default: module.PushPage })));
const ReportsPage = lazy(() => import('../features/reports/ReportsPage').then((module) => ({ default: module.ReportsPage })));
const TestAlertPage = lazy(() => import('../features/test-alerts/TestAlertPage').then((module) => ({ default: module.TestAlertPage })));
const RolesPage = lazy(() => import('../features/roles/RolesPage').then((module) => ({ default: module.RolesPage })));
const AdminPage = lazy(() => import('../features/admin/AdminPage').then((module) => ({ default: module.AdminPage })));
const BrandingPage = lazy(() => import('../features/branding/BrandingPage').then((module) => ({ default: module.BrandingPage })));
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
          <Route index element={<PermissionRoute permissions={['incidents.view', 'dispatch.view', 'status.view', 'assets.view']}><DashboardPage /></PermissionRoute>} />
          <Route path="incidents" element={<PermissionRoute permissions={['incidents.view']}><IncidentsPage mode="active" /></PermissionRoute>} />
          <Route path="incidents/archive" element={<PermissionRoute permissions={['incidents.view']}><IncidentsPage mode="archive" /></PermissionRoute>} />
          <Route path="incidents/:incidentId" element={<PermissionRoute permissions={['incidents.view']}><IncidentDetailPage /></PermissionRoute>} />
          <Route path="status" element={<PermissionRoute permissions={['status.view']}><StatusPage /></PermissionRoute>} />
          <Route path="users" element={<PermissionRoute permissions={['users.view']}><UsersPage /></PermissionRoute>} />
          <Route path="teams" element={<PermissionRoute permissions={['teams.manage']}><TeamsPage /></PermissionRoute>} />
          <Route path="assets" element={<PermissionRoute permissions={['assets.view']}><AssetsPage /></PermissionRoute>} />
          <Route path="certifications" element={<PermissionRoute permissions={['certifications.view']}><CertificationsPage /></PermissionRoute>} />
          <Route path="verloop" element={<PermissionRoute permissions={['assets.view', 'certifications.view']} anyPermission><ExpiryPage /></PermissionRoute>} />
          <Route path="expiry" element={<PermissionRoute permissions={['assets.view', 'certifications.view']} anyPermission><ExpiryPage /></PermissionRoute>} />
          <Route path="updates" element={<PermissionRoute permissions={['updates.manage']}><UpdatesPage /></PermissionRoute>} />
          <Route path="push" element={<PermissionRoute permissions={['push.manage']}><PushPage /></PermissionRoute>} />
          <Route path="reports" element={<PermissionRoute permissions={['incidents.view', 'dispatch.view']}><ReportsPage /></PermissionRoute>} />
          <Route path="proefalarmering" element={<PermissionRoute permissions={['dispatch.view']}><TestAlertPage /></PermissionRoute>} />
          <Route path="rollen" element={<PermissionRoute permissions={['roles.manage']}><RolesPage /></PermissionRoute>} />
          <Route path="roles" element={<PermissionRoute permissions={['roles.manage']}><RolesPage /></PermissionRoute>} />
          <Route path="admin" element={<PermissionRoute permissions={['settings.manage']}><AdminPage /></PermissionRoute>} />
          <Route path="branding" element={<PermissionRoute permissions={['settings.manage']}><BrandingPage /></PermissionRoute>} />
          <Route path="system" element={<PermissionRoute permissions={['system.health']}><SystemPage /></PermissionRoute>} />
          <Route path="systeem" element={<PermissionRoute permissions={['system.health']}><SystemPage /></PermissionRoute>} />
          <Route path="profile" element={<ProfilePage />} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Suspense>
  );
}
