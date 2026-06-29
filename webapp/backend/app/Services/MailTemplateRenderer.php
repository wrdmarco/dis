<?php

namespace App\Services;

final class MailTemplateRenderer
{
    /**
     * @param array<string, string|int|null> $tokens
     */
    public function render(string $template, array $tokens): string
    {
        $replacements = [];
        foreach ($tokens as $key => $value) {
            $replacements['{{'.$key.'}}'] = (string) ($value ?? '');
        }

        return strtr($template, $replacements);
    }
}
