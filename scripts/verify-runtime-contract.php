<?php

declare(strict_types=1);

$root = $argv[1] ?? dirname(__DIR__);

/** @return never */
function fail_contract(string $message): void
{
    fwrite(STDERR, "runtime contract: {$message}\n");
    exit(1);
}

/** @return array<string, mixed> */
function read_json_file(string $path): array
{
    if (!is_file($path)) {
        fail_contract('required file is missing: '.$path);
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        fail_contract('invalid JSON: '.$path);
    }

    return $decoded;
}

$contract = read_json_file($root.'/.peanut/runtime-contract.json');
$composer = read_json_file($root.'/composer.json');
$workflowPath = $root.'/.github/workflows/compatibility.yml';
$manifestPath = $root.'/.peanut/platform.yml';

if (!is_file($workflowPath) || !is_file($manifestPath)) {
    fail_contract('workflow or platform manifest is missing');
}

$workflow = (string) file_get_contents($workflowPath);
$manifest = (string) file_get_contents($manifestPath);

if (($contract['schema_version'] ?? null) !== 1) {
    fail_contract('unsupported schema version');
}

if (($composer['require']['php'] ?? null) !== ($contract['composer_php'] ?? null)) {
    fail_contract('Composer PHP constraint disagrees');
}

if (($composer['config']['platform']['php'] ?? null) !== ($contract['composer_platform_php'] ?? null)) {
    fail_contract('Composer resolver floor disagrees');
}

if (($composer['require-dev']['phpunit/phpunit'] ?? null) !== ($contract['phpunit'] ?? null)) {
    fail_contract('PHPUnit constraint disagrees');
}

$extensions = $contract['required_extensions'] ?? null;
if ($extensions !== ['ext-openssl', 'ext-sodium']) {
    fail_contract('required extension contract disagrees');
}

foreach ($extensions as $extension) {
    if (($composer['require'][$extension] ?? null) !== '*') {
        fail_contract("Composer does not require {$extension}");
    }

    $runtimeName = substr($extension, 4);
    if (!extension_loaded($runtimeName)) {
        fail_contract("runtime extension is unavailable: {$runtimeName}");
    }
}

$testedMinors = $contract['tested_php_minors'] ?? null;
$expectedMinors = ['8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];
if ($testedMinors !== $expectedMinors) {
    fail_contract('tested PHP minor contract disagrees');
}

$expectedMatrix = "php: ['8.0', '8.1', '8.2', '8.3', '8.4', '8.5']";
foreach ([
    $expectedMatrix,
    'php-version: ${{ matrix.php }}',
    'extensions: openssl, sodium',
    'composer validate --strict',
    'php scripts/verify-runtime-contract.php',
    'php tests/runtime-contract.php',
    'vendor/bin/phpunit',
] as $requiredWorkflowText) {
    if (!str_contains($workflow, $requiredWorkflowText)) {
        fail_contract('compatibility workflow disagrees: '.$requiredWorkflowText);
    }
}

foreach ([
    'PHP 8.0 through 8.5 with required OpenSSL and sodium extensions',
    'composer.lock resolves the PHP 8.0.0 development floor',
    'Required CI exercises PHP 8.0, 8.1, 8.2, 8.3, 8.4, and 8.5',
] as $requiredManifestText) {
    if (!str_contains($manifest, $requiredManifestText)) {
        fail_contract('platform manifest disagrees: '.$requiredManifestText);
    }
}

echo 'Runtime contract verified on PHP '.PHP_VERSION.".\n";
