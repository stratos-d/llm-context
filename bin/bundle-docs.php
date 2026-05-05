<?php

declare(strict_types=1);

final class DocsBundler
{
    /**
     * @param list<string> $preferredFiles
     * @param list<string> $orderedDocsFiles
     * @param list<string> $allMarkdownFiles
     * @return list<string>
     */
    public function orderedFiles(
        array $preferredFiles,
        array $orderedDocsFiles,
        array $allMarkdownFiles,
    ): array {
        $orderedFiles = [];

        foreach ([$preferredFiles, $orderedDocsFiles, $allMarkdownFiles] as $fileGroup) {
            foreach ($fileGroup as $file) {
                if (! isset($orderedFiles[$file])) {
                    $orderedFiles[$file] = true;
                }
            }
        }

        return array_keys($orderedFiles);
    }

    public function bundle(string $rootPath, string $outputPath): void
    {
        $normalizedOutputPath = $this->normalizePath($outputPath);
        $sourceFiles = $this->discoverSourceFiles($rootPath, $normalizedOutputPath);
        $absoluteOutputPath = $rootPath . DIRECTORY_SEPARATOR . $normalizedOutputPath;
        $outputDirectory = dirname($absoluteOutputPath);

        if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0777, true) && ! is_dir($outputDirectory)) {
            throw new RuntimeException(sprintf('Unable to create output directory [%s].', $outputDirectory));
        }

        $bundle = $this->renderBundle($sourceFiles, $rootPath, $normalizedOutputPath);

        if (file_put_contents($absoluteOutputPath, $bundle) === false) {
            throw new RuntimeException(sprintf('Unable to write bundle to [%s].', $normalizedOutputPath));
        }

        fwrite(
            STDOUT,
            sprintf(
                "Bundled %d markdown files into %s\n",
                count($sourceFiles),
                $normalizedOutputPath,
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function discoverSourceFiles(string $rootPath, string $outputPath): array
    {
        $allMarkdownFiles = $this->discoverAllMarkdownFiles($rootPath, $outputPath);
        $preferredFiles = array_values(array_filter([
            'README.md',
            'AGENTS.md',
            'CLAUDE.md',
            'docs/README.md',
        ], static fn (string $path): bool => is_file($rootPath . DIRECTORY_SEPARATOR . $path)));

        return $this->orderedFiles(
            $preferredFiles,
            $this->discoverDocsReadmeFiles($rootPath),
            $allMarkdownFiles,
        );
    }

    /**
     * @return list<string>
     */
    private function discoverAllMarkdownFiles(string $rootPath, string $outputPath): array
    {
        $markdownFiles = [];
        $directoryIterator = new RecursiveDirectoryIterator(
            $rootPath,
            FilesystemIterator::SKIP_DOTS,
        );

        $filterIterator = new RecursiveCallbackFilterIterator(
            $directoryIterator,
            function (SplFileInfo $file, string $path): bool {
                if ($file->isDir()) {
                    return ! in_array($file->getFilename(), ['.git', '.idea', 'build', 'vendor', 'node_modules'], true);
                }

                return true;
            },
        );

        $iterator = new RecursiveIteratorIterator($filterIterator);

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }

            $relativePath = $this->relativePath($rootPath, $file->getPathname());

            if ($relativePath === $outputPath) {
                continue;
            }

            $markdownFiles[] = $relativePath;
        }

        sort($markdownFiles);

        return $markdownFiles;
    }

    /**
     * @return list<string>
     */
    private function discoverDocsReadmeFiles(string $rootPath): array
    {
        $docsReadmePath = $rootPath . DIRECTORY_SEPARATOR . 'docs/README.md';

        if (! is_file($docsReadmePath)) {
            return [];
        }

        $docsReadme = file_get_contents($docsReadmePath);

        if ($docsReadme === false) {
            throw new RuntimeException('Unable to read docs/README.md.');
        }

        preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', $docsReadme, $matches);

        $orderedFiles = [];

        foreach ($matches[1] as $target) {
            if (! is_string($target)) {
                continue;
            }

            $path = $this->extractPathFromLink($target);

            if ($path === '' || ! str_ends_with($path, '.md')) {
                continue;
            }

            $resolvedPath = $this->resolveRelativePath('docs', $path);

            if (is_file($rootPath . DIRECTORY_SEPARATOR . $resolvedPath) && ! isset($orderedFiles[$resolvedPath])) {
                $orderedFiles[$resolvedPath] = true;
            }
        }

        return array_keys($orderedFiles);
    }

    /**
     * @param list<string> $sourceFiles
     */
    private function renderBundle(array $sourceFiles, string $rootPath, string $outputPath): string
    {
        $sections = [
            '# Documentation Bundle',
            '',
            'This file is generated by `composer docs:bundle`. Do not edit it directly.',
            '',
            '## Included files',
            '',
        ];

        foreach ($sourceFiles as $sourceFile) {
            $sections[] = sprintf('- `%s`', $sourceFile);
        }

        foreach ($sourceFiles as $sourceFile) {
            $contents = file_get_contents($rootPath . DIRECTORY_SEPARATOR . $sourceFile);

            if ($contents === false) {
                throw new RuntimeException(sprintf('Unable to read [%s].', $sourceFile));
            }

            $sections[] = '';
            $sections[] = '---';
            $sections[] = '';
            $sections[] = sprintf('## Source: `%s`', $sourceFile);
            $sections[] = '';
            $sections[] = rtrim($this->rewriteLocalLinks($contents, $sourceFile, $outputPath));
        }

        $sections[] = '';

        return implode("\n", $sections);
    }

    private function rewriteLocalLinks(string $contents, string $sourceFile, string $outputPath): string
    {
        $sourceDirectory = dirname($sourceFile);
        $outputDirectory = dirname($outputPath);

        return preg_replace_callback(
            '/(?<!!)\[([^\]]+)\]\(([^)]+)\)/',
            function (array $matches) use ($sourceDirectory, $outputDirectory): string {
                $target = $matches[2];

                if ($this->isExternalLink($target)) {
                    return $matches[0];
                }

                [$path, $fragment] = $this->splitLinkTarget($target);

                if ($path === '') {
                    return $matches[0];
                }

                $resolvedPath = $this->resolveRelativePath($sourceDirectory, $path);
                $rebasedPath = $this->rebasePath($outputDirectory, $resolvedPath);

                if ($fragment !== '') {
                    $rebasedPath .= '#' . $fragment;
                }

                return sprintf('[%s](%s)', $matches[1], $rebasedPath);
            },
            $contents,
        ) ?? $contents;
    }

    private function isExternalLink(string $target): bool
    {
        return str_starts_with($target, '#')
            || preg_match('/^[a-z]+:/i', $target) === 1;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitLinkTarget(string $target): array
    {
        $segments = explode('#', $target, 2);

        return [
            $segments[0],
            $segments[1] ?? '',
        ];
    }

    private function resolveRelativePath(string $sourceDirectory, string $path): string
    {
        if (str_starts_with($path, '/')) {
            return ltrim($this->normalizePath($path), '/');
        }

        if ($sourceDirectory === '.' || $sourceDirectory === '') {
            return $this->normalizePath($path);
        }

        return $this->normalizePath($sourceDirectory . '/' . $path);
    }

    private function rebasePath(string $fromDirectory, string $toPath): string
    {
        $fromParts = $this->pathSegments($fromDirectory);
        $toParts = $this->pathSegments($toPath);

        while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        $relativeParts = array_merge(array_fill(0, count($fromParts), '..'), $toParts);

        if ($relativeParts === []) {
            return '.';
        }

        return implode('/', $relativeParts);
    }

    /**
     * @return list<string>
     */
    private function pathSegments(string $path): array
    {
        $normalizedPath = trim($this->normalizePath($path), '/.');

        if ($normalizedPath === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $normalizedPath), static fn (string $segment): bool => $segment !== ''));
    }

    private function extractPathFromLink(string $target): string
    {
        return $this->splitLinkTarget(trim($target))[0];
    }

    private function normalizePath(string $path): string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $segments = [];

        foreach (explode('/', $normalizedPath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function relativePath(string $basePath, string $targetPath): string
    {
        return $this->rebasePath($basePath, $targetPath);
    }
}

$rootPath = dirname(__DIR__);
$outputPath = $argv[1] ?? 'build/llm-context-docs.md';
$bundler = new DocsBundler();

try {
    $bundler->bundle($rootPath, $outputPath);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . "\n");

    exit(1);
}
