import { Component, lazy, Suspense } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { useAuth } from '../features/auth/AuthContext';

const LoginPage = lazy(() => import('../features/auth/LoginPage').then((module) => ({ default: module.LoginPage })));
const AndroidDownloadPage = lazy(() => import('../features/public/AndroidDownloadPage').then((module) => ({ default: module.AndroidDownloadPage })));
const RegisterWizardPage = lazy(() => import('../features/registration/RegisterWizardPage').then((module) => ({ default: module.RegisterWizardPage })));
const SetupWizardPage = lazy(() => import('../features/setup/SetupWizardPage').then((module) => ({ default: module.SetupWizardPage })));
const AuthenticatedRoutes = lazy(() => import('./AuthenticatedApp').then((module) => ({ default: module.AuthenticatedRoutes })));

export function App() {
  return (
    <AppErrorBoundary>
      <Suspense fallback={<div className="resource-state" role="status" aria-live="polite">Laden...</div>}>
        <Routes>
          <Route path="/setup" element={<SetupWizardPage />} />
          <Route path="/download" element={<AndroidDownloadPage />} />
          <Route path="/register" element={<RegisterWizardPage />} />
          <Route path="/login" element={<LoginPage />} />
          <Route path="/*" element={<AuthenticatedEntrypoint />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </Suspense>
    </AppErrorBoundary>
  );
}

function AuthenticatedEntrypoint() {
  const { isAuthenticated } = useAuth();

  return isAuthenticated ? <AuthenticatedRoutes /> : <Navigate to="/login" replace />;
}

interface AppErrorBoundaryState {
  message: string | null;
}

const chunkReloadKey = 'dis.chunk.reload.attempted';

class AppErrorBoundary extends Component<{ children: React.ReactNode }, AppErrorBoundaryState> {
  state: AppErrorBoundaryState = { message: null };

  static getDerivedStateFromError(error: unknown): AppErrorBoundaryState {
    return { message: userMessageForError(error) };
  }

  componentDidCatch(error: unknown): void {
    if (!isChunkLoadError(error) || sessionStorage.getItem(chunkReloadKey) === '1') {
      return;
    }

    sessionStorage.setItem(chunkReloadKey, '1');
    window.location.reload();
  }

  render() {
    if (this.state.message === null) {
      sessionStorage.removeItem(chunkReloadKey);
      return this.props.children;
    }

    return (
      <main className="boot-screen">
        <div className="resource-state resource-state--error" role="alert">
          <span>{this.state.message}</span>
          <button className="secondary-button" type="button" onClick={() => window.location.reload()}>
            Opnieuw laden
          </button>
        </div>
      </main>
    );
  }
}

function userMessageForError(error: unknown): string {
  return isChunkLoadError(error)
    ? 'De pagina kon na een update niet volledig worden geladen. Laad de pagina opnieuw.'
    : 'Deze pagina kon niet worden geladen.';
}

function isChunkLoadError(error: unknown): boolean {
  const text = error instanceof Error ? `${error.name} ${error.message}` : String(error);

  return /ChunkLoadError|chunk|dynamically imported module|Importing a module script failed|Failed to fetch|Failed to load module script|module script|Unable to preload CSS|preload CSS|Load failed|Import failed|error loading/i.test(text);
}
