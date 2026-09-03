#!/usr/bin/env bash
# Run the required Composer advisory check with bounded transport-only retries.
# Valid clean/finding results are terminal; cached or offline results are forbidden.
set -uo pipefail

max_attempts="${PEANUT_AUDIT_MAX_ATTEMPTS:-3}"
retry_delay="${PEANUT_AUDIT_RETRY_DELAY:-2}"

case "$max_attempts" in
  ''|*[!0-9]*|0) echo "PEANUT_AUDIT_MAX_ATTEMPTS must be a positive integer" >&2; exit 2 ;;
esac
case "$retry_delay" in
  ''|*[!0-9]*) echo "PEANUT_AUDIT_RETRY_DELAY must be a non-negative integer" >&2; exit 2 ;;
esac

result_file="$(mktemp "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/formflow-core-composer-audit.XXXXXX")"
error_file="$(mktemp "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/formflow-core-composer-audit-error.XXXXXX")"
cleanup() { rm -f -- "$result_file" "$error_file"; }
trap cleanup EXIT

expected_result_exit() {
  # The variables in the next line belong to PHP, not the shell.
  # shellcheck disable=SC2016
  php -r '$r=json_decode(file_get_contents($argv[1]), true); if (!is_array($r) || !isset($r["advisories"], $r["abandoned"]) || !is_array($r["advisories"]) || !is_array($r["abandoned"])) { exit(2); } echo count($r["advisories"]) + count($r["abandoned"]) > 0 ? "1" : "0";' "$1"
}

attempt=1
while :; do
  audit_rc=0
  composer audit --locked --no-interaction --format=json >"$result_file" 2>"$error_file" || audit_rc=$?
  cat "$result_file"
  cat "$error_file" >&2

  if expected_rc="$(expected_result_exit "$result_file")"; then
    if [ "$audit_rc" -eq "$expected_rc" ]; then
      exit "$audit_rc"
    fi
    echo "Composer audit exit/result mismatch (exit $audit_rc); failing closed." >&2
    [ "$audit_rc" -ne 0 ] && exit "$audit_rc"
    exit 2
  fi

  if [ "$audit_rc" -ne 100 ]; then
    echo "Composer audit produced no complete result (exit $audit_rc); non-retryable scanner failure." >&2
    [ "$audit_rc" -ne 0 ] && exit "$audit_rc"
    exit 2
  fi

  if [ "$attempt" -ge "$max_attempts" ]; then
    echo "Composer audit transport unavailable after $attempt attempt(s)." >&2
    exit 70
  fi

  echo "Composer audit transport unavailable; retrying attempt $((attempt + 1)) of $max_attempts." >&2
  [ "$retry_delay" -eq 0 ] || sleep "$((attempt * retry_delay))"
  attempt=$((attempt + 1))
done
