<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Http\FormRequest;

final readonly class SourceScan
{
    /**
     * @param  array<int, string>  $patterns  regex without delimiters
     * @param  array<int, string>  $dirs  project-relative dirs to scan
     * @param  array<int, string>  $exclude  project-relative path prefixes to skip
     * @return array<int, string> "file:line" hits
     */
    public static function findInProject(
        array $patterns,
        array $dirs = ['app', 'routes'],
        array $exclude = [],
    ): array {
        $hits = [];

        foreach ($dirs as $dir) {
            foreach (self::phpFiles($dir) as $file) {
                $relative = self::relative($file);

                foreach ($exclude as $prefix) {
                    if (str_starts_with($relative, $prefix)) {
                        continue 2;
                    }
                }

                foreach ($patterns as $pattern) {
                    $hits = [...$hits, ...self::scanString((string) file_get_contents($file), $pattern, $relative)];
                }
            }
        }

        return $hits;
    }

    /**
     * @param  array<int, string>  $dirs
     * @return array<int, string>
     */
    public static function projectPhpFiles(array $dirs): array
    {
        $files = [];

        foreach ($dirs as $dir) {
            $files = [...$files, ...self::phpFiles($dir)];
        }

        return $files;
    }

    /**
     * @param  array<int, string>  $patterns
     * @return array<int, string>
     */
    public static function findInDirectory(string $dir, array $patterns): array
    {
        return self::findInProject($patterns, [$dir]);
    }

    /**
     * @return array<int, string>
     */
    public static function scanString(string $content, string $pattern, ?string $file = null): array
    {
        $hits = [];
        $lines = explode("\n", $content);

        foreach ($lines as $number => $line) {
            if (preg_match('/'.$pattern.'/', $line) === 1) {
                $hits[] = ($file ?? 'string').':'.($number + 1);
            }
        }

        return $hits;
    }

    public static function methodUsesFormRequest(\ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType
                && class_exists((string) $type->getName())
                && is_a((string) $type->getName(), FormRequest::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private static function phpFiles(string $dir): array
    {
        $root = self::root().'/'.$dir;

        if (! is_dir($root)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function relative(string $absolute): string
    {
        return ltrim(str_replace(self::root(), '', $absolute), '/');
    }
}
