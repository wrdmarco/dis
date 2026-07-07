export interface LocationSuggestion {
  id: string;
  label: string;
}

export interface LocationSearchResult {
  locationLabel: string;
  latitude: string;
  longitude: string;
}

export async function fetchLocationSuggestions(query: string, signal: AbortSignal): Promise<LocationSuggestion[]> {
  const [pdok, osm] = await Promise.allSettled([
    fetchPdokLocationSuggestions(query, signal),
    fetchPhotonLocationSuggestions(query, signal),
  ]);
  const pdokSuggestions = pdok.status === 'fulfilled' ? pdok.value : [];
  const photonSuggestions = osm.status === 'fulfilled' ? osm.value : [];
  const suggestions = looksLikeAddressQuery(query)
    ? [...pdokSuggestions, ...photonSuggestions]
    : [...photonSuggestions, ...pdokSuggestions];

  return uniqueLocationSuggestions(suggestions).slice(0, 8);
}

export async function lookupLocationSuggestion(suggestion: LocationSuggestion): Promise<LocationSearchResult | null> {
  if (suggestion.id.startsWith('photon:')) {
    return coordinatesFromPhotonSuggestion(suggestion);
  }

  return lookupPdokLocation(suggestion.id.replace(/^pdok:/, ''));
}

export async function geocodeAddressLabel(query: string): Promise<LocationSearchResult | null> {
  const trimmed = query.trim();
  if (trimmed.length < 6) {
    return null;
  }

  return looksLikeAddressQuery(trimmed)
    ? await geocodePdokAddress(trimmed) ?? await geocodePhotonLocation(trimmed)
    : await geocodePhotonLocation(trimmed) ?? await geocodePdokAddress(trimmed);
}

async function fetchPdokLocationSuggestions(query: string, signal: AbortSignal): Promise<LocationSuggestion[]> {
  const params = new URLSearchParams({ q: query, rows: '8' });
  const response = await fetch(`https://api.pdok.nl/bzk/locatieserver/search/v3_1/suggest?${params.toString()}`, {
    headers: { Accept: 'application/json' },
    signal,
  });
  if (!response.ok) {
    return [];
  }

  const payload = await response.json() as { response?: { docs?: Array<{ id?: string; weergavenaam?: string }> } };

  return payload.response?.docs
    ?.filter((item): item is { id: string; weergavenaam: string } => typeof item.id === 'string' && typeof item.weergavenaam === 'string')
    .map((item) => ({ id: `pdok:${item.id}`, label: item.weergavenaam }))
    ?? [];
}

async function fetchPhotonLocationSuggestions(query: string, signal: AbortSignal): Promise<LocationSuggestion[]> {
  const params = new URLSearchParams({
    q: query,
    limit: '6',
    lat: '52.1326',
    lon: '5.2913',
  });
  const response = await fetch(`https://photon.komoot.io/api/?${params.toString()}`, {
    headers: { Accept: 'application/json' },
    signal,
  });
  if (!response.ok) {
    return [];
  }

  const payload = await response.json() as {
    features?: Array<{
      geometry?: { coordinates?: [number, number] };
      properties?: {
        osm_id?: number;
        name?: string;
        street?: string;
        housenumber?: string;
        postcode?: string;
        city?: string;
        state?: string;
        country?: string;
      };
    }>;
  };

  return payload.features
    ?.map((feature, index) => {
      const coordinates = feature.geometry?.coordinates;
      const longitude = coordinates?.[0];
      const latitude = coordinates?.[1];
      const label = photonLabel(feature.properties);
      const displayLabel = photonDisplayLabel(query, label);
      if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || label === '') {
        return null;
      }

      return {
        id: `photon:${feature.properties?.osm_id ?? index}:${latitude}:${longitude}:${encodeURIComponent(displayLabel)}`,
        label: displayLabel,
      };
    })
    .filter((item): item is LocationSuggestion => item !== null)
    ?? [];
}

function uniqueLocationSuggestions(suggestions: LocationSuggestion[]): LocationSuggestion[] {
  const seen = new Set<string>();
  return suggestions.filter((suggestion) => {
    const key = suggestion.label.toLocaleLowerCase('nl-NL');
    if (seen.has(key)) {
      return false;
    }
    seen.add(key);
    return true;
  });
}

async function lookupPdokLocation(id: string): Promise<LocationSearchResult | null> {
  try {
    const params = new URLSearchParams({ id });
    const response = await fetch(`https://api.pdok.nl/bzk/locatieserver/search/v3_1/lookup?${params.toString()}`, { headers: { Accept: 'application/json' } });
    if (!response.ok) {
      return null;
    }

    const payload = await response.json() as { response?: { docs?: Array<{ centroide_ll?: string; weergavenaam?: string }> } };
    const match = payload.response?.docs?.[0];
    return coordinatesFromPdokMatch(match);
  } catch {
    return null;
  }
}

function looksLikeAddressQuery(query: string): boolean {
  return /\d/.test(query);
}

async function geocodePdokAddress(query: string): Promise<LocationSearchResult | null> {
  const params = new URLSearchParams({ q: query, rows: '1' });
  const response = await fetch(`https://api.pdok.nl/bzk/locatieserver/search/v3_1/free?${params.toString()}`, { headers: { Accept: 'application/json' } });
  if (!response.ok) {
    return null;
  }

  const payload = await response.json() as { response?: { docs?: Array<{ centroide_ll?: string; weergavenaam?: string }> } };
  return coordinatesFromPdokMatch(payload.response?.docs?.[0]);
}

async function geocodePhotonLocation(query: string): Promise<LocationSearchResult | null> {
  const params = new URLSearchParams({
    q: query,
    limit: '1',
    lat: '52.1326',
    lon: '5.2913',
  });
  const response = await fetch(`https://photon.komoot.io/api/?${params.toString()}`, { headers: { Accept: 'application/json' } });
  if (!response.ok) {
    return null;
  }

  const payload = await response.json() as {
    features?: Array<{
      geometry?: { coordinates?: [number, number] };
      properties?: Parameters<typeof photonLabel>[0];
    }>;
  };
  const match = payload.features?.[0];
  const longitude = match?.geometry?.coordinates?.[0];
  const latitude = match?.geometry?.coordinates?.[1];
  const label = photonLabel(match?.properties);
  if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || label === '') {
    return null;
  }

  return coordinatesFromLatLon(label, String(latitude), String(longitude));
}

function coordinatesFromPhotonSuggestion(suggestion: LocationSuggestion): LocationSearchResult | null {
  const [, , latitude, longitude, encodedLabel] = suggestion.id.split(':');
  if (!latitude || !longitude || !encodedLabel) {
    return null;
  }

  return coordinatesFromLatLon(decodeURIComponent(encodedLabel), latitude, longitude);
}

function photonLabel(properties?: {
  name?: string;
  street?: string;
  housenumber?: string;
  postcode?: string;
  city?: string;
  state?: string;
  country?: string;
}): string {
  if (!properties) {
    return '';
  }

  const street = [properties.street, properties.housenumber].filter(Boolean).join(' ');
  return [
    properties.name,
    street,
    properties.postcode,
    properties.city,
    properties.state,
    properties.country,
  ].filter((part): part is string => typeof part === 'string' && part.trim() !== '').join(', ');
}

function photonDisplayLabel(query: string, label: string): string {
  const normalizedQuery = query.trim();
  if (normalizedQuery === '') {
    return label;
  }

  const queryWords = normalizedQuery.toLocaleLowerCase('nl-NL').split(/\s+/).filter((word) => word.length > 2);
  const normalizedLabel = label.toLocaleLowerCase('nl-NL');
  const missingImportantWord = queryWords.some((word) => !normalizedLabel.includes(word));
  return missingImportantWord ? `${normalizedQuery} - ${label}` : label;
}

function coordinatesFromPdokMatch(match?: { centroide_ll?: string; weergavenaam?: string }): LocationSearchResult | null {
  const point = match?.centroide_ll?.match(/^POINT\(([-0-9.]+) ([-0-9.]+)\)$/);
  if (!point) {
    return null;
  }

  const [, longitude, latitude] = point;

  return {
    latitude: formatCoordinate(latitude),
    longitude: formatCoordinate(longitude),
    locationLabel: match?.weergavenaam ?? '',
  };
}

function coordinatesFromLatLon(label: string, latitude: string, longitude: string): LocationSearchResult | null {
  const formattedLatitude = formatCoordinate(latitude);
  const formattedLongitude = formatCoordinate(longitude);
  if (formattedLatitude === '' || formattedLongitude === '') {
    return null;
  }

  return {
    latitude: formattedLatitude,
    longitude: formattedLongitude,
    locationLabel: label,
  };
}

function formatCoordinate(value: string): string {
  const coordinate = Number(value);
  if (!Number.isFinite(coordinate)) {
    return '';
  }

  return coordinate.toFixed(7);
}
