<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Welkom bij D.I.S</title>
</head>
<body style="margin:0;background:#f4f7fb;color:#172033;font-family:Arial,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7fb;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="620" cellspacing="0" cellpadding="0" style="width:620px;max-width:94%;background:#ffffff;border:1px solid #dbe5f0;border-radius:10px;overflow:hidden;">
                    <tr>
                        <td style="background:#0f172a;color:#ffffff;padding:22px 26px;">
                            <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#7dd3fc;">Nationaal Droneteam</div>
                            <h1 style="margin:6px 0 0;font-size:22px;line-height:1.3;">Welkom bij D.I.S</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 26px;">
                            <p style="margin:0 0 14px;">Beste {{ $user->name }},</p>
                            <p style="margin:0 0 18px;">Er is een D.I.S account voor je aangemaakt. Rond je registratie af via de knop hieronder. Je stelt zelf je wachtwoord in en doorloopt direct de MFA-setup wanneer dat voor je rol verplicht is.</p>
                            <p style="margin:24px 0;">
                                <a href="{{ $registrationUrl }}" style="display:inline-block;background:#0ea5e9;color:#061018;text-decoration:none;font-weight:bold;border-radius:6px;padding:11px 16px;">Registratie afronden</a>
                            </p>
                            <p style="margin:0 0 10px;color:#475569;">Daarna toont de wizard hoe je de Android app installeert en koppelt aan deze D.I.S omgeving.</p>
                            @if ($adminAppAllowed)
                                <p style="margin:0;color:#475569;">Omdat je adminrechten hebt, toont de wizard ook de installatie-informatie voor de admin app.</p>
                            @endif
                            <p style="margin:22px 0 0;color:#64748b;font-size:13px;">Deze link is tijdelijk geldig. Vraag een beheerder om een nieuwe uitnodiging als de link verlopen is.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
