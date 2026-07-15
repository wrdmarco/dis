<?php

namespace App\Services;

use App\Models\SystemSetting;
use RuntimeException;

final class BackupRuntimeConfigService
{
    private const MAX_VALUE_LENGTH = 4096;

    private const CONTROL_CHARACTERS_PATTERN = '/[\x00-\x1F\x7F]/u';

    public function __construct(private readonly ?string $pathOverride = null) {}

    public function write(string $target): void
    {
        if (! in_array($target, ['local', 'samba'], true)) {
            throw new RuntimeException('Backup runtime configuration target is invalid.');
        }

        $dataPath = rtrim((string) env('DIS_DATA_PATH', '/opt/dis-data'), '/');
        $config = [
            'BACKUP_TARGET' => $target,
            'BACKUP_ROOT' => $dataPath.'/backup',
            'BACKUP_RETENTION_COUNT' => (string) max(0, SystemSetting::integer('backup.retention_count', 7)),
            'BACKUP_ENCRYPTION_KEY_FILE' => $dataPath.'/secrets/backup-encryption.key',
        ];

        if ($target === 'samba') {
            $config += [
                'BACKUP_SAMBA_SHARE' => SystemSetting::string('backup.samba.share', '') ?? '',
                'BACKUP_SAMBA_MOUNT' => SystemSetting::string('backup.samba.mount', '/mnt/dis-backup') ?? '/mnt/dis-backup',
                'BACKUP_SAMBA_USERNAME' => SystemSetting::string('backup.samba.username', '') ?? '',
                'BACKUP_SAMBA_PASSWORD' => SystemSetting::string('backup.samba.password', '') ?? '',
                'BACKUP_SAMBA_DOMAIN' => SystemSetting::string('backup.samba.domain', '') ?? '',
                'BACKUP_SAMBA_VERSION' => SystemSetting::string('backup.samba.version', '3.1.1') ?? '3.1.1',
            ];
        }

        $this->assertValidValues($config);
        $payload = json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
        $this->publish($payload);
    }

    /**
     * @param  array<string, string>  $config
     */
    private function assertValidValues(array $config): void
    {
        foreach ($config as $value) {
            if (! mb_check_encoding($value, 'UTF-8')
                || mb_strlen($value, 'UTF-8') > self::MAX_VALUE_LENGTH
                || preg_match(self::CONTROL_CHARACTERS_PATTERN, $value) !== 0) {
                throw new RuntimeException('Backup runtime configuration contains an invalid value.');
            }
        }
    }

    private function publish(string $payload): void
    {
        $path = $this->pathOverride ?? storage_path('app/backup-config.json');
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Backup runtime configuration directory could not be created.');
        }

        $temporary = tempnam($directory, '.backup-config-');
        if ($temporary === false) {
            throw new RuntimeException('Backup runtime configuration could not be created.');
        }

        $published = false;
        try {
            $handle = @fopen($temporary, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Backup runtime configuration could not be opened.');
            }

            try {
                $offset = 0;
                $length = strlen($payload);
                while ($offset < $length) {
                    $written = fwrite($handle, substr($payload, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Backup runtime configuration could not be written completely.');
                    }
                    $offset += $written;
                }

                if (! fflush($handle) || ! fsync($handle)) {
                    throw new RuntimeException('Backup runtime configuration could not be durably stored.');
                }
            } finally {
                fclose($handle);
            }

            if (! chmod($temporary, 0640)) {
                throw new RuntimeException('Backup runtime configuration permissions could not be restricted.');
            }
            if (! @rename($temporary, $path)) {
                throw new RuntimeException('Backup runtime configuration could not be published.');
            }
            $published = true;
        } finally {
            if (! $published && (is_file($temporary) || is_link($temporary))) {
                @unlink($temporary);
            }
        }
    }
}
