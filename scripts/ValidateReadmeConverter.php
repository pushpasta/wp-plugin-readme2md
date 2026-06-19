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

$destinationPath = $dummyDir . DIRECTORY_SEPARATOR . 'README.md';
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

foreach (['Stars', 'Forks', 'Watchers', 'Last Commit', 'Downloads'] as $badge) {
    if (strpos($output, "![{$badge}](") !== false) {
        fwrite(STDERR, "{$badge} badge should not appear without --include\n");
        cleanup($dummyDir);
        exit(1);
    }
}

putenv('GITHUB_REPOSITORY=test-owner/test-repo');

$allBadgesPath = $dummyDir . DIRECTORY_SEPARATOR . 'README-all-badges.md';
exec('php ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($sourcePath) . ' ' . escapeshellarg($allBadgesPath) . ' --include=stars,forks,watchers,last-commit,downloads --badge-style=flat-square 2>&1', $runOutput, $status);
if ($status !== 0) {
    fwrite(STDERR, "Script execution with all badges failed:\n" . implode("\n", $runOutput) . "\n");
    cleanup($dummyDir);
    exit(1);
}

$allBadgesOutput = file_get_contents($allBadgesPath);
if ($allBadgesOutput === false) {
    fwrite(STDERR, "Could not read generated README.md with all badges\n");
    cleanup($dummyDir);
    exit(1);
}

$expectedBadges = [
    '![Stars](https://img.shields.io/github/stars/test-owner/test-repo?style=flat-square)',
    '![Forks](https://img.shields.io/github/forks/test-owner/test-repo?style=flat-square)',
    '![Watchers](https://img.shields.io/github/watchers/test-owner/test-repo?style=flat-square)',
    '![Last Commit](https://img.shields.io/github/last-commit/test-owner/test-repo?style=flat-square)',
    '![Downloads](https://img.shields.io/wordpress/plugin/downloads/test?color=orange&style=flat-square)',
];
foreach ($expectedBadges as $badge) {
    if (strpos($allBadgesOutput, $badge) === false) {
        fwrite(STDERR, "Missing expected badge: {$badge}\n");
        cleanup($dummyDir);
        exit(1);
    }
}

$orderPath = $dummyDir . DIRECTORY_SEPARATOR . 'README-order.md';
exec('php ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($sourcePath) . ' ' . escapeshellarg($orderPath) . ' --include=downloads,stars 2>&1', $runOutput, $status);
if ($status !== 0) {
    fwrite(STDERR, "Script execution with order test failed:\n" . implode("\n", $runOutput) . "\n");
    cleanup($dummyDir);
    exit(1);
}

$orderOutput = file_get_contents($orderPath);
$downloadsPos = strpos($orderOutput, '![Downloads](');
$starsPos = strpos($orderOutput, '![Stars](');
if ($downloadsPos === false || $starsPos === false || $downloadsPos > $starsPos) {
    fwrite(STDERR, "Badge order should follow --include order (downloads before stars)\n");
    cleanup($dummyDir);
    exit(1);
}

$defaultStylePath = $dummyDir . DIRECTORY_SEPARATOR . 'README-default-style.md';
exec('php ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($sourcePath) . ' ' . escapeshellarg($defaultStylePath) . ' --include=stars 2>&1', $runOutput, $status);
if ($status !== 0) {
    fwrite(STDERR, "Script execution with default style failed:\n" . implode("\n", $runOutput) . "\n");
    cleanup($dummyDir);
    exit(1);
}

$defaultStyleOutput = file_get_contents($defaultStylePath);
if (strpos($defaultStyleOutput, 'style=flat)') === false) {
    fwrite(STDERR, "Default badge style should be flat\n");
    cleanup($dummyDir);
    exit(1);
}

$invalidTypePath = $dummyDir . DIRECTORY_SEPARATOR . 'README-invalid.md';
exec('php ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($sourcePath) . ' ' . escapeshellarg($invalidTypePath) . ' --include=invalid-badge 2>&1', $runOutput, $status);
if ($status === 0) {
    fwrite(STDERR, "Script should fail with --include=invalid-badge\n");
    cleanup($dummyDir);
    exit(1);
}

$emptyIncludePath = $dummyDir . DIRECTORY_SEPARATOR . 'README-empty.md';
exec('php ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($sourcePath) . ' ' . escapeshellarg($emptyIncludePath) . ' --include= 2>&1', $runOutput, $status);
if ($status === 0) {
    fwrite(STDERR, "Script should fail with --include= (empty)\n");
    cleanup($dummyDir);
    exit(1);
}

$invalidStylePath = $dummyDir . DIRECTORY_SEPARATOR . 'README-invalid-style.md';
exec('php ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($sourcePath) . ' ' . escapeshellarg($invalidStylePath) . ' --include=stars --badge-style=rounded 2>&1', $runOutput, $status);
if ($status === 0) {
    fwrite(STDERR, "Script should fail with --badge-style=rounded\n");
    cleanup($dummyDir);
    exit(1);
}

putenv('GITHUB_REPOSITORY');

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
