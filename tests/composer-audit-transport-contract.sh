#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WRAPPER="$ROOT/scripts/run-composer-audit-transport.sh"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/formflow-core-composer-audit-test.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT
fail() { echo "FAIL: $1" >&2; exit 1; }

cat >"$TMP/composer" <<'EOF'
#!/usr/bin/env bash
count=0
[ ! -f "$FAKE_COUNT" ] || count=$(<"$FAKE_COUNT")
count=$((count + 1))
printf '%s' "$count" >"$FAKE_COUNT"
case "$FAKE_MODE" in
  clean) printf '%s\n' '{"advisories":[],"abandoned":[]}' ; exit 0 ;;
  finding) printf '%s\n' '{"advisories":{"CVE-1":{}},"abandoned":[]}' ; exit 1 ;;
  recover) [ "$count" -lt 3 ] && exit 100; printf '%s\n' '{"advisories":[],"abandoned":[]}' ; exit 0 ;;
  exhaust) exit 100 ;;
  config) exit 2 ;;
  mismatch) printf '%s\n' '{"advisories":[],"abandoned":[]}' ; exit 2 ;;
  false-green) printf '%s\n' '{"advisories":{"CVE-1":{}},"abandoned":[]}' ; exit 0 ;;
  malformed) printf '%s\n' '{"unexpected":true}' ; exit 0 ;;
esac
EOF
chmod +x "$TMP/composer"

run_case() {
  local mode="$1" expected="$2" expected_calls="$3"
  local count="$TMP/$mode-count"
  set +e
  PATH="$TMP:$PATH" FAKE_MODE="$mode" FAKE_COUNT="$count" PEANUT_AUDIT_RETRY_DELAY=0 "$WRAPPER" >/dev/null 2>&1
  status=$?
  set -e
  [ "$status" -eq "$expected" ] || fail "$mode returned $status, expected $expected"
  [ "$(<"$count")" -eq "$expected_calls" ] || fail "$mode used the wrong attempt count"
}

run_case clean 0 1
run_case finding 1 1
run_case recover 0 3
run_case exhaust 70 3
run_case config 2 1
run_case mismatch 2 1
run_case false-green 2 1
run_case malformed 2 1

set +e
PEANUT_AUDIT_MAX_ATTEMPTS=0 "$WRAPPER" >/dev/null 2>&1
status=$?
set -e
[ "$status" -eq 2 ] || fail "invalid retry configuration did not fail before scanning"

echo "FORMFLOW-CORE COMPOSER AUDIT TRANSPORT CONTRACT PASSED"
