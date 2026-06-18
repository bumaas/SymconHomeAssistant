<?php

declare(strict_types=1);

trait HABundlePathTrait
{
    private function getDefaultBundleFixturesPath(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures';
    }

    private function resolveBundlePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if ($this->isAbsoluteFilesystemPath($path)) {
            return $path;
        }

        return $this->getDefaultBundleFixturesPath() . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
    }

    private function isAbsoluteFilesystemPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return true;
        }

        if (str_starts_with($path, '\\\\') || str_starts_with($path, '//')) {
            return true;
        }

        return str_starts_with($path, '/') || str_starts_with($path, '\\');
    }
}
