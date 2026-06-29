import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { useAuth } from '../features/auth/AuthContext';

const LoginPage = lazy(() => import('../features/auth/LoginPage').then((module) => ({ default: module.LoginPage })));
const AndroidDownloadPage = lazy(() => import('../features/public/AndroidDownloadPage').then((module) => ({ default: module.AndroidDownloadPage })));
const PublicStatusPage = lazy(() => import('../features/public/PublicStatusPage').then((module) => ({ default: module.PublicStatusPage })));
const RegisterWizardPage = lazy(() => import('../features/registration/RegisterWizardPage').then((module) => ({ default: module.RegisterWizardPage })));
const SetupWizardPage = lazy(() => import('../features/setup/SetupWizardPage').then((module) => ({ default: module.SetupWizardPage })));
const AuthenticatedRoutes = lazy(() => import('./AuthenticatedApp').then((module) => ({ default: module.AuthenticatedRoutes })));

export function App() {
  return (
    <Suspense fallback={<div className="resource-state">Laden...</div>}>
      <Routes>
        <Route path="/setup" element={<SetupWizardPage />} />
        <Route path="/download" element={<AndroidDownloadPage />} />
        <Route path="/status" element={<PublicStatusPage />} />
        <Route path="/register" element={<RegisterWizardPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/*" element={<AuthenticatedEntrypoint />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Suspense>
  );
}

function AuthenticatedEntrypoint() {
  const { isAuthenticated } = useAuth();

  return isAuthenticated ? <AuthenticatedRoutes /> : <Navigate to="/login" replace />;
}
