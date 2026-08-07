#!/usr/bin/env bash
set -euo pipefail
set +x

# Root-only deployment helper. It extracts only the live-ingress settings from
# the managed environment, validates TLS material, and publishes systemd
# credential sources. No secret is printed or passed through a process argv.
readonly ENV_LINK="/opt/dis/.env"
readonly CREDENTIAL_DIRECTORY="/etc/dis-wallboard-live"
readonly MEDIAMTX_CONFIGURATION_PATH="${CREDENTIAL_DIRECTORY}/mediamtx.yml"
readonly STREAM_KEY_HASH_PATH="${CREDENTIAL_DIRECTORY}/stream-key.sha256"
readonly INPUT_URL_PATH="${CREDENTIAL_DIRECTORY}/input.url"
readonly TLS_CERTIFICATE_PATH="${CREDENTIAL_DIRECTORY}/server.crt"
readonly TLS_PRIVATE_KEY_PATH="${CREDENTIAL_DIRECTORY}/server.key"
readonly LEGACY_INGEST_URL_PATH="${CREDENTIAL_DIRECTORY}/ingest.url"
readonly DISABLED_MARKER="disabled"
readonly LOCAL_RTMP_PORT="19350"
readonly AUTH_HTTP_PORT="19351"
readonly INGRESS_CREDENTIAL_DIRECTORY="/run/credentials/dis-wallboard-live-ingress.service"

fail_closed() {
  printf 'The wallboard live-stream credentials could not be generated from a valid managed configuration.\n' >&2
  exit 1
}

root_controlled_file() {
  local path="$1" metadata mode current

  [ -f "${path}" ] && [ ! -L "${path}" ] || return 1
  metadata="$(/usr/bin/stat -c '%u:%a:%h' -- "${path}" 2>/dev/null || true)"
  [[ "${metadata}" =~ ^0:([0-7]+):1$ ]] || return 1
  mode="${BASH_REMATCH[1]}"
  (( (8#${mode} & 8#022) == 0 )) || return 1

  current="${path%/*}"
  while [ -n "${current}" ]; do
    metadata="$(/usr/bin/stat -c '%u:%a' -- "${current}" 2>/dev/null || true)"
    [[ "${metadata}" =~ ^0:([0-7]+)$ ]] || return 1
    mode="${BASH_REMATCH[1]}"
    (( (8#${mode} & 8#022) == 0 )) || return 1
    [ "${current}" = "/" ] && break
    current="${current%/*}"
    [ -n "${current}" ] || current="/"
  done
}

private_mode_is_safe() {
  local mode="$1"

  [[ "${mode}" =~ ^[0-7]+$ ]] || return 1
  (( (8#${mode} & 8#077) == 0 ))
}

root_private_file() {
  local path="$1" mode

  root_controlled_file "${path}" || return 1
  mode="$(/usr/bin/stat -c '%a' -- "${path}" 2>/dev/null || true)"
  private_mode_is_safe "${mode}"
}

resolve_root_controlled_source() {
  local configured="$1" resolved

  [[ "${configured}" == /* ]] && [[ "${configured}" != *$'\n'* ]] && [[ "${configured}" != *$'\r'* ]] \
    || return 1
  resolved="$(/usr/bin/readlink -e -- "${configured}" 2>/dev/null || true)"
  [ -n "${resolved}" ] && [[ "${resolved}" == /* ]] && root_controlled_file "${resolved}" || return 1
  printf '%s' "${resolved}"
}

write_without_newline() {
  printf '%s' "$1" > "$2"
}

main() {
  local resolved_env metadata mode current enabled public_host bind_address rtmps_port
  local stream_key configured_certificate configured_private_key resolved_certificate resolved_private_key label resolved_public_ipv4
  local generation certificate_public_key private_public_key stream_key_hash file leaf_certificate public_address_octets

  [ "${EUID}" -eq 0 ] || fail_closed
  [ "$#" -eq 0 ] || fail_closed
  umask 0077

  resolved_env="$(/usr/bin/readlink -e -- "${ENV_LINK}" 2>/dev/null || true)"
  [ -n "${resolved_env}" ] && root_controlled_file "${resolved_env}" || fail_closed

  read_env_value() {
    local key="$1" line value=""

    while IFS= read -r line || [ -n "${line}" ]; do
      line="${line%$'\r'}"
      case "${line}" in
        "${key}="*) value="${line#*=}" ;;
      esac
    done < "${resolved_env}"

    case "${value}" in
      \"*\") value="${value:1:${#value}-2}" ;;
      \'*\') value="${value:1:${#value}-2}" ;;
    esac
    printf '%s' "${value}"
  }

  enabled="$(read_env_value WALLBOARD_LIVE_STREAM_ENABLED)"
  case "${enabled,,}" in
    true|1|\(true\)) enabled=1 ;;
    false|0|\(false\)|"") enabled=0 ;;
    *) fail_closed ;;
  esac

  metadata="$(/usr/bin/stat -c '%u:%g:%a' -- "${CREDENTIAL_DIRECTORY}" 2>/dev/null || true)"
  [ "${metadata}" = "0:0:700" ] || fail_closed
  generation="$(/usr/bin/mktemp -d "${CREDENTIAL_DIRECTORY}/.generation.XXXXXX")"
  cleanup() {
    /usr/bin/rm -rf -- "${generation:-}" 2>/dev/null || true
  }
  trap cleanup EXIT INT TERM
  /usr/bin/chown root:root "${generation}"
  /usr/bin/chmod 0700 "${generation}"

  if [ "${enabled}" = "0" ]; then
    for file in mediamtx.yml stream-key.sha256 input.url server.crt server.key; do
      write_without_newline "${DISABLED_MARKER}" "${generation}/${file}"
    done
  else
    public_host="$(read_env_value WALLBOARD_LIVE_STREAM_PUBLIC_HOST)"
    bind_address="$(read_env_value WALLBOARD_LIVE_STREAM_RTMPS_BIND_ADDRESS)"
    rtmps_port="$(read_env_value WALLBOARD_LIVE_STREAM_RTMPS_PORT)"
    stream_key="$(read_env_value WALLBOARD_LIVE_STREAM_STREAM_KEY)"
    configured_certificate="$(read_env_value WALLBOARD_LIVE_STREAM_TLS_CERTIFICATE_PATH)"
    configured_private_key="$(read_env_value WALLBOARD_LIVE_STREAM_TLS_PRIVATE_KEY_PATH)"
    [ -n "${bind_address}" ] || bind_address="0.0.0.0"
    [ -n "${rtmps_port}" ] || rtmps_port="1936"

    [ -n "${public_host}" ] && [ "${#public_host}" -le 253 ] \
      && [[ "${public_host}" != *..* ]] && [[ "${public_host}" != .* ]] && [[ "${public_host}" != *. ]] \
      || fail_closed
    if [[ ! "${public_host}" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
      IFS='.' read -r -a hostname_labels <<< "${public_host}"
      [ "${#hostname_labels[@]}" -gt 0 ] || fail_closed
      for label in "${hostname_labels[@]}"; do
        [ "${#label}" -ge 1 ] && [ "${#label}" -le 63 ] \
          && [[ "${label}" =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$ ]] || fail_closed
      done
      resolved_public_ipv4="$(/usr/bin/timeout 10 /usr/bin/getent ahostsv4 "${public_host}" 2>/dev/null \
        | /usr/bin/awk '$2 == "STREAM" { print $1 }' | LC_ALL=C /usr/bin/sort -u || true)"
      [ -n "${resolved_public_ipv4}" ] || fail_closed
    else
      IFS='.' read -r -a public_address_octets <<< "${public_host}"
      [ "${#public_address_octets[@]}" -eq 4 ] || fail_closed
      for current in "${public_address_octets[@]}"; do
        [[ "${current}" =~ ^[0-9]{1,3}$ ]] || fail_closed
        [ "${#current}" -eq 1 ] || [ "${current:0:1}" != "0" ] || fail_closed
        (( 10#${current} <= 255 )) || fail_closed
      done
      (( 10#${public_address_octets[0]} > 0 && 10#${public_address_octets[0]} < 224 )) || fail_closed
      [ "${public_host}" != "255.255.255.255" ] || fail_closed
    fi
    [[ "${bind_address}" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || fail_closed
    IFS='.' read -r -a address_octets <<< "${bind_address}"
    [ "${#address_octets[@]}" -eq 4 ] || fail_closed
    for current in "${address_octets[@]}"; do
      [[ "${current}" =~ ^[0-9]{1,3}$ ]] || fail_closed
      [ "${#current}" -eq 1 ] || [ "${current:0:1}" != "0" ] || fail_closed
      (( 10#${current} <= 255 )) || fail_closed
    done
    if [ "${bind_address}" != "0.0.0.0" ]; then
      (( 10#${address_octets[0]} > 0 && 10#${address_octets[0]} < 224 )) || fail_closed
      [ "${bind_address}" != "255.255.255.255" ] || fail_closed
    fi
    [[ "${rtmps_port}" =~ ^[0-9]{4,5}$ ]] || fail_closed
    (( 10#${rtmps_port} >= 1024 && 10#${rtmps_port} <= 65535 )) || fail_closed
    [ "${rtmps_port}" != "${LOCAL_RTMP_PORT}" ] && [ "${rtmps_port}" != "${AUTH_HTTP_PORT}" ] || fail_closed
    [[ "${stream_key}" =~ ^[A-Za-z0-9._~-]{32,79}$ ]] || fail_closed
    current="${stream_key:0:1}"
    [ -n "${stream_key//${current}/}" ] || fail_closed

    resolved_certificate="$(resolve_root_controlled_source "${configured_certificate}")" || fail_closed
    resolved_private_key="$(resolve_root_controlled_source "${configured_private_key}")" || fail_closed
    root_private_file "${resolved_private_key}" || fail_closed
    [ "${resolved_certificate}" != "${resolved_private_key}" ] || fail_closed
    /usr/bin/cp --reflink=never -- "${resolved_certificate}" "${generation}/server.crt"
    /usr/bin/cp --reflink=never -- "${resolved_private_key}" "${generation}/server.key"
    /usr/bin/chown root:root "${generation}/server.crt" "${generation}/server.key"
    /usr/bin/chmod 0600 "${generation}/server.crt" "${generation}/server.key"
    metadata="$(/usr/bin/stat -c '%s:%h' -- "${generation}/server.crt" 2>/dev/null || true)"
    [[ "${metadata}" =~ ^([0-9]+):1$ ]] && (( BASH_REMATCH[1] >= 1 && BASH_REMATCH[1] <= 1048576 )) \
      || fail_closed
    metadata="$(/usr/bin/stat -c '%s:%h' -- "${generation}/server.key" 2>/dev/null || true)"
    [[ "${metadata}" =~ ^([0-9]+):1$ ]] && (( BASH_REMATCH[1] >= 1 && BASH_REMATCH[1] <= 65536 )) \
      || fail_closed

    /usr/bin/openssl x509 -in "${generation}/server.crt" -noout -checkend 86400 >/dev/null 2>&1 \
      || fail_closed
    if [[ "${public_host}" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
      /usr/bin/openssl x509 -in "${generation}/server.crt" -noout -checkip "${public_host}" >/dev/null 2>&1 \
        || fail_closed
    else
      /usr/bin/openssl x509 -in "${generation}/server.crt" -noout -checkhost "${public_host}" >/dev/null 2>&1 \
        || fail_closed
    fi
    /usr/bin/openssl pkey -in "${generation}/server.key" -passin pass: -check -noout >/dev/null 2>&1 \
      || fail_closed
    root_controlled_file /etc/ssl/certs/ca-certificates.crt || fail_closed
    leaf_certificate="${generation}/leaf.crt"
    /usr/bin/openssl x509 -in "${generation}/server.crt" -out "${leaf_certificate}" >/dev/null 2>&1 \
      || fail_closed
    /usr/bin/chown root:root "${leaf_certificate}"
    /usr/bin/chmod 0600 "${leaf_certificate}"
    /usr/bin/openssl verify -purpose sslserver -verify_depth 8 \
      -CAfile /etc/ssl/certs/ca-certificates.crt \
      -untrusted "${generation}/server.crt" "${leaf_certificate}" >/dev/null 2>&1 \
      || fail_closed
    /usr/bin/rm -f -- "${leaf_certificate}"
    leaf_certificate=""
    certificate_public_key="$(/usr/bin/openssl x509 -in "${generation}/server.crt" -pubkey -noout 2>/dev/null \
      | /usr/bin/openssl pkey -pubin -outform DER 2>/dev/null \
      | /usr/bin/sha256sum | /usr/bin/cut -d' ' -f1)"
    private_public_key="$(/usr/bin/openssl pkey -in "${generation}/server.key" -passin pass: -pubout -outform DER 2>/dev/null \
      | /usr/bin/sha256sum | /usr/bin/cut -d' ' -f1)"
    [[ "${certificate_public_key}" =~ ^[a-f0-9]{64}$ ]] \
      && [ "${certificate_public_key}" = "${private_public_key}" ] || fail_closed

    stream_key_hash="$(printf '%s' "${stream_key}" | /usr/bin/sha256sum | /usr/bin/cut -d' ' -f1)"
    [[ "${stream_key_hash}" =~ ^[a-f0-9]{64}$ ]] || fail_closed
    write_without_newline "${stream_key_hash}" "${generation}/stream-key.sha256"
    write_without_newline "rtmp://127.0.0.1:${LOCAL_RTMP_PORT}/live/${stream_key}" "${generation}/input.url"

    /usr/bin/printf '%s\n' \
      'logLevel: warn' \
      'logDestinations: [stdout]' \
      'logStructured: false' \
      'dumpPackets: false' \
      'readTimeout: 15s' \
      'writeTimeout: 15s' \
      'runOnConnect:' \
      'runOnDisconnect:' \
      'authMethod: http' \
      "authHTTPAddress: http://127.0.0.1:${AUTH_HTTP_PORT}/auth" \
      'authHTTPExclude: []' \
      'api: false' \
      'metrics: false' \
      'pprof: false' \
      'playback: false' \
      'rtsp: false' \
      'rtmp: true' \
      'rtmpEncryption: optional' \
      "rtmpAddress: 127.0.0.1:${LOCAL_RTMP_PORT}" \
      "rtmpsAddress: ${bind_address}:${rtmps_port}" \
      "rtmpServerKey: ${INGRESS_CREDENTIAL_DIRECTORY}/server.key" \
      "rtmpServerCert: ${INGRESS_CREDENTIAL_DIRECTORY}/server.crt" \
      'rtmpTrustedProxies: []' \
      'hls: false' \
      'webrtc: false' \
      'srt: false' \
      'moq: false' \
      'pathDefaults:' \
      '  source: publisher' \
      '  maxReaders: 1' \
      '  overridePublisher: false' \
      '  record: false' \
      '  runOnInit:' \
      '  runOnDemand:' \
      '  runOnAvailable:' \
      '  runOnOnline:' \
      '  runOnRead:' \
      'paths:' \
      '  "~^live/[A-Za-z0-9._~-]{32,79}$":' \
      > "${generation}/mediamtx.yml"
  fi

  for file in mediamtx.yml stream-key.sha256 input.url server.crt server.key; do
    [ -f "${generation}/${file}" ] && [ ! -L "${generation}/${file}" ] || fail_closed
    /usr/bin/chown root:root "${generation}/${file}"
    /usr/bin/chmod 0600 "${generation}/${file}"
    /usr/bin/sync -f "${generation}/${file}"
  done

  # Publish the activation config last. Running units retain their prior systemd
  # credential snapshot; deploy keeps them stopped and refresh rolls this set
  # back unless both restarted services pass stable readiness checks.
  for file in server.crt server.key stream-key.sha256 input.url mediamtx.yml; do
    /usr/bin/mv -fT -- "${generation}/${file}" "${CREDENTIAL_DIRECTORY}/${file}"
  done
  /usr/bin/rmdir -- "${generation}"
  generation=""
  /usr/bin/sync -f "${CREDENTIAL_DIRECTORY}"
  if [ -e "${LEGACY_INGEST_URL_PATH}" ] || [ -L "${LEGACY_INGEST_URL_PATH}" ]; then
    [ -f "${LEGACY_INGEST_URL_PATH}" ] && [ ! -L "${LEGACY_INGEST_URL_PATH}" ] \
      && [ "$(/usr/bin/stat -c '%u:%g:%a:%h' -- "${LEGACY_INGEST_URL_PATH}" 2>/dev/null || true)" = "0:0:600:1" ] \
      || fail_closed
    /usr/bin/rm -f -- "${LEGACY_INGEST_URL_PATH}"
  fi
  trap - EXIT INT TERM

  unset stream_key current stream_key_hash certificate_public_key private_public_key
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
