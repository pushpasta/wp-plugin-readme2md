<?php

$scriptPath = realpath(__DIR__ . '/../scripts/ReadmeConverter.php');
if ($scriptPath === false) {
    fwrite(STDERR, "Could not locate scripts/ReadmeConverter.php\n");
    exit(1);
}

$dummyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wp_readme_convert_test_' . uniqid();
if (!mkdir($dummyDir, 0700, true) && !is_dir($dummyDir)) {
    fwrite(STDERR, "Could not create temporary directory: {$dummyDir}\n");
    exit(1);
}

$sourcePath = $dummyDir . DIRECTORY_SEPARATOR . 'readme.txt';
$destinationPath = $dummyDir . DIRECTORY_SEPARATOR . 'README.md';
$readmeContents = <<<TXT
=== Test Plugin ===
Contributors: test
Donate link: https://example.com
Tags: featured
Requires at least: 6.0
Tested up to: 6.4
Stable tag: 1.2.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
A test plugin description.
TXT;

file_put_contents($sourcePath, $readmeContents);

$status = 0;
exec('php -l ' . escapeshellarg($scriptPath) . ' 2>&1', $syntaxOutput, $status);
if ($status !== 0) {
    fwrite(STDERR, "Syntax check failed:\n" . implode("\n", $syntaxOutput) . "\n");
    cleanup($dummyDir);
    exit(1);
}

exec('php ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($sourcePath) . ' ' . escapeshellarg($destinationPath) . ' 2>&1', $runOutput, $status);
if ($status !== 0) {
    fwrite(STDERR, "Script execution failed:\n" . implode("\n", $runOutput) . "\n");
    cleanup($dummyDir);
    exit(1);
}

if (!file_exists($destinationPath)) {
    fwrite(STDERR, "Expected output file not created: {$destinationPath}\n");
    cleanup($dummyDir);
    exit(1);
}

$output = file_get_contents($destinationPath);
if ($output === false) {
    fwrite(STDERR, "Could not read generated README.md\n");
    cleanup($dummyDir);
    exit(1);
}

if (strpos($output, '# Test Plugin') === false) {
    fwrite(STDERR, "Generated README.md is missing the plugin title\n");
    cleanup($dummyDir);
    exit(1);
}

if (strpos($output, 'A test plugin description.') === false) {
    fwrite(STDERR, "Generated README.md is missing the plugin description\n");
    cleanup($dummyDir);
    exit(1);
}

if (strpos($output, '![Stable tag](') === false) {
    fwrite(STDERR, "Generated README.md is missing the Stable tag badge\n");
    cleanup($dummyDir);
    exit(1);
}

fwrite(STDOUT, "OK\n");
cleanup($dummyDir);
exit(0);

function cleanup(string $dir): void
{
    $files = glob($dir . DIRECTORY_SEPARATOR . '*');
    if ($files !== false) {
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    if (is_dir($dir)) {
        rmdir($dir);
    }
}
