'use client';

import { MapPin, Search } from 'lucide-react';
import {
  useEffect,
  useId,
  useRef,
  useState,
  type ChangeEvent,
  type KeyboardEvent,
} from 'react';
import {
  fetchLocationSuggestions,
  type LocationSuggestion,
} from '../lib/locationSearch';

const MINIMUM_QUERY_LENGTH = 3;
const SEARCH_DELAY_MS = 250;
const MAXIMUM_LABEL_LENGTH = 255;

interface AddressAutocompleteProps {
  id: string;
  value: string;
  disabled?: boolean;
  required?: boolean;
  invalid?: boolean;
  describedBy?: string;
  placeholder?: string;
  onChange: (value: string) => void;
}

export function AddressAutocomplete(props: AddressAutocompleteProps) {
  const {
    id,
    value,
    disabled = false,
    required = false,
    invalid = false,
    describedBy,
    placeholder = 'Zoek op adres, gebouw of locatie',
    onChange,
  } = props;
  const generatedId = useId();
  const listboxId = `${id}-${generatedId.replace(/:/g, '')}-suggestions`;
  const [suggestions, setSuggestions] = useState<LocationSuggestion[]>([]);
  const [activeIndex, setActiveIndex] = useState(-1);
  const [focused, setFocused] = useState(false);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [searchComplete, setSearchComplete] = useState(false);
  const selectedLabelRef = useRef<string | null>(null);
  const query = value.trim();

  useEffect(() => {
    setActiveIndex(-1);

    if (disabled || query.length < MINIMUM_QUERY_LENGTH) {
      setSuggestions([]);
      setLoading(false);
      setSearchComplete(false);
      setOpen(false);
      return undefined;
    }

    if (selectedLabelRef.current === query) {
      selectedLabelRef.current = null;
      setSuggestions([]);
      setLoading(false);
      setSearchComplete(false);
      setOpen(false);
      return undefined;
    }

    const controller = new AbortController();
    let cancelled = false;
    setLoading(true);
    setSearchComplete(false);
    setOpen(true);

    const timeoutId = window.setTimeout(() => {
      void fetchLocationSuggestions(query, controller.signal)
        .then((results) => {
          if (cancelled) return;
          setSuggestions(results);
          setSearchComplete(true);
          setLoading(false);
        })
        .catch(() => {
          if (cancelled) return;
          setSuggestions([]);
          setSearchComplete(true);
          setLoading(false);
        });
    }, SEARCH_DELAY_MS);

    return () => {
      cancelled = true;
      window.clearTimeout(timeoutId);
      controller.abort();
    };
  }, [disabled, query]);

  const popupVisible = focused
    && open
    && query.length >= MINIMUM_QUERY_LENGTH
    && (loading || searchComplete || suggestions.length > 0);
  const activeSuggestion = activeIndex >= 0 ? suggestions[activeIndex] : undefined;
  const liveMessage = query.length < MINIMUM_QUERY_LENGTH
    ? ''
    : loading
      ? 'Locaties zoeken.'
      : searchComplete && suggestions.length === 0
        ? 'Geen locaties gevonden. Je kunt de locatie handmatig invullen.'
        : searchComplete
          ? `${suggestions.length} locaties gevonden. Gebruik de pijltoetsen om een suggestie te kiezen, of vul de locatie handmatig in.`
          : '';

  function updateValue(event: ChangeEvent<HTMLInputElement>) {
    selectedLabelRef.current = null;
    setOpen(event.target.value.trim().length >= MINIMUM_QUERY_LENGTH);
    onChange(event.target.value.slice(0, MAXIMUM_LABEL_LENGTH));
  }

  function selectSuggestion(suggestion: LocationSuggestion) {
    const label = suggestion.label.slice(0, MAXIMUM_LABEL_LENGTH);
    selectedLabelRef.current = label.trim();
    setSuggestions([]);
    setActiveIndex(-1);
    setLoading(false);
    setSearchComplete(false);
    setOpen(false);
    onChange(label);
  }

  function handleKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'Escape' && popupVisible) {
      event.preventDefault();
      setActiveIndex(-1);
      setOpen(false);
      return;
    }

    if (event.key === 'Enter' && popupVisible && activeSuggestion !== undefined) {
      event.preventDefault();
      selectSuggestion(activeSuggestion);
      return;
    }

    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
      return;
    }

    event.preventDefault();
    setOpen(true);
    if (suggestions.length === 0) {
      setActiveIndex(-1);
      return;
    }

    setActiveIndex((current) => {
      if (event.key === 'ArrowDown') {
        return current >= suggestions.length - 1 ? 0 : current + 1;
      }

      return current <= 0 ? suggestions.length - 1 : current - 1;
    });
  }

  return (
    <div className="address-autocomplete">
      <div className="address-autocomplete__control">
        <Search aria-hidden="true" size={16} />
        <input
          id={id}
          type="search"
          role="combobox"
          value={value}
          maxLength={MAXIMUM_LABEL_LENGTH}
          placeholder={placeholder}
          autoComplete="off"
          disabled={disabled}
          required={required}
          aria-invalid={invalid || undefined}
          aria-autocomplete="list"
          aria-controls={listboxId}
          aria-expanded={popupVisible}
          aria-activedescendant={activeSuggestion === undefined ? undefined : `${listboxId}-${activeIndex}`}
          aria-describedby={describedBy}
          onBlur={() => {
            setFocused(false);
            setActiveIndex(-1);
          }}
          onChange={updateValue}
          onFocus={() => {
            setFocused(true);
            if (query.length >= MINIMUM_QUERY_LENGTH) setOpen(true);
          }}
          onKeyDown={handleKeyDown}
        />
      </div>

      {popupVisible ? (
        <div className="address-autocomplete__popup">
          <ul id={listboxId} className="address-autocomplete__results" role="listbox" aria-busy={loading}>
            {suggestions.map((suggestion, index) => (
              <li role="none" key={suggestion.id}>
                <button
                  id={`${listboxId}-${index}`}
                  className={index === activeIndex ? 'address-autocomplete__option address-autocomplete__option--active' : 'address-autocomplete__option'}
                  type="button"
                  role="option"
                  tabIndex={-1}
                  aria-selected={index === activeIndex}
                  onMouseDown={(event) => event.preventDefault()}
                  onClick={() => selectSuggestion(suggestion)}
                >
                  <MapPin aria-hidden="true" size={15} />
                  <span>{suggestion.label}</span>
                </button>
              </li>
            ))}
          </ul>
          <p className="address-autocomplete__hint">
            {loading
              ? 'Locaties zoeken…'
              : suggestions.length === 0
                ? 'Geen passende suggestie? Gebruik de handmatig ingevulde locatie.'
                : 'Kies een suggestie of gebruik de handmatig ingevulde locatie.'}
          </p>
        </div>
      ) : null}

      <span className="sr-only" role="status" aria-live="polite" aria-atomic="true">
        {liveMessage}
      </span>
    </div>
  );
}
