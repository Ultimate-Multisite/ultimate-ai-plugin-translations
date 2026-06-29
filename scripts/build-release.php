<?php
/**
 * Build the WordPress plugin release ZIP.
 *
 * Composer's archive command applies the package exclusion rules, then this
 * script wraps the archive contents in the plugin slug directory required by
 * WordPress plugin uploads.
 *
 * @package GratisAIPluginTranslations
 */

declare(strict_types=1);

const PLUGIN_SLUG = 'superdav-ai-plugin-translations';

$root_dir       = dirname(__DIR__);
$build_dir      = $root_dir . DIRECTORY_SEPARATOR . 'build';
$temporary_dir  = $build_dir . DIRECTORY_SEPARATOR . '.composer-archive';
$temporary_name = PLUGIN_SLUG . '-contents';
$temporary_zip  = $temporary_dir . DIRECTORY_SEPARATOR . $temporary_name . '.zip';
$release_zip    = $build_dir . DIRECTORY_SEPARATOR . PLUGIN_SLUG . '.zip';

if (!class_exists(ZipArchive::class)) {
    fail('The PHP zip extension is required to build the release archive.');
}

remove_path($temporary_dir);
ensure_directory($build_dir);
ensure_directory($temporary_dir);

if (is_file($release_zip) && !unlink($release_zip)) {
    fail('Unable to remove existing release archive: ' . relative_path($release_zip, $root_dir));
}

$composer_binary = getenv('COMPOSER_BINARY') ?: 'composer';
$command         = escapeshellarg($composer_binary)
    . ' archive --format=zip --dir=' . escapeshellarg($temporary_dir)
    . ' --file=' . escapeshellarg($temporary_name);

passthru($command, $exit_code);

if (0 !== $exit_code) {
    fail('Composer archive failed.');
}

if (!is_file($temporary_zip)) {
    fail('Composer archive did not create the expected file: ' . relative_path($temporary_zip, $root_dir));
}

wrap_archive_in_plugin_slug($temporary_zip, $release_zip);
remove_path($temporary_dir);

fwrite(STDOUT, 'Created: ' . relative_path($release_zip, $root_dir) . PHP_EOL);

/**
 * Wrap a flat Composer archive in the WordPress plugin slug directory.
 *
 * @param string $source_zip Source ZIP created by Composer archive.
 * @param string $target_zip Final WordPress upload ZIP.
 */
function wrap_archive_in_plugin_slug(string $source_zip, string $target_zip): void
{
    $source = new ZipArchive();
    if (true !== $source->open($source_zip)) {
        fail('Unable to open Composer archive.');
    }

    $target = new ZipArchive();
    if (true !== $target->open($target_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        $source->close();
        fail('Unable to create release archive.');
    }

    for ($index = 0; $index < $source->numFiles; $index++) {
        $stat = $source->statIndex($index);
        if (!is_array($stat) || !isset($stat['name']) || !is_string($stat['name'])) {
            $target->close();
            $source->close();
            fail('Unable to read Composer archive entry metadata.');
        }

        $entry_name = normalize_archive_path($stat['name']);
        if ('' === $entry_name || is_disallowed_archive_path($entry_name)) {
            continue;
        }

        $target_name = PLUGIN_SLUG . '/' . $entry_name;
        if (str_ends_with($entry_name, '/')) {
            $target->addEmptyDir(rtrim($target_name, '/'));
            continue;
        }

        $stream = $source->getStream($stat['name']);
        if (!is_resource($stream)) {
            $target->close();
            $source->close();
            fail('Unable to read Composer archive entry: ' . $entry_name);
        }

        $contents = stream_get_contents($stream);
        fclose($stream);

        if (false === $contents || !$target->addFromString($target_name, $contents)) {
            $target->close();
            $source->close();
            fail('Unable to add release archive entry: ' . $target_name);
        }
    }

    $target->close();
    $source->close();
}

/**
 * Normalize an archive entry path to a relative POSIX path.
 *
 * @param string $path Archive entry path.
 * @return string
 */
function normalize_archive_path(string $path): string
{
    return ltrim(str_replace('\\', '/', $path), '/');
}

/**
 * Check for paths that must never enter the release archive.
 *
 * @param string $path Normalized archive entry path.
 * @return bool
 */
function is_disallowed_archive_path(string $path): bool
{
    $path = trim($path, '/');

    return '.git' === $path || str_starts_with($path, '.git/');
}

/**
 * Ensure a directory exists.
 *
 * @param string $directory Directory path.
 */
function ensure_directory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
        fail('Unable to create directory: ' . $directory);
    }
}

/**
 * Remove a file or directory tree.
 *
 * @param string $path Path to remove.
 */
function remove_path(string $path): void
{
    if ('' === $path || DIRECTORY_SEPARATOR === $path) {
        fail('Refusing to remove an unsafe path.');
    }

    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        $entries = scandir($path);
        if (false === $entries) {
            fail('Unable to read directory for removal: ' . $path);
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            remove_path($path . DIRECTORY_SEPARATOR . $entry);
        }

        if (!rmdir($path)) {
            fail('Unable to remove directory: ' . $path);
        }

        return;
    }

    if (!unlink($path)) {
        fail('Unable to remove file: ' . $path);
    }
}

/**
 * Convert an absolute path to a project-relative path for output.
 *
 * @param string $path Project file path.
 * @param string $root Project root path.
 * @return string
 */
function relative_path(string $path, string $root): string
{
    $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if (str_starts_with($path, $root)) {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)));
    }

    return $path;
}

/**
 * Print an error and stop the build.
 *
 * @param string $message Error message.
 * @return never
 */
function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
