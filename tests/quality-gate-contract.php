<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$verifier = $root.'/scripts/verify-quality-gate.php';
$temp = sys_get_temp_dir().'/formflow-core-quality-'.bin2hex(random_bytes(6));

/** @return never */
function fail_quality_test(string $message): void
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

function remove_quality_fixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path.'/'.$entry;
        is_dir($child) ? remove_quality_fixture($child) : unlink($child);
    }

    rmdir($path);
}

function copy_quality_fixture(string $root, string $target): void
{
    remove_quality_fixture($target);
    mkdir($target.'/.github/workflows', 0777, true);
    mkdir($target.'/scripts', 0777, true);
    mkdir($target.'/tests', 0777, true);
    copy($root.'/.github/workflows/compatibility.yml', $target.'/.github/workflows/compatibility.yml');
    copy($root.'/composer.lock', $target.'/composer.lock');
    copy($root.'/phpunit.xml', $target.'/phpunit.xml');
    copy($root.'/scripts/run-composer-audit-transport.sh', $target.'/scripts/run-composer-audit-transport.sh');
    copy($root.'/tests/composer-audit-transport-contract.sh', $target.'/tests/composer-audit-transport-contract.sh');
    copy($root.'/tests/composer-audit-workflow-contract.sh', $target.'/tests/composer-audit-workflow-contract.sh');

    foreach (['PackageVerifierTest.php', 'SignedUpdateGateTest.php', 'EncryptorTest.php', 'SensitiveValueTest.php'] as $test) {
        copy($root.'/tests/'.$test, $target.'/tests/'.$test);
    }
}

function run_quality_verifier(string $verifier, string $target): int
{
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($verifier).' '.escapeshellarg($target);
    exec($command.' >/dev/null 2>&1', $output, $status);

    return $status;
}

register_shutdown_function(static fn () => remove_quality_fixture($temp));

if (run_quality_verifier($verifier, $root) !== 0) {
    fail_quality_test('baseline quality-gate contract failed');
}

copy_quality_fixture($root, $temp);
$workflow = (string) file_get_contents($temp.'/.github/workflows/compatibility.yml');
file_put_contents($temp.'/.github/workflows/compatibility.yml', str_replace("    tags: ['v*']\n", '', $workflow));
if (run_quality_verifier($verifier, $temp) === 0) {
    fail_quality_test('missing version-tag trigger was accepted');
}

copy_quality_fixture($root, $temp);
$workflow = (string) file_get_contents($temp.'/.github/workflows/compatibility.yml');
file_put_contents($temp.'/.github/workflows/compatibility.yml', str_replace('bash scripts/run-composer-audit-transport.sh', 'composer audit --locked', $workflow));
if (run_quality_verifier($verifier, $temp) === 0) {
    fail_quality_test('direct dependency-audit bypass was accepted');
}

copy_quality_fixture($root, $temp);
unlink($temp.'/scripts/run-composer-audit-transport.sh');
if (run_quality_verifier($verifier, $temp) === 0) {
    fail_quality_test('missing dependency-audit transport wrapper was accepted');
}

copy_quality_fixture($root, $temp);
unlink($temp.'/tests/SignedUpdateGateTest.php');
if (run_quality_verifier($verifier, $temp) === 0) {
    fail_quality_test('missing signed-update regression suite was accepted');
}

copy_quality_fixture($root, $temp);
$workflow = (string) file_get_contents($temp.'/.github/workflows/compatibility.yml');
file_put_contents($temp.'/.github/workflows/compatibility.yml', str_replace(
    'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1',
    'actions/checkout@v7',
    $workflow
));
if (run_quality_verifier($verifier, $temp) === 0) {
    fail_quality_test('mutable checkout action was accepted');
}

echo "Quality gate regression tests passed.\n";
