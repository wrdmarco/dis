#!/usr/bin/env bash
set -euo pipefail

TEST_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${TEST_SCRIPT_DIR}/../.." && pwd)"
TEST_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/dis-osrm-admin-source-parser-test.XXXXXX")"

cleanup() {
  case "${TEST_ROOT}" in
    "${TMPDIR:-/tmp}"/dis-osrm-admin-source-parser-test.*) rm -rf -- "${TEST_ROOT}" ;;
    *) printf 'Refusing to clean unexpected test path: %s\n' "${TEST_ROOT}" >&2 ;;
  esac
}
trap cleanup EXIT

# shellcheck source=scripts/osrm-admin-request-worker.sh
source "${APP_ROOT}/scripts/osrm-admin-request-worker.sh"

SEALED_MODE=400
stat() {
  if [ "${1:-}" = '-c' ] && [ "${2:-}" = '%u:%g:%a:%h' ]; then
    printf '0:0:%s:1\n' "${SEALED_MODE}"
    return 0
  fi
  command stat "$@"
}

header_file="${TEST_ROOT}/headers"
write_location_header() {
  local location="$1"
  chmod 0600 "${header_file}" 2>/dev/null || true
  printf 'HTTP/1.1 302 Found\r\nLocation: %s\r\nContent-Length: 0\r\n\r\n' "${location}" > "${header_file}"
  chmod 0400 "${header_file}"
}

valid_nl_url='https://download.geofabrik.de/europe/netherlands-260715.osm.pbf'
valid_be_url='https://download.geofabrik.de/europe/belgium-260715.osm.pbf'
write_location_header "${valid_nl_url}"
[ "$(parse_versioned_source_location "${header_file}" netherlands)" = "${valid_nl_url}" ]
versioned_source_url_is_valid netherlands "${valid_nl_url}"
write_location_header "${valid_be_url}"
[ "$(parse_versioned_source_location "${header_file}" belgium)" = "${valid_be_url}" ]
versioned_source_url_is_valid belgium "${valid_be_url}"
if versioned_source_url_is_valid netherlands "${valid_be_url}"; then
  printf 'A Belgian immutable URL was unexpectedly accepted as the Dutch source.\n' >&2
  exit 1
fi

invalid_urls=(
  'http://download.geofabrik.de/europe/netherlands-260715.osm.pbf'
  'https://other.example/europe/netherlands-260715.osm.pbf'
  'https://download.geofabrik.de:443/europe/netherlands-260715.osm.pbf'
  'https://user@download.geofabrik.de/europe/netherlands-260715.osm.pbf'
  'https://download.geofabrik.de/asia/netherlands-260715.osm.pbf'
  'https://download.geofabrik.de/europe/netherlands-latest.osm.pbf'
  'https://download.geofabrik.de/europe/netherlands-261332.osm.pbf'
  'https://download.geofabrik.de/europe/netherlands-260715.osm.pbf?download=1'
  'https://download.geofabrik.de/europe/netherlands-260715.osm.pbf#fragment'
  'https://download.geofabrik.de/europe/netherlands-260715.osm.pbf/extra'
)
for invalid_url in "${invalid_urls[@]}"; do
  write_location_header "${invalid_url}"
  if parse_versioned_source_location "${header_file}" netherlands >/dev/null 2>&1; then
    printf 'Unsafe Geofabrik redirect was unexpectedly accepted: %s\n' "${invalid_url}" >&2
    exit 1
  fi
done

write_location_header "${valid_nl_url}"
chmod 0600 "${header_file}"
printf 'Location: %s\r\n' "${valid_nl_url}" >> "${header_file}"
chmod 0400 "${header_file}"
if parse_versioned_source_location "${header_file}" netherlands >/dev/null 2>&1; then
  printf 'Duplicate Geofabrik Location headers were unexpectedly accepted.\n' >&2
  exit 1
fi

expected_source_set='[{"id":"netherlands","latest_url":"https://download.geofabrik.de/europe/netherlands-latest.osm.pbf"},{"id":"belgium","latest_url":"https://download.geofabrik.de/europe/belgium-latest.osm.pbf"}]'
[ "$(canonical_source_set_json)" = "${expected_source_set}" ]
[ "$(canonical_source_set_json | sha256sum | awk '{ print $1 }')" = \
  'ec4174cfe1cba6c41db2475fdbe9f61c4bb22f4255653c0fb212341eeee7c072' ]

# NL and BE share one monotone progress scale. Starting the smaller Belgian
# download cannot reset the percentage reached by the Dutch file.
[ "$(composite_download_percent 0 899 1200)" = '74' ]
[ "$(composite_download_percent 0 900 1200 100)" = '75' ]
[ "$(composite_download_percent 900 0 1200)" = '75' ]
[ "$(composite_download_percent 900 150 1200)" = '87' ]
[ "$(composite_download_percent 900 300 1200)" = '99' ]
[ "$(composite_download_percent 900 300 1200 100)" = '100' ]

# With the default factor and reserve, two billion bytes of supplier inputs require
# exactly input*(8+1)+2 GiB, not a second accidental merge-size multiplier.
[ "$(composite_disk_required_bytes 2000000000 8 2147483648)" = '20147483648' ]

merged_size_is_safe 5000000000 3000000000
if merged_size_is_safe 5368709121 3000000000; then
  printf 'A merged PBF above the fixed combined limit was unexpectedly accepted.\n' >&2
  exit 1
fi
if merged_size_is_safe 2001 1000; then
  printf 'A merged PBF above the source-relative bound was unexpectedly accepted.\n' >&2
  exit 1
fi

md5_file="${TEST_ROOT}/supplier.md5"
expected_filename='netherlands-260715.osm.pbf'
printf 'ABCDEFABCDEFABCDEFABCDEFABCDEFAB  %s\n' "${expected_filename}" > "${md5_file}"
chmod 0400 "${md5_file}"
[ "$(parse_supplier_md5_file "${md5_file}" "${expected_filename}")" = 'abcdefabcdefabcdefabcdefabcdefab' ]

chmod 0600 "${md5_file}"
printf 'abcdefabcdefabcdefabcdefabcdefab  netherlands-latest.osm.pbf\n' > "${md5_file}"
chmod 0400 "${md5_file}"
if parse_supplier_md5_file "${md5_file}" "${expected_filename}" >/dev/null 2>&1; then
  printf 'The rolling latest filename was unexpectedly accepted by the versioned MD5 parser.\n' >&2
  exit 1
fi

chmod 0600 "${md5_file}"
printf 'abcdefabcdefabcdefabcdefabcdefab  %s\nextra\n' "${expected_filename}" > "${md5_file}"
chmod 0400 "${md5_file}"
if parse_supplier_md5_file "${md5_file}" "${expected_filename}" >/dev/null 2>&1; then
  printf 'Additional supplier MD5 content was unexpectedly accepted.\n' >&2
  exit 1
fi

printf 'OSRM immutable Geofabrik redirect and supplier MD5 parser test passed.\n'
