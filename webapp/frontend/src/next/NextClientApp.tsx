'use client';

import { BrowserRouter } from 'react-router-dom';
import { App } from '../app/App';
import { AuthProvider } from '../features/auth/AuthContext';

export function NextClientApp() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <App />
      </AuthProvider>
    </BrowserRouter>
  );
}
