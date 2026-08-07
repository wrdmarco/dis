#!/usr/bin/env bash
set -euo pipefail
set +x

readonly SERVICE_USER="dis-wallboard-live"
readonly SERVICE_GROUP="dis-wallboard-live"
readonly RUNTIME_DIRECTORY="/run/dis-wallboard-live"
readonly OUTPUT_DIRECTORY="${RUNTIME_DIRECTORY}/hls"
readonly EXPECTED_CREDENTIAL_DIRECTORY="/run/credentials/dis-wallboard-live.service"
readonly MAX_SEGMENT_BYTES=6291456
readonly MAX_OUTPUT_BYTES=67108864
readonly MAX_OPEN_SEGMENT_SECONDS=12
readonly MAX_SEGMENT_FILES=10

log_generic() {
  printf 'DIS wallboard live stream: %s\n' "$1"
}

fail_closed() {
  log_generic 'runtime validation failed; ingest was not started.' >&2
  exit 78
}

[ "$#" -eq 0 ] || fail_closed
[ "$(/usr/bin/id -un)" = "${SERVICE_USER}" ] || fail_closed
[ "$(/usr/bin/id -gn)" = "${SERVICE_GROUP}" ] || fail_closed
[ "$(/usr/bin/id -G)" = "$(/usr/bin/id -g)" ] || fail_closed
[ "${CREDENTIALS_DIRECTORY:-}" = "${EXPECTED_CREDENTIAL_DIRECTORY}" ] || fail_closed

credential_file="${CREDENTIALS_DIRECTORY}/input.url"
[ -f "${credential_file}" ] && [ ! -L "${credential_file}" ] && [ -r "${credential_file}" ] || fail_closed
[ "$(/usr/bin/stat -c '%h' -- "${credential_file}" 2>/dev/null || true)" = "1" ] || fail_closed

credential="$(< "${credential_file}")"
if [ "${credential}" = "disabled" ]; then
  unset credential credential_file
  log_generic 'ingest is disabled.'
  exec /usr/bin/sleep infinity
fi

credential_pattern='^rtmp://127\.0\.0\.1:19350/live/[A-Za-z0-9._~-]{32,79}$'
[[ "${credential}" =~ ${credential_pattern} ]] || fail_closed
stream_key="${credential##*/}"
repeated_character="${stream_key:0:1}"
[ -n "${stream_key//${repeated_character}/}" ] || fail_closed
unset credential credential_pattern stream_key repeated_character

[ -d "${RUNTIME_DIRECTORY}" ] && [ ! -L "${RUNTIME_DIRECTORY}" ] || fail_closed
[ "$(/usr/bin/stat -c '%U:%G:%a' -- "${RUNTIME_DIRECTORY}" 2>/dev/null || true)" = "${SERVICE_USER}:${SERVICE_GROUP}:750" ] \
  || fail_closed

if [ -e "${OUTPUT_DIRECTORY}" ] || [ -L "${OUTPUT_DIRECTORY}" ]; then
  [ -d "${OUTPUT_DIRECTORY}" ] && [ ! -L "${OUTPUT_DIRECTORY}" ] || fail_closed
else
  /usr/bin/install -d -m 0750 "${OUTPUT_DIRECTORY}"
fi
/usr/bin/chmod 0750 "${OUTPUT_DIRECTORY}"
[ "$(/usr/bin/stat -c '%U:%G:%a' -- "${OUTPUT_DIRECTORY}" 2>/dev/null || true)" = "${SERVICE_USER}:${SERVICE_GROUP}:750" ] \
  || fail_closed

clean_output_directory() {
  local entry name

  # Remove only files that this runner itself can generate. Unexpected entries
  # fail closed instead of being traversed or overwritten.
  while IFS= read -r -d '' entry; do
    name="${entry##*/}"
    if [[ "${name}" =~ ^segment-[0-9]{20}\.ts(\.tmp)?$ ]] \
      || [ "${name}" = "index.m3u8" ] \
      || [ "${name}" = "index.m3u8.tmp" ]; then
      [ -f "${entry}" ] && [ ! -L "${entry}" ] \
        && [ "$(/usr/bin/stat -c '%h' -- "${entry}" 2>/dev/null || true)" = "1" ] \
        || fail_closed
      /usr/bin/rm -f -- "${entry}"
    else
      fail_closed
    fi
  done < <(/usr/bin/find -P "${OUTPUT_DIRECTORY}" -mindepth 1 -maxdepth 1 -print0)
}

umask 0027
ffmpeg_pid=""

terminate_ffmpeg() {
  local attempt

  [ -n "${ffmpeg_pid:-}" ] || return 0
  /usr/bin/kill -TERM "${ffmpeg_pid}" 2>/dev/null || true
  for attempt in 1 2 3 4 5; do
    /usr/bin/kill -0 "${ffmpeg_pid}" 2>/dev/null || break
    /usr/bin/sleep 1
  done
  /usr/bin/kill -KILL "${ffmpeg_pid}" 2>/dev/null || true
  wait "${ffmpeg_pid}" 2>/dev/null || true
  ffmpeg_pid=""
}

shutdown() {
  trap - TERM INT HUP
  terminate_ffmpeg
  exit 0
}
trap shutdown TERM INT HUP

retry_delay_seconds=2
last_retry_log_at=0
log_generic 'worker is ready and will wait for an OBS publisher.'

# MediaMTX rejects a reader while no publisher exists. Keep this service stable
# and retry locally instead of making systemd flap whenever OBS is offline.
while true; do
  clean_output_directory
  start_number="$(/usr/bin/date -u +%s%6N)"
  [[ "${start_number}" =~ ^[0-9]{16}$ ]] || fail_closed

  /usr/bin/ffmpeg \
    -hide_banner \
    -loglevel quiet \
    -nostdin \
    -protocol_whitelist rtmp,tcp \
    -rw_timeout 15000000 \
    -f flv \
    -/i "${credential_file}" \
    -map 0:v:0 \
    -map '0:a:0?' \
    -sn \
    -dn \
    -c:v copy \
    -bsf:v h264_mp4toannexb \
    -c:a aac \
    -b:a 160k \
    -ac 2 \
    -ar 48000 \
    -max_muxing_queue_size 1024 \
    -f hls \
    -hls_segment_type mpegts \
    -hls_time 2 \
    -hls_list_size 6 \
    -hls_delete_threshold 3 \
    -hls_allow_cache 0 \
    -hls_flags delete_segments+omit_endlist+temp_file \
    -hls_base_url segments/ \
    -start_number "${start_number}" \
    -hls_segment_filename "${OUTPUT_DIRECTORY}/segment-%020d.ts" \
    "${OUTPUT_DIRECTORY}/index.m3u8" \
    >/dev/null 2>&1 &
  ffmpeg_pid=$!
  unset start_number

  violation=0
  produced_output=0
  declare -A first_seen_open_segment=()
  while /usr/bin/kill -0 "${ffmpeg_pid}" 2>/dev/null; do
    now="$(/usr/bin/date -u +%s)"
    total_bytes=0
    segment_count=0
    declare -A currently_open=()

    shopt -s nullglob
    for segment in "${OUTPUT_DIRECTORY}"/segment-*.ts "${OUTPUT_DIRECTORY}"/segment-*.ts.tmp; do
      name="${segment##*/}"
      [[ "${name}" =~ ^segment-[0-9]{20}\.ts(\.tmp)?$ ]] || continue
      [ ! -L "${segment}" ] || { violation=1; break; }
      # FFmpeg atomically renames .ts.tmp to .ts. A pathname disappearing
      # between glob expansion and stat is that normal publication race.
      if [ ! -e "${segment}" ]; then
        [ ! -L "${segment}" ] || { violation=1; break; }
        continue
      fi
      if [ ! -f "${segment}" ]; then
        if [ ! -e "${segment}" ] && [ ! -L "${segment}" ]; then
          continue
        fi
        violation=1
        break
      fi
      [ ! -L "${segment}" ] || { violation=1; break; }
      metadata="$(/usr/bin/stat -c '%h:%s' -- "${segment}" 2>/dev/null || true)"
      if [ -z "${metadata}" ] && [ ! -e "${segment}" ] && [ ! -L "${segment}" ]; then
        continue
      fi
      [[ "${metadata}" =~ ^1:([0-9]+)$ ]] || { violation=1; break; }
      size="${BASH_REMATCH[1]}"
      segment_count=$((segment_count + 1))
      total_bytes=$((total_bytes + size))
      if (( size > MAX_SEGMENT_BYTES || total_bytes > MAX_OUTPUT_BYTES || segment_count > MAX_SEGMENT_FILES )); then
        violation=1
        break
      fi

      if [[ "${name}" == *.tmp ]]; then
        currently_open["${name}"]=1
        if [ -z "${first_seen_open_segment[${name}]+x}" ]; then
          first_seen_open_segment["${name}"]="${now}"
        elif (( now - first_seen_open_segment[${name}] >= MAX_OPEN_SEGMENT_SECONDS )); then
          violation=1
          break
        fi
      fi
    done
    shopt -u nullglob
    (( segment_count > 0 )) && produced_output=1

    for name in "${!first_seen_open_segment[@]}"; do
      if [ -z "${currently_open[${name}]+x}" ]; then
        unset "first_seen_open_segment[$name]"
      fi
    done

    [ "${violation}" = "0" ] || break
    /usr/bin/sleep 1
  done

  if [ "${violation}" = "1" ]; then
    terminate_ffmpeg
  else
    wait "${ffmpeg_pid}" 2>/dev/null || true
    ffmpeg_pid=""
  fi
  clean_output_directory

  now="$(/usr/bin/date -u +%s)"
  if (( now - last_retry_log_at >= 60 )); then
    if [ "${violation}" = "1" ]; then
      log_generic 'bounded-output guard rejected an invalid or oversized stream; waiting to retry.' >&2
    else
      log_generic 'OBS input is unavailable or incompatible; waiting to retry.'
    fi
    last_retry_log_at="${now}"
  fi
  if [ "${produced_output}" = "1" ]; then
    retry_delay_seconds=2
  else
    retry_delay_seconds=$((retry_delay_seconds * 2))
    (( retry_delay_seconds <= 15 )) || retry_delay_seconds=15
  fi
  /usr/bin/sleep "${retry_delay_seconds}"
done
