<?php

namespace App\Mail\Concerns;

trait BuildsSimpleHtmlMail
{
    protected function simpleHtmlBody(string $appName, string $tenantName, string $subject, string $body, ?string $actionUrl = null, string $actionLabel = 'Openen'): string
    {
        $action = $actionUrl !== null && $actionUrl !== ''
            ? '<p style="margin-top:24px;"><a href="'.e($actionUrl).'" style="display:inline-block;background:#0284c7;color:#ffffff;text-decoration:none;border-radius:6px;padding:10px 16px;">'.e($actionLabel).'</a></p>'
            : '';

        return implode('', [
            '<!doctype html>',
            '<html lang="nl">',
            '<head><meta charset="utf-8"><title>'.e($subject).'</title></head>',
            '<body style="font-family:Arial,sans-serif;line-height:1.5;color:#111827;background:#f8fafc;padding:24px;">',
            '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;padding:24px;">',
            '<p style="margin:0 0 4px;color:#6b7280;font-size:12px;text-transform:uppercase;letter-spacing:.08em;">'.e($tenantName).'</p>',
            '<h1 style="margin:0 0 20px;font-size:22px;color:#111827;">'.e($appName).'</h1>',
            '<div style="white-space:pre-line;font-size:15px;">'.e($body).'</div>',
            $action,
            '</div>',
            '</body>',
            '</html>',
        ]);
    }
}
