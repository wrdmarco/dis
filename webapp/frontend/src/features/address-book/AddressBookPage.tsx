import { useEffect, useMemo, useState } from 'react';
import { Search } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { locationLabel } from '../../lib/profileLocation';
import { useApiResource } from '../../lib/useApiResource';
import type { AddressBookEntry } from '../../types/api';

export function AddressBookPage() {
  const [searchInput, setSearchInput] = useState('');
  const [searchQuery, setSearchQuery] = useState('');
  const entries = useApiResource<AddressBookEntry[]>(`/address-book${searchQuery ? `?q=${encodeURIComponent(searchQuery)}` : ''}`);
  const resultCount = entries.data?.length ?? 0;
  const emptyText = useMemo(() => searchQuery ? 'Geen gebruikers gevonden voor deze zoekterm.' : 'Geen gebruikers beschikbaar.', [searchQuery]);

  useEffect(() => {
    const timer = window.setTimeout(() => setSearchQuery(searchInput.trim()), 250);

    return () => window.clearTimeout(timer);
  }, [searchInput]);

  return (
    <div className="page-stack">
      <Panel title="Adresboek">
        <div className="form-grid">
          <label className="form-grid__wide">
            Zoeken
            <span className="search-field">
              <Search aria-hidden size={16} />
              <input
                value={searchInput}
                onChange={(event) => setSearchInput(event.target.value)}
                placeholder="Zoek op naam, telefoonnummer, woonplaats, provincie of land"
              />
            </span>
          </label>
        </div>
      </Panel>

      <Panel title="Contacten">
        <ResourceState loading={entries.loading} error={entries.error} empty={false}>
          {resultCount > 0 ? (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Naam</th>
                  <th>Telefoonnummer</th>
                  <th>Locatie</th>
                </tr>
              </thead>
              <tbody>
                {entries.data?.map((entry) => (
                  <tr key={entry.id}>
                    <td><strong>{entry.name}</strong></td>
                    <td>{entry.phone_number ? <a href={`tel:${entry.phone_number}`}>{entry.phone_number}</a> : '-'}</td>
                    <td>{locationLabel(entry.city, entry.region, entry.country)}</td>
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
