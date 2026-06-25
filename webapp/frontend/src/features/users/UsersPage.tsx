import { FormEvent, useState } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { User } from '../../types/api';

export function UsersPage() {
  const { api } = useAuth();
  const users = useApiResource<User[]>('/users');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);

  const createUser = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    try {
      await api.post('/users', { name, email, password, account_status: 'active' });
      setName('');
      setEmail('');
      setPassword('');
      await users.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Gebruiker kon niet worden aangemaakt.');
    }
  };

  return (
    <div className="page-stack">
      <Panel title="Gebruiker aanmaken">
        <form className="inline-form" onSubmit={createUser}>
          <input value={name} onChange={(event) => setName(event.target.value)} placeholder="Naam" required />
          <input type="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="E-mail" required />
          <input type="password" value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Sterk wachtwoord" required />
          <button className="primary-button" type="submit">Aanmaken</button>
        </form>
        {error && <p className="form-error">{error}</p>}
      </Panel>
      <Panel title="Gebruikers">
        <ResourceState loading={users.loading} error={users.error} empty={(users.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Naam</th><th>E-mail</th><th>Account</th><th>Push</th><th>Teams</th></tr></thead>
            <tbody>
              {users.data?.map((user) => (
                <tr key={user.id}>
                  <td>{user.name}</td>
                  <td>{user.email}</td>
                  <td><StatusPill value={user.account_status} tone={user.account_status === 'active' ? 'good' : 'bad'} /></td>
                  <td>{user.push_enabled ? 'Actief' : 'Uit'}</td>
                  <td>{user.teams?.map((team) => team.code).join(', ') || '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
    </div>
  );
}

