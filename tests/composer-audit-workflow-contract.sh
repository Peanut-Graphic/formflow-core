#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKFLOW="$ROOT/.github/workflows/compatibility.yml"
fail() { echo "FAIL: $1" >&2; exit 1; }

[ -x "$ROOT/scripts/run-composer-audit-transport.sh" ] || fail "audit transport wrapper is missing or not executable"
[ -x "$ROOT/tests/composer-audit-transport-contract.sh" ] || fail "adversarial transport contract is missing or not executable"
grep -q 'bash scripts/run-composer-audit-transport.sh' "$WORKFLOW" || fail "Composer audit bypasses the wrapper"
grep -q 'bash tests/composer-audit-transport-contract.sh' "$WORKFLOW" || fail "adversarial transport contract is not executed"
grep -q 'bash tests/composer-audit-workflow-contract.sh' "$WORKFLOW" || fail "workflow bypass contract is not executed"
! grep -Eq 'run:[[:space:]]+composer audit' "$WORKFLOW" || fail "workflow retains a direct audit command"

echo "FORMFLOW-CORE COMPOSER AUDIT WORKFLOW CONTRACT PASSED"
