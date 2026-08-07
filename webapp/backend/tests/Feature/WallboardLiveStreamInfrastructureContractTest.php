<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class WallboardLiveStreamInfrastructureContractTest extends TestCase
{
    private string $repositoryRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryRoot = dirname(__DIR__, 4);
    }

    public function test_rtmps_ingress_and_hls_worker_are_separate_hardened_services(): void
    {
        $ingress = $this->read('infrastructure/systemd/dis-wallboard-live-ingress.service');
        $hls = $this->read('infrastructure/systemd/dis-wallboard-live.service');

        foreach ([
            'Description=DIS Wallboard OBS RTMPS Ingress',
            'User=dis-wallboard-live-ingress',
            'Group=dis-wallboard-live-ingress',
            'LoadCredential=mediamtx.yml:/etc/dis-wallboard-live/mediamtx.yml',
            'LoadCredential=stream-key.sha256:/etc/dis-wallboard-live/stream-key.sha256',
            'LoadCredential=server.crt:/etc/dis-wallboard-live/server.crt',
            'LoadCredential=server.key:/etc/dis-wallboard-live/server.key',
            'ExecStart=/usr/local/bin/dis-wallboard-live-ingress-runner',
            'RuntimeDirectory=dis-wallboard-live-ingress',
            'LimitCORE=0',
            'NoNewPrivileges=true',
            'ProtectSystem=strict',
            'ProtectProc=invisible',
            'CapabilityBoundingSet=',
            'RestrictAddressFamilies=AF_INET',
            'MemoryDenyWriteExecute=true',
            'ReadWritePaths=/run/dis-wallboard-live-ingress',
        ] as $contract) {
            self::assertStringContainsString($contract, $ingress);
        }
        self::assertStringNotContainsString('User=root', $ingress);
        self::assertStringNotContainsString('SupplementaryGroups=', $ingress);
        self::assertStringNotContainsString('ReadWritePaths=/opt/dis', $ingress);

        foreach ([
            'Description=DIS Wallboard Live HLS Worker',
            'User=dis-wallboard-live',
            'Group=dis-wallboard-live',
            'Wants=network-online.target dis-wallboard-live-ingress.service',
            'After=network-online.target dis-wallboard-live-ingress.service',
            'LoadCredential=input.url:/etc/dis-wallboard-live/input.url',
            'ExecStart=/usr/local/bin/dis-wallboard-live-runner',
            'RuntimeDirectory=dis-wallboard-live',
            'LimitFSIZE=6291456',
            'NoNewPrivileges=true',
            'ProtectSystem=strict',
            'MemoryDenyWriteExecute=true',
            'ReadWritePaths=/run/dis-wallboard-live',
        ] as $contract) {
            self::assertStringContainsString($contract, $hls);
        }
        self::assertStringNotContainsString('SupplementaryGroups=', $hls);
    }

    public function test_stream_key_is_hashed_for_auth_and_absent_from_ingress_configuration(): void
    {
        $configure = $this->read('scripts/wallboard-live-configure.sh');
        $auth = $this->read('scripts/wallboard-live-auth.py');
        $ingressRunner = $this->read('scripts/wallboard-live-ingress-runner.sh');
        $hlsRunner = $this->read('scripts/wallboard-live-runner.sh');

        foreach ([
            'WALLBOARD_LIVE_STREAM_STREAM_KEY',
            'WALLBOARD_LIVE_STREAM_TLS_CERTIFICATE_PATH',
            'WALLBOARD_LIVE_STREAM_TLS_PRIVATE_KEY_PATH',
            '/usr/bin/getent ahostsv4 "${public_host}"',
            '[[ "${stream_key}" =~ ^[A-Za-z0-9._~-]{32,79}$ ]]',
            '/usr/bin/openssl x509',
            '-noout -checkend 86400',
            '-noout -checkhost "${public_host}"',
            '/usr/bin/openssl pkey',
            'root_private_file "${resolved_private_key}"',
            '/usr/bin/openssl verify -purpose sslserver -verify_depth 8',
            '-CAfile /etc/ssl/certs/ca-certificates.crt',
            'stream_key_hash="$(printf',
            'authMethod: http',
            'authHTTPAddress: http://127.0.0.1:${AUTH_HTTP_PORT}/auth',
            'rtmpEncryption: optional',
            'rtmpAddress: 127.0.0.1:${LOCAL_RTMP_PORT}',
            'rtmpsAddress: ${bind_address}:${rtmps_port}',
            'overridePublisher: false',
            'maxReaders: 1',
            'rtsp: false',
            'hls: false',
            'webrtc: false',
            'srt: false',
            'moq: false',
        ] as $contract) {
            self::assertStringContainsString($contract, $configure);
        }
        self::assertStringNotContainsString('passphrase=', $configure);
        self::assertStringNotContainsString('srt://', $configure);

        foreach ([
            'LISTEN_ADDRESS = ("127.0.0.1", 19351)',
            'hashlib.sha256(candidate.encode("ascii")).hexdigest()',
            'hmac.compare_digest(digest, expected_hash)',
            'action == "publish"',
            'local_read = action == "read" and source_ip in {"127.0.0.1", "::1"}',
            'class AttemptLimiter:',
            'PUBLIC_AUTH_ATTEMPTS_PER_MINUTE = 30',
            'def audit_publish_auth',
            'def log_message',
        ] as $contract) {
            self::assertStringContainsString($contract, $auth);
        }
        self::assertStringContainsString('/usr/bin/python3 -I -S "${AUTH_PATH}" >/dev/null &', $ingressRunner);
        self::assertStringContainsString('"${MEDIAMTX_PATH}" "${configuration}" >/dev/null 2>&1 &', $ingressRunner);
        self::assertStringContainsString('wait -n "${auth_pid}" "${mediamtx_pid}"', $ingressRunner);

        foreach ([
            'credential_file="${CREDENTIALS_DIRECTORY}/input.url"',
            '-protocol_whitelist rtmp,tcp',
            '-f flv',
            '-/i "${credential_file}"',
            '-rw_timeout 15000000',
            '-loglevel quiet',
            '-bsf:v h264_mp4toannexb',
            '-c:a aac',
            '-hls_delete_threshold 3',
            '-hls_flags delete_segments+omit_endlist+temp_file',
            'readonly MAX_SEGMENT_BYTES=6291456',
            'readonly MAX_OUTPUT_BYTES=67108864',
            'readonly MAX_OPEN_SEGMENT_SECONDS=12',
            'while true; do',
            'clean_output_directory',
            'retry_delay_seconds=$((retry_delay_seconds * 2))',
        ] as $contract) {
            self::assertStringContainsString($contract, $hlsRunner);
        }
        self::assertStringNotContainsString('srt,udp', $hlsRunner);

        $refresh = $this->read('scripts/wallboard-live-refresh.sh');
        foreach ([
            'LOCK_PATH="${LOCK_DIRECTORY}/dis-exclusive-operation.lock"',
            '/usr/bin/flock -n 9',
            'DIS_OPERATION_LOCK_HELD',
            'DIS_OPERATION_LOCK_FD',
            '/proc/$$/fd/${DIS_OPERATION_LOCK_FD}',
            '.refresh-backup.XXXXXX',
            'restoring the previous credentials',
            'openssl s_client',
            'services_ready',
        ] as $contract) {
            self::assertStringContainsString($contract, $refresh);
        }
    }

    public function test_stream_key_rotation_uses_a_hardened_root_request_broker(): void
    {
        $service = $this->read('infrastructure/systemd/dis-wallboard-live-key-request.service');
        $path = $this->read('infrastructure/systemd/dis-wallboard-live-key-request.path');
        $timer = $this->read('infrastructure/systemd/dis-wallboard-live-key-request.timer');
        $worker = $this->read('scripts/wallboard-live-key-request-worker.sh');

        foreach ([
            'User=root',
            'Group=root',
            'ExecStart=/usr/local/bin/dis-wallboard-live-key-request-worker',
            'RuntimeDirectory=dis-wallboard-live-key-request',
            'RuntimeDirectoryMode=0700',
            'UMask=0077',
            'LimitCORE=0',
            'NoNewPrivileges=true',
            'ProtectSystem=strict',
            'ProtectProc=invisible',
            'MemoryDenyWriteExecute=true',
            'TimeoutStartSec=240s',
            'ReadWritePaths=@DIS_DATA_PATH@ /etc/dis-wallboard-live /run/dis-wallboard-live-key-request /run/lock',
        ] as $contract) {
            self::assertStringContainsString($contract, $service);
        }
        self::assertStringContainsString(
            'PathExistsGlob=@DIS_DATA_PATH@/wallboard-live-key-requests/*.pending',
            $path,
        );
        foreach (['OnBootSec=1min', 'OnUnitInactiveSec=1min', 'AccuracySec=1s'] as $contract) {
            self::assertStringContainsString($contract, $timer);
        }

        foreach ([
            'MAX_REQUEST_LIFETIME_SECONDS=120',
            'MAX_RESULT_BYTES=65536',
            'MINIMUM_REMAINING_SECONDS=35',
            'ACTIVATION_DEADLINE_SECONDS=55',
            'SUCCESS_RESULT_DEADLINE_SECONDS=70',
            'ROLLBACK_REFRESH_TIMEOUT_SECONDS=75',
            "'user:www-data:-wx'",
            '^www-data:600:1:',
            'keys_unsorted - ["operation", "stream_key", "expected_key_sha256", "actor_id", "created_at", "expires_at"]',
            'test("^[A-Za-z0-9_-]{64}$")',
            'test("^[a-f0-9]{64}$")',
            'test("^[0-9A-HJKMNP-TV-Z]{26}$"; "i")',
            '/usr/bin/mv -T -- "${pending}" "${running}"',
            'set_managed_env_secret "${env_file}" WALLBOARD_LIVE_STREAM_STREAM_KEY',
            'DIS_OPERATION_LOCK_HELD=1 DIS_OPERATION_LOCK_FD="${DIS_OPERATION_LOCK_FD}"',
            'wallboard_live_key_rotation_conflict',
            'failed 3',
            'rollback=restored',
            'Only pre-commit activation/publication failures reach this branch.',
            'the key remains retrievable through the portal.',
            'refresh_before_deadline "${activation_deadline}"',
            'restore_previous_key_and_runtime "${env_file}" "${previous_key}" "${expected_key_sha256}"',
            'systemctl stop dis-wallboard-live.service dis-wallboard-live-ingress.service',
            'write_result "${request_id}" "${state}" "${exit_code}" "${output}" "${result_deadline}"',
            '/usr/bin/flock -x "${result_fd}"',
            'result_process_id="${BASHPID}"',
            'descriptor_path="/proc/${result_process_id}/fd/${result_fd}"',
            'invalidate_published_result',
            'Stream-key rotation missed its result publication deadline.',
            '/usr/bin/sync -f "${REQUEST_DIR}"',
            "initial_output='Stream-key rotation commit is being finalized.'",
            'write_commit_marker "${commit_marker}" || return 1',
            'write_locked_result succeeded 0 "${output}" || return 2',
            'printf \'committed\\n\' > "${temporary}" || return 1',
            '/usr/bin/sync -f "${WORK_DIR}" || return 1',
            'if [ "${completed}" != "1" ] && ! safe_commit_marker "${commit_marker}"; then',
            'safe_commit_marker "${commit_marker}"',
            'cleanup_committed_request "${running_file}" "${previous_key_file}" "${commit_marker}"',
            'wallboard_live_key_rotation_committed',
            'result_publication_incomplete',
            'if [ "${result_status}" = "2" ] && safe_commit_marker "${commit_marker}"; then',
            'The durable marker makes rollback forbidden',
            '{state: $state, exit_code: $exit_code, output: $output, finished_at: $finished_at}',
            '/usr/bin/chown www-data:root "${temporary}"',
            'matches=$((matches + 1))',
            '[ "${matches}" -eq 1 ] && valid_stream_key "${value}" || return 1',
        ] as $contract) {
            self::assertStringContainsString($contract, $worker);
        }
        $exclusiveLock = strpos($worker, '/usr/bin/flock -x "${result_fd}"');
        $resultMove = strpos($worker, '/usr/bin/mv -T -- "${temporary}" "${result_file}"');
        $directorySync = $resultMove === false
            ? false
            : strpos($worker, '/usr/bin/sync -f "${REQUEST_DIR}"', $resultMove);
        $postPublicationDeadline = $directorySync === false
            ? false
            : strpos($worker, '&& [ "$(/usr/bin/date +%s)" -ge "${result_deadline}" ]; then', $directorySync);
        $commitMarker = $postPublicationDeadline === false
            ? false
            : strpos($worker, 'write_commit_marker "${commit_marker}" || return 1', $postPublicationDeadline);
        $successRewrite = $commitMarker === false
            ? false
            : strpos($worker, 'write_locked_result succeeded 0 "${output}" || return 2', $commitMarker);
        $finalDeadline = $successRewrite === false
            ? false
            : strpos($worker, '&& [ "$(/usr/bin/date +%s)" -ge "${result_deadline}" ]; then', $successRewrite);
        $publicationComplete = $finalDeadline === false
            ? false
            : strpos($worker, 'completed=1', $finalDeadline);
        self::assertNotFalse($exclusiveLock);
        self::assertNotFalse($resultMove);
        self::assertNotFalse($directorySync);
        self::assertNotFalse($postPublicationDeadline);
        self::assertNotFalse($commitMarker);
        self::assertNotFalse($successRewrite);
        self::assertNotFalse($finalDeadline);
        self::assertNotFalse($publicationComplete);
        self::assertLessThan($resultMove, $exclusiveLock);
        self::assertLessThan($directorySync, $resultMove);
        self::assertLessThan($postPublicationDeadline, $directorySync);
        self::assertLessThan($commitMarker, $postPublicationDeadline);
        self::assertLessThan($successRewrite, $commitMarker);
        self::assertLessThan($finalDeadline, $successRewrite);
        self::assertLessThan($publicationComplete, $finalDeadline);

        $cleanupStart = strpos($worker, 'cleanup_committed_request()');
        $cleanupRecovery = $cleanupStart === false
            ? false
            : strpos($worker, '/usr/bin/rm -f -- "${previous_key_file}" || return 1', $cleanupStart);
        $cleanupRunning = $cleanupRecovery === false
            ? false
            : strpos($worker, '/usr/bin/rm -f -- "${running_file}" || return 1', $cleanupRecovery);
        $cleanupMarker = $cleanupRunning === false
            ? false
            : strpos($worker, '/usr/bin/rm -f -- "${commit_marker}" || return 1', $cleanupRunning);
        self::assertNotFalse($cleanupStart);
        self::assertNotFalse($cleanupRecovery);
        self::assertNotFalse($cleanupRunning);
        self::assertNotFalse($cleanupMarker);
        self::assertLessThan($cleanupRunning, $cleanupRecovery);
        self::assertLessThan($cleanupMarker, $cleanupRunning);

        $processStart = strpos($worker, 'process_claimed_request()');
        $committedRecovery = $processStart === false
            ? false
            : strpos($worker, 'if [ -e "${commit_marker}" ] || [ -L "${commit_marker}" ]; then', $processStart);
        $timeoutRecovery = $committedRecovery === false
            ? false
            : strpos($worker, 'if [ "${time_invalid}" = "1" ]; then', $committedRecovery);
        self::assertNotFalse($processStart);
        self::assertNotFalse($committedRecovery);
        self::assertNotFalse($timeoutRecovery);
        self::assertLessThan($timeoutRecovery, $committedRecovery);
        self::assertStringNotContainsString(
            'restore_previous_key_and_runtime',
            substr($worker, $committedRecovery, $timeoutRecovery - $committedRecovery),
        );
        self::assertStringNotContainsString('stream_key=${stream_key}', $worker);
        self::assertStringNotContainsString('expected_key_sha256=${expected_key_sha256}', $worker);

        $common = $this->read('scripts/lib/common.sh');
        foreach ([
            'ensure_managed_directory "${DIS_DATA_PATH}/wallboard-live-key-requests" root root 1730',
            'ensure_managed_directory "${DIS_DATA_PATH}/wallboard-live-key-request-work" root root 0700',
            'setfacl -m "u:www-data:-wx" "${DIS_DATA_PATH}/wallboard-live-key-requests"',
            'install_wallboard_live_key_request_systemd_units()',
            'dis-wallboard-live-key-request.path',
            'dis-wallboard-live-key-request.timer',
        ] as $contract) {
            self::assertStringContainsString($contract, $common);
        }
    }

    public function test_mediamtx_is_version_and_checksum_pinned(): void
    {
        $common = $this->read('scripts/lib/common.sh');
        foreach ([
            'WALLBOARD_LIVE_MEDIAMTX_VERSION="1.20.0"',
            '952d5f7d31d1b448ab4da4509550594c511d42636db9d7bb175d377f4ede81df',
            '6aa3c03da7b6477f1e110c8e18e819cf9ef121e8981b52b8f8219982dae35f2f',
            '25947caac403f37ec881c9be213af2cad67e344a6c7098905b0d31c17f40e336',
            '2da379972ba86627632aa7e3f779c680ba04a5ee26ef2a20dc61cefcc24f73b8',
            "--proto '=https'",
            'sha256sum -- "${archive}"',
            'sha256sum -- "${temporary}/mediamtx"',
        ] as $contract) {
            self::assertStringContainsString($contract, $common);
        }
    }

    public function test_both_services_participate_in_every_runtime_lifecycle(): void
    {
        $paths = [
            'scripts/deploy.sh',
            'scripts/restore.sh',
            'scripts/self-heal-permissions.sh',
            'scripts/retire-android-apks.sh',
            'scripts/retire-weather-snapshots.sh',
            'scripts/uninstall.sh',
            'scripts/lib/common.sh',
        ];
        foreach ($paths as $path) {
            $source = $this->read($path);
            self::assertStringContainsString('dis-wallboard-live', $source, $path);
            self::assertStringContainsString('dis-wallboard-live-ingress', $source, $path);
        }

        $deploy = $this->read('scripts/deploy.sh');
        self::assertStringContainsString(
            'infrastructure/systemd/dis-wallboard-live-ingress.service" /etc/systemd/system/dis-wallboard-live-ingress.service',
            $deploy,
        );
        self::assertStringContainsString('install_wallboard_live_runtime_bundle "${APP_ROOT}"', $deploy);

        $uninstall = $this->read('scripts/uninstall.sh');
        foreach ([
            '/usr/local/bin/dis-wallboard-live-ingress-runner',
            '/usr/local/bin/dis-mediamtx',
            '/usr/local/libexec/dis-wallboard-live-auth',
            '/usr/local/sbin/dis-wallboard-live-refresh',
            'userdel "${WALLBOARD_LIVE_INGRESS_USER}"',
            'groupdel "${WALLBOARD_LIVE_INGRESS_GROUP}"',
        ] as $contract) {
            self::assertStringContainsString($contract, $uninstall);
        }
    }

    public function test_configuration_and_docs_use_a_youtube_style_rtmps_server_plus_stream_key(): void
    {
        $configuration = $this->read('webapp/backend/config/wallboard_live_stream.php');
        foreach ([
            "env('WALLBOARD_LIVE_STREAM_ENABLED', false)",
            "env('WALLBOARD_LIVE_STREAM_PUBLIC_HOST', '')",
            "env('WALLBOARD_LIVE_STREAM_RTMPS_BIND_ADDRESS', '0.0.0.0')",
            "env('WALLBOARD_LIVE_STREAM_RTMPS_PORT', 1936)",
            "env('WALLBOARD_LIVE_STREAM_STREAM_KEY', '')",
            "env('WALLBOARD_LIVE_STREAM_TLS_CERTIFICATE_PATH', '')",
            "env('WALLBOARD_LIVE_STREAM_TLS_PRIVATE_KEY_PATH', '')",
        ] as $contract) {
            self::assertStringContainsString($contract, $configuration);
        }

        foreach (['.env.example', 'webapp/backend/.env.example'] as $environmentPath) {
            $environment = $this->read($environmentPath);
            foreach ([
                'WALLBOARD_LIVE_STREAM_ENABLED=false',
                'WALLBOARD_LIVE_STREAM_PUBLIC_HOST=',
                'WALLBOARD_LIVE_STREAM_RTMPS_BIND_ADDRESS=0.0.0.0',
                'WALLBOARD_LIVE_STREAM_RTMPS_PORT=1936',
                'WALLBOARD_LIVE_STREAM_STREAM_KEY=',
                'WALLBOARD_LIVE_STREAM_TLS_CERTIFICATE_PATH=',
                'WALLBOARD_LIVE_STREAM_TLS_PRIVATE_KEY_PATH=',
            ] as $contract) {
                self::assertStringContainsString($contract, $environment);
            }
            self::assertStringNotContainsString('WALLBOARD_LIVE_STREAM_SHARED_SECRET', $environment);
            self::assertStringNotContainsString('WALLBOARD_LIVE_STREAM_SRT_PORT', $environment);
        }

        $readme = $this->read('README.md');
        foreach ([
            'OBS Custom Service via RTMPS',
            'rtmps://<PUBLIC_HOST>:1936/live',
            'Stream Key',
            'MediaMTX 1.20.0',
            'H.264',
            'AAC',
            'keyframe-interval 2 seconden',
            'TCP-poort `1936`',
            'vaste OBS-adres of het vertrouwde VPN',
            'root-only systemd-credentials',
        ] as $contract) {
            self::assertStringContainsString($contract, $readme);
        }
        self::assertStringNotContainsString('srt://', $readme);
        self::assertStringNotContainsString('SRT-passphrase', $readme);
    }

    public function test_nginx_exposes_only_server_generated_transport_stream_segments_internally(): void
    {
        $nginx = $this->read('infrastructure/nginx/dis.conf');
        self::assertStringContainsString(
            'location ~ "^/__dis_wallboard_live/(segment-[0-9]{20}\\.ts)$" {',
            $nginx,
        );
        self::assertStringNotContainsString(
            'location ~ ^/__dis_wallboard_live/(segment-[0-9]{20}\\.ts)$ {',
            $nginx,
        );
        self::assertStringContainsString('internal;', $nginx);
        self::assertStringContainsString('alias /run/dis-wallboard-live/hls/$1;', $nginx);
        self::assertStringContainsString('disable_symlinks on;', $nginx);
        self::assertStringContainsString('video/mp2t', $nginx);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->repositoryRoot.'/'.$path);
        self::assertNotFalse($contents);

        return $contents;
    }
}
