<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 30px; }
        body { margin: 0; color: #172033; font-family: "DejaVu Sans", sans-serif; font-size: 11px; line-height: 1.45; }
        h1, h2, h3, p { margin: 0; }
        .header { padding: 18px 20px; border-radius: 10px; background: #0f172a; color: #f8fafc; }
        .header__eyebrow { color: #7dd3fc; font-size: 10px; text-transform: uppercase; letter-spacing: .08em; }
        .header h1 { margin-top: 5px; font-size: 24px; }
        .header__meta { margin-top: 10px; color: #cbd5e1; }
        .section { margin-top: 18px; }
        .section h2 { margin-bottom: 8px; color: #0f172a; font-size: 15px; }
        .grid { width: 100%; border-collapse: separate; border-spacing: 8px; margin-left: -8px; margin-right: -8px; }
        .metric { padding: 10px 12px; border: 1px solid #d8e1ec; border-radius: 8px; background: #f8fafc; }
        .metric span { display: block; color: #64748b; font-size: 9px; text-transform: uppercase; }
        .metric strong { display: block; margin-top: 4px; font-size: 16px; }
        .details { width: 100%; border-collapse: collapse; }
        .details th { width: 24%; color: #64748b; font-weight: normal; text-align: left; vertical-align: top; padding: 5px 8px 5px 0; }
        .details td { padding: 5px 0; vertical-align: top; }
        .table { width: 100%; border-collapse: collapse; }
        .table th { padding: 7px 6px; border-bottom: 1px solid #cbd5e1; color: #475569; font-size: 9px; text-align: left; text-transform: uppercase; }
        .table td { padding: 7px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .table tr:nth-child(even) td { background: #f8fafc; }
        .timeline { border-left: 2px solid #bae6fd; margin-left: 7px; padding-left: 12px; }
        .timeline__item { margin-bottom: 11px; page-break-inside: avoid; }
        .timeline__time { color: #64748b; font-size: 9px; }
        .timeline__label { margin-top: 2px; font-weight: bold; }
        .timeline__type { color: #0369a1; font-size: 9px; text-transform: uppercase; }
        .muted { color: #64748b; }
        .map-layout { width: 100%; }
        .map-card { position: relative; width: 100%; height: 260px; overflow: hidden; border: 1px solid #cbd5e1; border-radius: 9px; background: #e8f3f8; }
        .map-snapshot { display: block; width: 100%; height: 260px; }
        .map-placeholder { padding: 98px 18px; color: #475569; text-align: center; }
        .map-marker { position: absolute; width: 15px; height: 15px; margin: -10px 0 0 -10px; border: 3px solid #ffffff; border-radius: 50%; background: #dc2626; box-shadow: 0 1px 4px rgba(15, 23, 42, .35); }
        .map-marker-ring { position: absolute; width: 31px; height: 31px; margin: -18px 0 0 -18px; border: 2px solid #dc2626; border-radius: 50%; }
        .map-detail-box { margin-top: 8px; padding: 11px 12px; border: 1px solid #d8e1ec; border-radius: 8px; background: #f8fafc; }
        .map-detail-box strong { display: block; margin-bottom: 5px; color: #0f172a; font-size: 13px; }
        .map-detail-box span { display: block; margin-top: 5px; color: #475569; }
        .map-url { margin-top: 8px; color: #0369a1; font-size: 9px; word-break: break-all; }
        .aeret-link-card { padding: 14px 16px; border: 1px solid #bfdbfe; border-radius: 9px; background: #eff6ff; }
        .aeret-link-card strong { display: block; margin-bottom: 6px; color: #0f172a; font-size: 13px; }
        .aeret-link-card span { display: block; margin-top: 4px; color: #475569; }
        .aeret-snapshot { display: block; width: 100%; height: 320px; margin-bottom: 8px; border: 1px solid #cbd5e1; border-radius: 9px; object-fit: cover; }
        .snapshot-note { margin: 0 0 8px 0; color: #64748b; font-size: 9px; }
        .flight-grid { width: 100%; border-collapse: separate; border-spacing: 8px; margin-left: -8px; margin-right: -8px; }
        .flight-card { padding: 10px 12px; border: 1px solid #d8e1ec; border-radius: 8px; background: #f8fafc; vertical-align: top; }
        .flight-card h3 { margin-bottom: 6px; color: #0f172a; font-size: 12px; }
        .flight-card dl { margin: 0; }
        .flight-card dt { color: #64748b; font-size: 9px; text-transform: uppercase; }
        .flight-card dd { margin: 0 0 5px 0; }
        .flight-list { margin: 6px 0 0 14px; padding: 0; }
        .flight-list li { margin-bottom: 4px; }
        .footer { position: fixed; bottom: -10px; left: 0; right: 0; color: #94a3b8; font-size: 9px; text-align: center; }
    </style>
</head>
<body>
@php
    $tz = $timezone;
    $map = $map ?? ['available' => false];
    $flight = $droneFlightContext ?? null;
    $weather = is_array($flight) && is_array($flight['weather'] ?? null) ? $flight['weather'] : null;
    $airspace = is_array($flight) && is_array($flight['airspace'] ?? null) ? $flight['airspace'] : null;
    $flightMap = is_array($flight) && is_array($flight['map'] ?? null) ? $flight['map'] : null;
    $aeretUrl = $flightMap['aeret_url'] ?? $map['aeret_url'] ?? null;
    $flightChecklist = is_array($flight) && is_array($flight['checklist'] ?? null) ? $flight['checklist'] : [];
    $formatDate = fn ($value) => $value ? $value->timezone($tz)->format('d-m-Y H:i') : '-';
    $formatMinutes = fn ($value) => $value === null ? '-' : $value.' min';
    $formatFlightValue = fn ($value, string $suffix = '') => $value === null || $value === '' ? '-' : $value.$suffix;
    $formatFlightItem = fn ($item) => is_scalar($item) ? (string) $item : json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $responseLabel = fn ($value) => match ($value) {
        'accepted' => 'Komt',
        'declined' => 'Komt niet',
        'no_response' => 'Geen reactie',
        default => 'Wacht op reactie',
    };
@endphp
<div class="footer">D.I.S incidentrapport - gegenereerd {{ $formatDate($generatedAt) }}</div>

<header class="header">
    <div class="header__eyebrow">Nationaal Droneteam - Incidentrapport</div>
    <h1>{{ $incident->reference }}</h1>
    <div class="header__meta">{{ $incident->title }} - {{ ucfirst($incident->status) }}</div>
</header>

<section class="section">
    <table class="grid">
        <tr>
            <td class="metric"><span>Ontvangers</span><strong>{{ $summary['recipients'] }}</strong></td>
            <td class="metric"><span>Komt</span><strong>{{ $summary['accepted'] }}</strong></td>
            <td class="metric"><span>Komt niet</span><strong>{{ $summary['declined'] }}</strong></td>
            <td class="metric"><span>Geen reactie</span><strong>{{ $summary['no_response'] }}</strong></td>
        </tr>
        <tr>
            <td class="metric"><span>Onderweg</span><strong>{{ $summary['en_route'] }}</strong></td>
            <td class="metric"><span>Op locatie</span><strong>{{ $summary['on_scene'] }}</strong></td>
            <td class="metric"><span>Geopend</span><strong>{{ $formatDate($incident->opened_at) }}</strong></td>
            <td class="metric"><span>Gesloten</span><strong>{{ $formatDate($incident->closed_at) }}</strong></td>
        </tr>
    </table>
</section>

<section class="section">
    <h2>Incidentgegevens</h2>
    <table class="details">
        <tr><th>Omschrijving</th><td>{{ $incident->description ?: '-' }}</td></tr>
        <tr><th>Melder</th><td>{{ $incident->reporter_name ?: '-' }}{{ $incident->reporter_phone ? ' - '.$incident->reporter_phone : '' }}</td></tr>
        <tr><th>Aanvragende organisatie</th><td>{{ $incident->requesting_organization ?: '-' }}{{ $incident->requesting_unit ? ' - '.$incident->requesting_unit : '' }}</td></tr>
        <tr><th>Contact ter plaatse</th><td>{{ $incident->on_scene_contact_name ?: '-' }}{{ $incident->on_scene_contact_role ? ' - '.$incident->on_scene_contact_role : '' }}{{ $incident->on_scene_contact_phone ? ' - '.$incident->on_scene_contact_phone : '' }}</td></tr>
        <tr><th>Prioriteit</th><td>{{ ucfirst($incident->priority) }}</td></tr>
        <tr><th>Opkomstlocatie</th><td>{{ $incident->location_label ?: '-' }}</td></tr>
        <tr><th>Team</th><td>{{ $incident->team ? $incident->team->code.' - '.$incident->team->name : '-' }}</td></tr>
        <tr><th>Coordinator</th><td>{{ $incident->coordinator?->name ?? $incident->coordinator_name ?? '-' }}</td></tr>
        <tr><th>Aangemaakt door</th><td>{{ $incident->creator?->name ?? $incident->created_by_name ?? '-' }}</td></tr>
        <tr><th>Operationeel doel</th><td>{{ $incident->operational_objective ?: '-' }}</td></tr>
        <tr><th>Benodigde middelen</th><td>{{ $incident->required_resources ?: '-' }}</td></tr>
        <tr><th>Vereiste certificering / rol</th><td>{{ $incident->required_qualification ?: '-' }}</td></tr>
    </table>
</section>

<section class="section">
    <h2>Incidentkaart satelliet</h2>
    @if ($map['available'])
        <div class="map-layout">
            <div class="map-card">
                @if (! empty($map['snapshot_data_uri']))
                    <img class="map-snapshot" src="{{ $map['snapshot_data_uri'] }}" alt="Incidentkaart satelliet snapshot">
                @else
                    <div class="map-placeholder">Satelliet snapshot kon niet worden opgehaald. Gebruik de OSM-link onder de kaart.</div>
                    <div class="map-marker-ring" style="left: {{ $map['marker_x'] }}%; top: {{ $map['marker_y'] }}%;"></div>
                    <div class="map-marker" style="left: {{ $map['marker_x'] }}%; top: {{ $map['marker_y'] }}%;"></div>
                @endif
            </div>
            <div class="map-detail-box">
                <strong>{{ $incident->location_label ?: 'GPS locatie' }}</strong>
                <span>Latitude: {{ $map['latitude_label'] }}</span>
                <span>Longitude: {{ $map['longitude_label'] }}</span>
                @if (! empty($map['openstreetmap_url']))
                    <div class="map-url">Incidentkaart OSM: {{ $map['openstreetmap_url'] }}</div>
                @endif
            </div>
        </div>
    @else
        <p class="muted">Geen GPS locatie beschikbaar voor dit incident.</p>
    @endif
</section>

<section class="section">
    <h2>Drone vluchtinformatie</h2>
    @if (is_array($flight))
        <table class="flight-grid">
            <tr>
                <td class="flight-card">
                    <h3>Weer op locatie</h3>
                    @if ($weather)
                        <dl>
                            <dt>Status</dt><dd>{{ $weather['status'] ?? '-' }}</dd>
                            <dt>Samenvatting</dt><dd>{{ $weather['summary'] ?? '-' }}</dd>
                            <dt>Temperatuur</dt><dd>{{ $formatFlightValue($weather['temperature_c'] ?? null, ' C') }}</dd>
                            <dt>Gevoelstemperatuur</dt><dd>{{ $formatFlightValue($weather['feels_like_c'] ?? null, ' C') }}</dd>
                            <dt>Wind</dt><dd>{{ $formatFlightValue($weather['wind_speed_kmh'] ?? null, ' km/u') }}</dd>
                            <dt>Windstoten</dt><dd>{{ $formatFlightValue($weather['wind_gust_kmh'] ?? null, ' km/u') }}</dd>
                            <dt>Zicht</dt><dd>{{ isset($weather['visibility_m']) ? round(((float) $weather['visibility_m']) / 1000, 1).' km' : '-' }}</dd>
                            <dt>Neerslag</dt><dd>{{ $formatFlightValue($weather['precipitation_mm'] ?? null, ' mm') }}</dd>
                            <dt>Bewolking</dt><dd>{{ $formatFlightValue($weather['cloud_cover_percent'] ?? null, '%') }}</dd>
                            <dt>Bron</dt><dd>{{ $weather['provider'] ?? '-' }}</dd>
                        </dl>
                    @else
                        <p class="muted">Geen weerdata opgeslagen.</p>
                    @endif
                </td>
                <td class="flight-card">
                    <h3>Luchtruim, no-fly en NOTAM</h3>
                    @if ($airspace)
                        <dl>
                            <dt>Status</dt><dd>{{ $airspace['status'] ?? '-' }}</dd>
                            <dt>Samenvatting</dt><dd>{{ $airspace['summary'] ?? '-' }}</dd>
                            <dt>Bron</dt><dd>{{ $airspace['provider'] ?? '-' }}</dd>
                        </dl>
                        <strong>No-fly zones</strong>
                        <ul class="flight-list">
                            @forelse (($airspace['no_fly_zones'] ?? []) as $item)
                                <li>{{ $formatFlightItem($item) }}</li>
                            @empty
                                <li>Geen no-fly zones ontvangen van provider.</li>
                            @endforelse
                        </ul>
                        <strong>NOTAM</strong>
                        <ul class="flight-list">
                            @forelse (($airspace['notams'] ?? []) as $item)
                                <li>{{ $formatFlightItem($item) }}</li>
                            @empty
                                <li>Geen NOTAM regels ontvangen van provider.</li>
                            @endforelse
                        </ul>
                    @else
                        <p class="muted">Geen luchtruimdata opgeslagen.</p>
                    @endif
                </td>
            </tr>
            <tr>
                <td class="flight-card" colspan="2">
                    <h3>Aeret kaart</h3>
                    @if ($flightMap || $aeretUrl)
                        @if (! empty($map['aeret_snapshot_data_uri']))
                            <p class="snapshot-note">Snapshot vastgelegd bij rapportgeneratie. De actuele Aeret-kaart kan later wijzigen.</p>
                            <img class="aeret-snapshot" src="{{ $map['aeret_snapshot_data_uri'] }}" alt="Aeret kaart snapshot">
                        @endif
                        <div class="aeret-link-card">
                            <strong>Open de actuele Aeret Drone PreFlight kaart</strong>
                            <span>De interactieve Aeret-kaart wordt extern opgebouwd met actuele kaartlagen. Dit rapport bevat een snapshot en de directe link naar dezelfde incidentlocatie.</span>
                            <span>Latitude: {{ $map['latitude_label'] ?? '-' }} | Longitude: {{ $map['longitude_label'] ?? '-' }}</span>
                        </div>
                        <dl>
                            <dt>Bron</dt><dd>{{ $flightMap['provider'] ?? 'Aeret Drone PreFlight' }}</dd>
                            <dt>Status</dt><dd>{{ $flightMap['status'] ?? '-' }}</dd>
                        </dl>
                        <p class="map-url">Aeret kaart: {{ $aeretUrl ?? '-' }}</p>
                    @else
                        <p class="muted">Geen Aeret kaart opgeslagen.</p>
                    @endif
                </td>
            </tr>
            <tr>
                <td class="flight-card" colspan="2">
                    <h3>Vliegcheck</h3>
                    <ul class="flight-list">
                        @foreach ($flightChecklist as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </td>
            </tr>
        </table>
    @else
        <p class="muted">Geen drone vluchtinformatie opgeslagen voor dit incident.</p>
    @endif
</section>

<section class="section">
    <h2>Opkomst en aanrijtijden</h2>
    <table class="table">
        <thead>
        <tr>
            <th>Gebruiker</th>
            <th>Reactie</th>
            <th>Gepusht</th>
            <th>Reactietijd</th>
            <th>Onderweg</th>
            <th>Op locatie</th>
            <th>Aanrijtijd</th>
            <th>Totaal</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($travelRows as $row)
            <tr>
                <td>{{ $row['user_name'] ?? 'Verwijderde gebruiker' }}<br><span class="muted">{{ $row['user_email'] ?? '-' }}</span></td>
                <td>{{ $responseLabel($row['response_status']) }}</td>
                <td>{{ $formatDate($row['notified_at']) }}</td>
                <td>{{ $formatMinutes($row['response_minutes']) }}</td>
                <td>{{ $formatDate($row['en_route_at']) }}</td>
                <td>{{ $formatDate($row['on_scene_at']) }}</td>
                <td>{{ $formatMinutes($row['drive_minutes']) }}</td>
                <td>{{ $formatMinutes($row['total_minutes']) }}</td>
            </tr>
        @empty
            <tr><td colspan="8">Geen alarmeringsontvangers geregistreerd.</td></tr>
        @endforelse
        </tbody>
    </table>
</section>

<section class="section">
    <h2>Volledige log</h2>
    <div class="timeline">
        @forelse ($timeline as $item)
            <div class="timeline__item">
                <div class="timeline__time">{{ $formatDate($item['created_at']) }}</div>
                <div class="timeline__type">{{ $item['type'] }}</div>
                <div class="timeline__label">{{ $item['label'] }}</div>
                @if (! empty($item['message']))
                    <div>{{ $item['message'] }}</div>
                @endif
            </div>
        @empty
            <p>Geen logregels geregistreerd.</p>
        @endforelse
    </div>
</section>
</body>
</html>
