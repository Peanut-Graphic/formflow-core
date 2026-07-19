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

### Second slice — signed-update verification (`Peanut\FormCore\Update`)
The security primitive behind Peanut's signed plugin updates, extracted from FormFlow Pro's
`class-updater.php` so all three consumers verify identically instead of drifting:

- **`PackageVerifier`** — pure + WordPress-free. `verifyBytes()` checks sha256 **and** the detached
  Ed25519 signature (sha256 alone proves nothing — whoever can swap the zip can swap the hash beside
  it). `isTrustedPackageUrl()` matches hosts on a **label boundary**, so `github.com` matches
  `codeload.github.com` but `peanutgraphic.com` never matches `evilpeanutgraphic.com`.
- **`SignedUpdateGate`** — hooks `upgrader_pre_download`, downloads the package, fetches the
  `<asset>.manifest.json` sidecar, verifies, and hands WordPress a local file — or a `WP_Error`.

**Fail-closed** on every branch: missing manifest field, undecodable base64, wrong key/signature
length, absent libsodium, untrusted host. A verifier that returns true on "couldn't check" is a
facade, which is the defect class this package exists to retire.

Enforcement is deliberately stricter than a plain host pin: when `hook_extra` identifies the package
as **ours**, an untrusted host or missing manifest is **refused**, not skipped. Consumers with no
updater of their own (FormFlow Lite — packages are handed to it by the license-server mu-plugin) have
no other control in the path.

### Third slice — sensitive-value primitives (`Peanut\FormCore\Crypto`)
`SensitiveValue::mask()` / `hash()` / `verifyHash()`, extracted from the copies in Pro and Lite.

Those copies had **diverged**: both repos independently found the same `substr($data, -0)` bug —
PHP treats `-0` as `0`, so a "reveal no trailing characters" mask returned the **entire value** —
and each shipped a *different* fix. One bug, found twice, fixed twice, with no guarantee the next
fix reaches both. That is the concrete cost of A6.

The shared version guards the `-0` trap explicitly, clamps negative windows to zero, and fails
toward *less* disclosure (a value too short to mask meaningfully is masked entirely rather than
partly revealed). Pinned by tests, including the leak case.

### Fourth slice — `Encryptor` (data at rest)
AES-256-CBC encrypt/decrypt + key derivation, extracted from the byte-identical copies in Pro and
Lite.

The constraint that shapes it: **existing ciphertext must keep decrypting.** Stored records were
written by the previous implementation, so the key derivation and wire format are reproduced
exactly — same cipher, same 16-byte IV prepended, same base64 envelope, same `substr(...,0,32)`
truncation (a configured key is TRUNCATED, only the salt fallback is HASHED; swapping which branch
hashes would invalidate every stored record).

Because "it round-trips itself" would not have caught that, the suite reproduces the **legacy
algorithm verbatim** and proves the two interchangeable in BOTH directions, across unicode,
multiline, exact-AES-block and 5KB payloads — so a partial rollout can't corrupt reads either.

> ⚠️ **Release requirement:** a consumer that registers `SignedUpdateGate` will refuse **every**
> subsequent update that lacks a valid `.manifest.json`. Its releases must therefore be published via
> `Peanut-meta/scripts/publish-plugin.sh`, which signs unconditionally. As of 2026-07-19 no
> `formflow-lite` release had ever been signed — verify the manifest ships before enabling the gate.


## Consuming (per the extraction plan)
`composer require peanut/formflow-core` via a private `vcs` repository entry; bundled into each
plugin's `vendor/` at build time (`scripts/publish-plugin.sh`). Lockstep-versioned with the consumers.

## Test
`composer install && vendor/bin/phpunit` — the tests pin behaviour both plugins relied on (WP is stubbed).

See `Peanut-meta/2026-07-05-formflow-shared-core-scoping.md` for the full plan + remaining modules.
