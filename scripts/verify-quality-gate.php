<?php

declare(strict_types=1);

$root = $argv[1] ?? dirname(__DIR__);

/** @return never */
function fail_quality_contract(string $message): void
{
    fwrite(STDERR, "quality gate contract: {$message}\n");
    exit(1);
}

$workflowPath = $root.'/.github/workflows/compatibility.yml';
$lockPath = $root.'/composer.lock';
$phpunitPath = $root.'/phpunit.xml';

foreach ([$workflowPath, $lockPath, $phpunitPath] as $requiredFile) {
    if (!is_file($requiredFile)) {
        fail_quality_contract('required file is missing: '.$requiredFile);
    }
}

$workflow = (string) file_get_contents($workflowPath);
$phpunit = (string) file_get_contents($phpunitPath);

foreach ([
    'pull_request:',
    'branches: [main]',
    "tags: ['v*']",
    'permissions:',
    'contents: read',
    'cancel-in-progress: true',
    "php: ['8.0', '8.1', '8.2', '8.3', '8.4', '8.5']",
    'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1',
    'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240',
    'composer validate --strict',
    'php scripts/verify-runtime-contract.php',
    'php tests/runtime-contract.php',
    'php scripts/verify-quality-gate.php',
    'php tests/quality-gate-contract.php',
    'composer install --no-interaction --prefer-dist',
    "find src tests scripts -name '*.php' -print0 | xargs -0 -n1 php -l",
    'vendor/bin/phpunit',
    'composer audit --locked',
] as $requiredWorkflowText) {
    if (!str_contains($workflow, $requiredWorkflowText)) {
        fail_quality_contract('compatibility workflow disagrees: '.$requiredWorkflowText);
    }
}

if (!str_contains($phpunit, '<directory>tests</directory>')) {
    fail_quality_contract('PHPUnit does not execute the complete tests directory');
}

foreach ([
    'tests/PackageVerifierTest.php',
    'tests/SignedUpdateGateTest.php',
    'tests/EncryptorTest.php',
    'tests/SensitiveValueTest.php',
] as $securityTest) {
    if (!is_file($root.'/'.$securityTest)) {
        fail_quality_contract('security regression suite is missing: '.$securityTest);
    }
}

echo "Quality gate contract verified.\n";
