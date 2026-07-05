# formflow-core

Shared form-engine + API/connector core for **FormFlow Pro** (`ISF\`) and **FormFlow Lite** (`FFFL\`).
Extracted 2026-07-05 from ~9.3k LOC of duplicated code the property-#11 microscope exposed
(identical bugs at identical line numbers). Namespace: `Peanut\FormCore\`.

## Status: foundation (first slice)
Extracted so far — the API-contract cluster + two pure utilities, characterization-tested:
- `Peanut\FormCore\Api\ApiConnectorInterface` (+ the `AccountValidationResult` / `EnrollmentResult` / `BookingResult` result types) and `SchedulingResult`
- `Peanut\FormCore\Api\ConnectorRegistry`
- `Peanut\FormCore\UTMTracker`, `Peanut\FormCore\Hooks`

`isf_`/`fffl_` hook-name literals neutralized to `peanut_formcore_*` — consumers adopt these on cutover.

## Consuming (per the extraction plan)
`composer require peanut/formflow-core` via a private `vcs` repository entry; bundled into each
plugin's `vendor/` at build time (`scripts/publish-plugin.sh`). Lockstep-versioned with the consumers.

## Test
`composer install && vendor/bin/phpunit` — the tests pin behaviour both plugins relied on (WP is stubbed).

See `Peanut-meta/2026-07-05-formflow-shared-core-scoping.md` for the full plan + remaining modules.
