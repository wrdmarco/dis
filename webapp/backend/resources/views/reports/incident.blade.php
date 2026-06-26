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
        .footer { position: fixed; bottom: -10px; left: 0; right: 0; color: #94a3b8; font-size: 9px; text-align: center; }
    </style>
</head>
<body>
@php
    $tz = $timezone;
    $formatDate = fn ($value) => $value ? $value->timezone($tz)->format('d-m-Y H:i') : '-';
    $formatMinutes = fn ($value) => $value === null ? '-' : $value.' min';
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
        <tr><th>Prioriteit</th><td>{{ ucfirst($incident->priority) }}</td></tr>
        <tr><th>Locatie</th><td>{{ $incident->location_label ?: '-' }}</td></tr>
        <tr><th>Team</th><td>{{ $incident->team ? $incident->team->code.' - '.$incident->team->name : '-' }}</td></tr>
        <tr><th>Coordinator</th><td>{{ $incident->coordinator?->name ?? '-' }}</td></tr>
        <tr><th>Aangemaakt door</th><td>{{ $incident->creator?->name ?? '-' }}</td></tr>
    </table>
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
                <td>{{ $row['user']?->name ?? 'Onbekende gebruiker' }}<br><span class="muted">{{ $row['user']?->email ?? '-' }}</span></td>
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
