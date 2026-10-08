<?php

declare(strict_types=1);

namespace voku\AgentRecallCompiler;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reads what a task touches so a constraint can be matched on its subject.
 *
 * Returns the text of a file, or the concatenated text of the files below a
 * directory, or null whenever the content cannot be established cheaply and
 * safely: the path does not exist yet (a file the task is about to create), it
 * leaves the project root, or it is larger than the caps below. Callers treat
 * null as "unknown" and keep the constraint selected, so a limit here can never
 * hide a constraint.
 */
final readonly class TaskFileContentReader
{
    public const int MAX_FILE_BYTES = 2_097_152;

    public const int MAX_DIRECTORY_FILES = 400;

    public const int MAX_DIRECTORY_BYTES = 8_388_608;

    private string|false $root;

    public function __construct(string $projectRoot)
    {
        $this->root = realpath($projectRoot);
    }

    public function __invoke(string $path): ?string
    {
        if ($this->root === false) {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }

        $resolved = realpath($this->root . '/' . $relative);
        // realpath() resolves symlinks first, so a link pointing outside the root fails this check too.
        if ($resolved === false || !str_starts_with($resolved, $this->root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (is_file($resolved)) {
            return $this->readFile($resolved, self::MAX_FILE_BYTES);
        }

        return is_dir($resolved) ? $this->readDirectory($resolved) : null;
    }

    private function readFile(string $file, int $maxBytes): ?string
    {
        $size = filesize($file);
        if ($size === false || $size > $maxBytes) {
            return null;
        }

        $content = file_get_contents($file);

        return $content === false ? null : $content;
    }

    private function readDirectory(string $directory): ?string
    {
        $files = 0;
        $bytes = 0;
        $text = '';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->isLink()) {
                continue;
            }

            ++$files;
            $bytes += (int) $entry->getSize();
            if ($files > self::MAX_DIRECTORY_FILES || $bytes > self::MAX_DIRECTORY_BYTES) {
                return null;
            }

            $content = $this->readFile($entry->getPathname(), self::MAX_FILE_BYTES);
            if ($content === null) {
                return null;
            }

            $text .= $content . "\n";
        }

        return $text;
    }
}
