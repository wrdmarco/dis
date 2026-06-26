<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Certificaat verloopt</title>
</head>
<body style="margin:0;background:#f4f7fb;color:#172033;font-family:Arial,sans-serif;">
    @php
        $certification = $userCertification->certification;
        $expiresAt = $userCertification->expires_at?->format('d-m-Y') ?? '-';
        $isExpired = $daysUntilExpiry < 0;
    @endphp
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7fb;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="620" cellspacing="0" cellpadding="0" style="width:620px;max-width:94%;background:#ffffff;border:1px solid #dbe5f0;border-radius:10px;overflow:hidden;">
                    <tr>
                        <td style="background:#0f172a;color:#ffffff;padding:22px 26px;">
                            <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#7dd3fc;">Nationaal Droneteam</div>
                            <h1 style="margin:6px 0 0;font-size:22px;line-height:1.3;">{{ $isExpired ? 'Certificaat verlopen' : 'Certificaat verloopt binnenkort' }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 26px;">
                            <p style="margin:0 0 14px;">Beste {{ $userCertification->user?->name ?? 'gebruiker' }},</p>
                            <p style="margin:0 0 18px;">
                                Je certificaat <strong>{{ $certification?->name ?? $userCertification->certification_id }}</strong>
                                {{ $isExpired ? 'is verlopen' : 'verloopt binnenkort' }}.
                            </p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:18px 0;">
                                <tr>
                                    <td style="padding:9px 0;border-bottom:1px solid #e5edf6;color:#64748b;">Certificaat</td>
                                    <td style="padding:9px 0;border-bottom:1px solid #e5edf6;text-align:right;">{{ $certification?->name ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:9px 0;border-bottom:1px solid #e5edf6;color:#64748b;">Certificaatnummer</td>
                                    <td style="padding:9px 0;border-bottom:1px solid #e5edf6;text-align:right;">{{ $userCertification->certificate_number ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:9px 0;border-bottom:1px solid #e5edf6;color:#64748b;">Verloopdatum</td>
                                    <td style="padding:9px 0;border-bottom:1px solid #e5edf6;text-align:right;">{{ $expiresAt }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:9px 0;color:#64748b;">Status</td>
                                    <td style="padding:9px 0;text-align:right;">{{ $isExpired ? 'Verlopen' : $daysUntilExpiry.' dagen resterend' }}</td>
                                </tr>
                            </table>
                            <p style="margin:0 0 18px;">Werk je certificaat bij in de app zodra de verlenging rond is. Zonder geldig vereist certificaat kun je niet meegenomen worden in alarmeringen waarvoor dit certificaat verplicht is.</p>
                            <p style="margin:24px 0 0;">
                                <a href="{{ $downloadUrl }}" style="display:inline-block;background:#0ea5e9;color:#061018;text-decoration:none;font-weight:bold;border-radius:6px;padding:11px 16px;">Open app downloadpagina</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
