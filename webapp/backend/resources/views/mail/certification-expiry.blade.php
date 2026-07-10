<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>{{ $mailTitle }}</title>
</head>
<body style="margin:0;background:#f4f7fb;color:#172033;font-family:Arial,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7fb;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="620" cellspacing="0" cellpadding="0" style="width:620px;max-width:94%;background:#ffffff;border:1px solid #dbe5f0;border-radius:10px;overflow:hidden;">
                    <tr>
                        <td style="background:#0f172a;color:#ffffff;padding:22px 26px;">
                            <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#7dd3fc;">{{ $tenantName }}</div>
                            <h1 style="margin:6px 0 0;font-size:22px;line-height:1.3;">{{ $mailTitle }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 26px;">
                            <div style="margin:0 0 18px;line-height:1.5;">{!! nl2br(e($body)) !!}</div>
                            <p style="margin:24px 0 0;">
                                <a href="{{ $downloadUrl }}" style="display:inline-block;background:#0ea5e9;color:#061018;text-decoration:none;font-weight:bold;border-radius:6px;padding:11px 16px;">Open D.I.S</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
