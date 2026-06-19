<?php

declare(strict_types=1);

final class ReadmeConverter
{
    private const META_FIELDS = [
        'Contributors',
        'Donate link',
        'Tags',
        'Requires at least',
        'Tested up to',
        'Stable tag',
        'Requires PHP',
        'Requires Plugins',
        'License',
        'License URI',
    ];

    private const VALID_BADGE_TYPES = ['stars', 'forks', 'watchers', 'last-commit', 'downloads'];
    private const VALID_BADGE_STYLES = ['flat', 'flat-square', 'plastic', 'for-the-badge', 'social'];

    private string $readmeTxt = 'readme.txt';
    private string $readmeMd = 'README.md';
    private string $assetsDir = 'assets';
    private string $content = '';
    private array $lines = [];
    private string $pluginName = 'WordPress Plugin';
    private array $metadata = [];
    private string $shortDescription = '';
    private string $body = '';
    private ?string $banner = null;
    private ?string $icon = null;
    private array $sections = [];
    private array $md = [];
    private array $include = [];
    private string $badgeStyle = 'flat';
    private ?string $repoOwner = null;
    private ?string $repoName = null;
    private ?string $pluginSlug = null;

    public static function main(): int
    {
        return (new self())->run();
    }

    public function run(): int
    {
        try {
            $this->configurePathsFromArgs();
            $this->ensureSourceExists();
            $this->loadContent();
            $this->parseHeader();
            if ($this->include !== []) {
                $this->resolveRepoInfo();
                if (in_array('downloads', $this->include, true)) {
                    $this->resolvePluginSlug();
                }
            }
            $this->collectAssets();
            $this->buildMarkdown();
            $this->writeReadme();
            echo "README.md generated successfully\n";
            return 0;
        } catch (RuntimeException $exception) {
            fwrite(STDERR, $exception->getMessage() . "\n");
            return 1;
        }
    }

    private function configurePathsFromArgs(): void
    {
        $args = $_SERVER['argv'] ?? [];
        array_shift($args);

        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, '--include=')) {
                $this->parseIncludeArg($arg);
                continue;
            }
            if ($arg === '--include') {
                throw new RuntimeException(
                    "--include requires a value. Allowed: " . implode(', ', self::VALID_BADGE_TYPES)
                );
            }
            if ($arg === '--badge-style' || str_starts_with($arg, '--badge-style=')) {
                $this->badgeStyle = $this->parseBadgeStyleArg($arg);
                continue;
            }
            if (str_starts_with($arg, '--source=')) {
                $this->readmeTxt = substr($arg, strlen('--source='));
                continue;
            }
            if (str_starts_with($arg, '--destination=')) {
                $this->readmeMd = substr($arg, strlen('--destination='));
                continue;
            }

            if ($index === 0) {
                $this->readmeTxt = $arg;
                continue;
            }
            if ($index === 1) {
                $this->readmeMd = $arg;
            }
        }
    }

    private function parseIncludeArg(string $arg): void
    {
        $value = substr($arg, strlen('--include='));
        $types = array_map('trim', explode(',', $value));
        $types = array_filter($types, static fn(string $t): bool => $t !== '');

        if ($types === []) {
            throw new RuntimeException(
                "--include requires a value. Allowed: " . implode(', ', self::VALID_BADGE_TYPES)
            );
        }

        foreach ($types as $type) {
            if (!in_array($type, self::VALID_BADGE_TYPES, true)) {
                throw new RuntimeException(
                    "Invalid badge type: '{$type}'. Allowed: " . implode(', ', self::VALID_BADGE_TYPES)
                );
            }
        }

        $this->include = array_values($types);
    }

    private function parseBadgeStyleArg(string $arg): string
    {
        if ($arg === '--badge-style') {
            throw new RuntimeException(
                "--badge-style requires a value. Allowed: " . implode(', ', self::VALID_BADGE_STYLES)
            );
        }
        $value = substr($arg, strpos($arg, '=') + 1);
        if (!in_array($value, self::VALID_BADGE_STYLES, true)) {
            throw new RuntimeException(
                "Invalid badge style: '{$value}'. Allowed: " . implode(', ', self::VALID_BADGE_STYLES)
            );
        }
        return $value;
    }

    private function ensureSourceExists(): void
    {
        if (!file_exists($this->readmeTxt)) {
            throw new RuntimeException('readme.txt not found');
        }
    }

    private function loadContent(): void
    {
        $content = file_get_contents($this->readmeTxt);
        if ($content === false) {
            throw new RuntimeException('Unable to read readme.txt');
        }

        $this->content = str_replace(["\r\n", "\r"], "\n", $content);
        $this->lines = explode("\n", $this->content);
    }

    private function parseHeader(): void
    {
        $this->pluginName = $this->extractPluginName();
        $bodyStart = $this->findBodyStart();
        $this->metadata = $this->extractMetadata($bodyStart);
        $this->shortDescription = $this->extractShortDescription($bodyStart);
        $this->body = implode("\n", array_slice($this->lines, $bodyStart));
        $this->sections = $this->extractSections($this->body);
    }

    private function extractPluginName(): string
    {
        $pattern = '/^===\s*(.*?)\s*===/m';
        if (preg_match($pattern, $this->content, $matches)) {
            return trim($matches[1]);
        }
        return $this->pluginName;
    }

    private function findBodyStart(): int
    {
        foreach ($this->lines as $index => $line) {
            if (preg_match('/^==\s+.+?\s+==$/', $line)) {
                return $index;
            }
        }
        return 0;
    }

    private function extractMetadata(int $bodyStart): array
    {
        $metadata = [];
        for ($index = 0; $index < $bodyStart; $index++) {
            $line = $this->lines[$index];
            if (strpos($line, ':') === false) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (in_array($key, self::META_FIELDS, true)) {
                $metadata[$key] = $value;
            }
        }
        return $metadata;
    }

    private function extractShortDescription(int $bodyStart): string
    {
        for ($index = 0; $index < $bodyStart; $index++) {
            $line = trim($this->lines[$index]);
            if ($line === '' || strpos($line, ':') !== false || str_starts_with($line, '===')) {
                continue;
            }
            return $line;
        }
        return '';
    }

    private function extractSections(string $body): array
    {
        $pattern = '/^==\s*(.*?)\s*==\s*$/m';
        preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE);

        $sections = [];
        $matchCount = count($matches[0]);
        for ($i = 0; $i < $matchCount; $i++) {
            $sectionName = trim($matches[1][$i][0]);
            $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $end = $i + 1 < $matchCount ? $matches[0][$i + 1][1] : strlen($body);
            $sectionContent = trim(substr($body, $start, $end - $start));
            $sections[] = [$sectionName, $sectionContent];
        }
        return $sections;
    }

    private function collectAssets(): void
    {
        if (!is_dir($this->assetsDir)) {
            return;
        }
        $files = array_values(array_diff(scandir($this->assetsDir), ['.', '..']));
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $lower = strtolower($file);
            if ($this->banner === null && str_starts_with($lower, 'banner-')) {
                $this->banner = $this->assetsDir . '/' . $file;
            }
            if ($this->icon === null && str_starts_with($lower, 'icon-')) {
                $this->icon = $this->assetsDir . '/' . $file;
            }
        }
    }

    private function resolveRepoInfo(): void
    {
        $repository = getenv('GITHUB_REPOSITORY');
        if ($repository !== false && strpos($repository, '/') !== false) {
            [$this->repoOwner, $this->repoName] = explode('/', $repository, 2);
            return;
        }

        $remote = $this->execCommand('git remote get-url origin');
        if ($remote !== null) {
            $remote = trim($remote);
            if (preg_match('#[:/]([^/]+)/([^/]+?)(?:\.git)?$#', $remote, $matches)) {
                $this->repoOwner = $matches[1];
                $this->repoName = $matches[2];
            }
        }
    }

    private function resolvePluginSlug(): void
    {
        if (!empty($this->metadata['Contributors'])) {
            $contributors = array_map('trim', explode(',', $this->metadata['Contributors']));
            if ($contributors !== []) {
                $this->pluginSlug = strtolower($contributors[0]);
                return;
            }
        }

        $slug = strtolower($this->pluginName);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        if ($slug !== '') {
            $this->pluginSlug = $slug;
        }
    }

    private function execCommand(string $command): ?string
    {
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0 || $output === []) {
            return null;
        }
        return $output[0];
    }

    private function buildMarkdown(): void
    {
        $this->md = [];
        $this->appendHero();
        $this->appendBadges();
        $this->appendMetadataTable();
        $this->appendSections();
    }

    private function appendHero(): void
    {
        if ($this->banner !== null) {
            $this->md[] = "![{$this->pluginName}]({$this->banner})";
            $this->md[] = '';
        } elseif ($this->icon !== null) {
            $this->md[] = "![{$this->pluginName}]({$this->icon})";
            $this->md[] = '';
        }
        $this->md[] = "# {$this->pluginName}";
        $this->md[] = '';
        if ($this->shortDescription !== '') {
            $this->md[] = $this->shortDescription;
            $this->md[] = '';
        }
    }

    private function appendBadges(): void
    {
        $badges = [];
        if (!empty($this->metadata['Requires at least'])) {
            $badges[] = '![WordPress](https://img.shields.io/badge/WordPress-' . rawurlencode($this->metadata['Requires at least']) . '%2B-blue)';
        }
        if (!empty($this->metadata['Requires PHP'])) {
            $badges[] = '![PHP](https://img.shields.io/badge/PHP-' . rawurlencode($this->metadata['Requires PHP']) . '%2B-777BB4)';
        }
        if (!empty($this->metadata['Tested up to'])) {
            $badges[] = '![Tested up to](https://img.shields.io/badge/Tested%20up%20to-' . rawurlencode($this->metadata['Tested up to']) . '-success)';
        }
        if (!empty($this->metadata['Stable tag'])) {
            $badges[] = '![Stable tag](https://img.shields.io/badge/Stable%20tag-' . rawurlencode($this->metadata['Stable tag']) . '-blueviolet)';
        }
        if (!empty($this->metadata['License'])) {
            $licenseSlug = str_replace([' ', '/'], ['%20', '%2F'], $this->metadata['License']);
            $badges[] = "![License](https://img.shields.io/badge/License-{$licenseSlug}-green)";
        }
        if ($badges !== []) {
            $this->md[] = implode(' ', $badges);
            $this->md[] = '';
        }
        if ($this->include !== []) {
            $dynamicBadges = [];
            $style = $this->badgeStyle;
            foreach ($this->include as $type) {
                $badge = $this->buildDynamicBadge($type, $style);
                if ($badge !== null) {
                    $dynamicBadges[] = $badge;
                }
            }
            if ($dynamicBadges !== []) {
                $this->md[] = implode(' ', $dynamicBadges);
                $this->md[] = '';
            }
        }
    }

    private function buildDynamicBadge(string $type, string $style): ?string
    {
        return match ($type) {
            'stars' => $this->repoOwner !== null
                ? "![Stars](https://img.shields.io/github/stars/" . rawurlencode($this->repoOwner) . '/' . rawurlencode($this->repoName) . "?style={$style})"
                : null,
            'forks' => $this->repoOwner !== null
                ? "![Forks](https://img.shields.io/github/forks/" . rawurlencode($this->repoOwner) . '/' . rawurlencode($this->repoName) . "?style={$style})"
                : null,
            'watchers' => $this->repoOwner !== null
                ? "![Watchers](https://img.shields.io/github/watchers/" . rawurlencode($this->repoOwner) . '/' . rawurlencode($this->repoName) . "?style={$style})"
                : null,
            'last-commit' => $this->repoOwner !== null
                ? "![Last Commit](https://img.shields.io/github/last-commit/" . rawurlencode($this->repoOwner) . '/' . rawurlencode($this->repoName) . "?style={$style})"
                : null,
            'downloads' => $this->pluginSlug !== null
                ? "![Downloads](https://img.shields.io/wordpress/plugin/downloads/" . rawurlencode($this->pluginSlug) . "?color=orange&style={$style})"
                : null,
            default => null,
        };
    }

    private function appendMetadataTable(): void
    {
        if ($this->metadata === []) {
            return;
        }
        $this->md[] = '| Property | Value |';
        $this->md[] = '|----------|-------|';
        foreach (self::META_FIELDS as $field) {
            if (!array_key_exists($field, $this->metadata)) {
                continue;
            }
            $value = $this->metadata[$field];
            if (in_array($field, ['Donate link', 'License URI'], true)) {
                $value = "[{$value}]({$value})";
            }
            $this->md[] = "| {$field} | {$value} |";
        }
        $this->md[] = '';
    }

    private function appendSections(): void
    {
        foreach ($this->sections as [$sectionName, $sectionContent]) {
            $normalized = strtolower($sectionName);
            if (in_array($normalized, ['frequently asked questions', 'faq'], true)) {
                $this->md[] = '## FAQ';
                $this->md[] = '';
                $this->md[] = $this->convertFaq($sectionContent);
                continue;
            }
            if ($normalized === 'screenshots') {
                $screenshots = $this->convertScreenshots($sectionContent);
                if ($screenshots !== '') {
                    $this->md[] = $screenshots;
                }
                continue;
            }
            $this->md[] = "## {$sectionName}";
            $this->md[] = '';
            $this->md[] = $this->convertGeneric($sectionContent);
            $this->md[] = '';
        }
    }

    private function convertGeneric(string $text): string
    {
        $lines = explode("\n", $text);
        foreach ($lines as $index => $line) {
            $lines[$index] = preg_replace('/^=\s*(.*?)\s*=$/', '### $1', $line);
        }
        return trim(implode("\n", $lines));
    }

    private function convertFaq(string $text): string
    {
        $pattern = '/=\s*(.*?)\s*=\s*\n(.*?)(?=\n=\s*.*?\s*=|\z)/s';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
        $output = [];
        foreach ($matches as $match) {
            $question = trim($match[1]);
            $answer = trim($match[2]);
            $output[] = '<details>';
            $output[] = '<summary>' . htmlspecialchars($question, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</summary>';
            $output[] = '';
            $output[] = $answer;
            $output[] = '';
            $output[] = '</details>';
            $output[] = '';
        }
        return implode("\n", $output);
    }

    private function convertScreenshots(string $text): string
    {
        $screenshots = [];
        foreach (explode("\n", $text) as $line) {
            if (!preg_match('/^\s*(\d+)\.\s*(.+)$/', $line, $match)) {
                continue;
            }
            $screenshots[] = [(int) $match[1], trim($match[2])];
        }
        if ($screenshots === []) {
            return '';
        }
        $output = ['## Screenshots', ''];
        foreach ($screenshots as [$number, $caption]) {
            $image = null;
            foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
                $candidate = $this->assetsDir . '/screenshot-' . $number . '.' . $ext;
                if (file_exists($candidate)) {
                    $image = $candidate;
                    break;
                }
            }
            $caption = str_replace('**', '', $caption);
            $parts = explode(':', $caption, 2);
            $title = trim($parts[0]);
            $description = $parts[1] ?? '';
            $output[] = "### {$title}";
            $output[] = '';
            if ($description !== '') {
                $output[] = trim($description);
                $output[] = '';
            }
            if ($image !== null) {
                $output[] = "![{$title}]({$image})";
                $output[] = '';
            }
        }
        return implode("\n", $output);
    }

    private function writeReadme(): void
    {
        file_put_contents($this->readmeMd, trim(implode("\n", $this->md)) . "\n");
    }
}

exit(ReadmeConverter::main());
