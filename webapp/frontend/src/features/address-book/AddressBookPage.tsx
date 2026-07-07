import { FormEvent, useMemo, useState } from 'react';
import { Search } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { useApiResource } from '../../lib/useApiResource';
import type { AddressBookEntry } from '../../types/api';

export function AddressBookPage() {
  const [searchInput, setSearchInput] = useState('');
  const [searchQuery, setSearchQuery] = useState('');
  const entries = useApiResource<AddressBookEntry[]>(`/address-book${searchQuery ? `?q=${encodeURIComponent(searchQuery)}` : ''}`);
  const resultCount = entries.data?.length ?? 0;
  const emptyText = useMemo(() => searchQuery ? 'Geen gebruikers gevonden voor deze zoekterm.' : 'Geen gebruikers beschikbaar.', [searchQuery]);

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSearchQuery(searchInput.trim());
  }

  function clearSearch() {
    setSearchInput('');
    setSearchQuery('');
  }

  return (
    <div className="page-stack">
      <Panel title="Adresboek">
        <form className="form-grid" onSubmit={submitSearch}>
          <label className="form-grid__wide">
            Zoeken
            <span className="search-field">
              <Search aria-hidden size={16} />
              <input
                value={searchInput}
                onChange={(event) => setSearchInput(event.target.value)}
                placeholder="Zoek op naam, telefoonnummer of woonplaats"
              />
            </span>
          </label>
          <div className="actions-row form-grid__wide">
            <button className="primary-button" type="submit">
              <Search size={16} /> Zoeken
            </button>
            <button className="secondary-button" type="button" onClick={clearSearch} disabled={searchInput === '' && searchQuery === ''}>
              Wissen
            </button>
            <span className="muted-text">{resultCount} resultaten uit gebruikerslijst</span>
          </div>
        </form>
      </Panel>

      <Panel title="Contacten">
        <ResourceState loading={entries.loading} error={entries.error} empty={false}>
          {resultCount > 0 ? (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Naam</th>
                  <th>Telefoonnummer</th>
                  <th>Woonplaats</th>
                </tr>
              </thead>
              <tbody>
                {entries.data?.map((entry) => (
                  <tr key={entry.id}>
                    <td><strong>{entry.name}</strong></td>
                    <td>{entry.phone_number ? <a href={`tel:${entry.phone_number}`}>{entry.phone_number}</a> : '-'}</td>
                    <td>{entry.city || '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <p className="muted-text">{emptyText}</p>
          )}
        </ResourceState>
      </Panel>
    </div>
  );
}
