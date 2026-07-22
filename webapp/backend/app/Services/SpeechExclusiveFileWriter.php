<?php

namespace App\Services;

final class SpeechExclusiveFileWriter
{
    public function write(string $path, string $bytes, int $mode = 0600): void
    {
        $handle = @fopen($path, 'xb');
        if (! is_resource($handle)) {
            throw new \RuntimeException('Speech staging path already exists or cannot be created.');
        }
        try {
            if (! @chmod($path, $mode)) {
                throw new \RuntimeException('Speech file permissions could not be restricted.');
            }
            $this->writeAll($handle, $bytes);
            $this->sync($handle);
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($path);
            throw $exception;
        }
        fclose($handle);
    }

    public function copy(string $source, string $destination, int $mode = 0640): void
    {
        $sourceMetadata = @lstat($source);
        if (! is_array($sourceMetadata) || is_link($source) || ! is_file($source)
            || (((int) ($sourceMetadata['mode'] ?? 0)) & 0170000) !== 0100000) {
            throw new \RuntimeException('Speech cache source is not a regular file.');
        }
        $input = @fopen($source, 'rb');
        if (! is_resource($input)) {
            throw new \RuntimeException('Speech cache source cannot be read.');
        }
        $openedMetadata = fstat($input);
        if (! is_array($openedMetadata)
            || (((int) ($openedMetadata['mode'] ?? 0)) & 0170000) !== 0100000
            || (isset($sourceMetadata['dev'], $sourceMetadata['ino'], $openedMetadata['dev'], $openedMetadata['ino'])
                && ((int) $sourceMetadata['dev'] !== (int) $openedMetadata['dev']
                    || (int) $sourceMetadata['ino'] !== (int) $openedMetadata['ino']))) {
            fclose($input);
            throw new \RuntimeException('Speech cache source changed while it was opened.');
        }
        $output = @fopen($destination, 'xb');
        if (! is_resource($output)) {
            fclose($input);
            throw new \RuntimeException('Speech cache destination already exists or cannot be created.');
        }
        try {
            if (! @chmod($destination, $mode)) {
                throw new \RuntimeException('Speech cache permissions could not be restricted.');
            }
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if (! is_string($chunk)) {
                    throw new \RuntimeException('Speech cache source could not be read completely.');
                }
                if ($chunk === '' && ! feof($input)) {
                    throw new \RuntimeException('Speech cache source stopped before end-of-file.');
                }
                $this->writeAll($output, $chunk);
            }
            $this->sync($output);
        } catch (\Throwable $exception) {
            fclose($input);
            fclose($output);
            @unlink($destination);
            throw $exception;
        }
        fclose($input);
        fclose($output);
    }

    /** @param resource $handle */
    private function writeAll($handle, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($handle, $bytes);
            if (! is_int($written) || $written < 1) {
                throw new \RuntimeException('Speech file could not be written completely.');
            }
            $bytes = substr($bytes, $written);
        }
    }

    /** @param resource $handle */
    private function sync($handle): void
    {
        if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
            throw new \RuntimeException('Speech file could not be synchronized.');
        }
    }
}
