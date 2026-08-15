<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$verifier = $root.'/scripts/verify-runtime-contract.php';
$temp = sys_get_temp_dir().'/formflow-core-runtime-'.bin2hex(random_bytes(6));

/** @return never */
function fail_test(string $message): void
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

function remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path.'/'.$entry;
        is_dir($child) ? remove_tree($child) : unlink($child);
    }

    rmdir($path);
}

function copy_fixture(string $root, string $target): void
{
    remove_tree($target);
    mkdir($target.'/.peanut', 0777, true);
    mkdir($target.'/.github/workflows', 0777, true);
    copy($root.'/.peanut/runtime-contract.json', $target.'/.peanut/runtime-contract.json');
    copy($root.'/.peanut/platform.yml', $target.'/.peanut/platform.yml');
    copy($root.'/.github/workflows/compatibility.yml', $target.'/.github/workflows/compatibility.yml');
    copy($root.'/composer.json', $target.'/composer.json');
}

function run_verifier(string $verifier, string $target): int
{
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($verifier).' '.escapeshellarg($target);
    exec($command.' >/dev/null 2>&1', $output, $status);

    return $status;
}

register_shutdown_function(static fn () => remove_tree($temp));

if (run_verifier($verifier, $root) !== 0) {
    fail_test('baseline runtime contract failed');
}

copy_fixture($root, $temp);
$composer = json_decode((string) file_get_contents($temp.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
unset($composer['require']['ext-sodium']);
file_put_contents($temp.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
if (run_verifier($verifier, $temp) === 0) {
    fail_test('missing sodium declaration was accepted');
}

copy_fixture($root, $temp);
$workflow = (string) file_get_contents($temp.'/.github/workflows/compatibility.yml');
file_put_contents($temp.'/.github/workflows/compatibility.yml', str_replace("'8.0', ", '', $workflow));
if (run_verifier($verifier, $temp) === 0) {
    fail_test('incomplete PHP matrix was accepted');
}

copy_fixture($root, $temp);
$manifest = (string) file_get_contents($temp.'/.peanut/platform.yml');
file_put_contents($temp.'/.peanut/platform.yml', str_replace('PHP 8.0 through 8.5', 'PHP 8.1 through 8.5', $manifest));
if (run_verifier($verifier, $temp) === 0) {
    fail_test('manifest runtime drift was accepted');
}

echo "Runtime contract regression tests passed.\n";
