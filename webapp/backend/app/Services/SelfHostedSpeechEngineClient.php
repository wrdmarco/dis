<?php

namespace App\Services;

use App\Contracts\SpeechEngineClient;
use App\Exceptions\SpeechEngineException;
use Illuminate\Support\Str;

final class SelfHostedSpeechEngineClient implements SpeechEngineClient
{
    /** @return array<string, mixed> */
    public function health(): array
    {
        return $this->request('health', [], 5);
    }

    /** @param array<string, mixed> $model @return array<string, mixed> */
    public function install(string $modelId, array $model): array
    {
        return $this->request('install', [
            'model_id' => $this->modelId($modelId),
            'revision' => (string) $model['revision'],
            'weights_sha256' => (string) $model['weights_sha256'],
        ], (int) config('dis.speech.install_timeout_seconds', 3600));
    }

    /** @return array<string, mixed> */
    public function cancelInstall(string $modelId): array
    {
        return $this->request('cancel_install', [
            'model_id' => $this->modelId($modelId),
        ], 15);
    }

    /** @return array<string, mixed> */
    public function status(string $modelId): array
    {
        return $this->request('status', ['model_id' => $this->modelId($modelId)], 10);
    }

    /** @return array<string, mixed> */
    public function synthesize(string $modelId, string $jobBasename, string $outputBasename): array
    {
        if (basename($jobBasename) !== $jobBasename
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}\.job\.json$/D', $jobBasename) !== 1
            || basename($outputBasename) !== $outputBasename
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}\.wav$/D', $outputBasename) !== 1) {
            throw new SpeechEngineException('invalid_staging_basename');
        }

        return $this->request('synthesize', [
            'model_id' => $this->modelId($modelId),
            'job_basename' => $jobBasename,
            'output_basename' => $outputBasename,
        ], (int) config('dis.speech.synthesis_timeout_seconds', 300));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function request(string $action, array $payload, int $timeoutSeconds): array
    {
        $socketPath = (string) config('dis.speech.socket_path', '/run/dis-tts/engine.sock');
        if (! str_starts_with($socketPath, '/') || str_contains($socketPath, "\0") || is_link($socketPath)) {
            throw new SpeechEngineException('invalid_socket_path');
        }
        $requestId = (string) Str::ulid();
        $json = json_encode([
            'protocol_version' => (int) config('dis.speech.protocol_version', 1),
            'request_id' => $requestId,
            'action' => $action,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $maximumRequest = (int) config('dis.speech.maximum_request_bytes', 65_536);
        if (strlen($json) > $maximumRequest) {
            throw new SpeechEngineException('request_too_large');
        }

        $connectTimeout = max(0.1, (float) config('dis.speech.connect_timeout_seconds', 1));
        $stream = @stream_socket_client('unix://'.$socketPath, $errorNumber, $errorMessage, $connectTimeout, STREAM_CLIENT_CONNECT);
        if (! is_resource($stream)) {
            throw new SpeechEngineException('engine_unavailable');
        }

        try {
            stream_set_blocking($stream, true);
            stream_set_timeout($stream, max(1, $timeoutSeconds));
            $this->writeAll($stream, pack('N', strlen($json)).$json);
            $lengthBytes = $this->readExact($stream, 4);
            $length = unpack('Nlength', $lengthBytes)['length'] ?? 0;
            if (! is_int($length) || $length < 2 || $length > (int) config('dis.speech.maximum_response_bytes', 262_144)) {
                throw new SpeechEngineException('invalid_response_length');
            }
            $decoded = json_decode($this->readExact($stream, $length), true, 32, JSON_THROW_ON_ERROR);
        } catch (SpeechEngineException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new SpeechEngineException('engine_protocol_error', previous: $exception);
        } finally {
            fclose($stream);
        }

        if (! is_array($decoded)
            || ($decoded['protocol_version'] ?? null) !== (int) config('dis.speech.protocol_version', 1)
            || ! is_string($decoded['request_id'] ?? null)
            || ! hash_equals($requestId, $decoded['request_id'])) {
            throw new SpeechEngineException('engine_response_mismatch');
        }
        if (($decoded['ok'] ?? false) !== true) {
            $errorCode = is_string($decoded['error_code'] ?? null) ? $decoded['error_code'] : 'engine_failed';
            throw new SpeechEngineException(preg_match('/^[a-z0-9_]{1,80}$/D', $errorCode) === 1 ? $errorCode : 'engine_failed');
        }

        return is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($stream, $bytes);
            if (! is_int($written) || $written < 1) {
                throw new SpeechEngineException('engine_write_failed');
            }
            $bytes = substr($bytes, $written);
        }
    }

    /** @param resource $stream */
    private function readExact($stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($stream, $length - strlen($bytes));
            if (! is_string($chunk) || $chunk === '') {
                $metadata = stream_get_meta_data($stream);
                throw new SpeechEngineException(($metadata['timed_out'] ?? false) ? 'engine_timeout' : 'engine_read_failed');
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    private function modelId(string $modelId): string
    {
        $models = (array) config('dis.speech.models', []);
        if (! array_key_exists($modelId, $models)) {
            throw new SpeechEngineException('model_not_allowlisted');
        }

        return $modelId;
    }
}
