<?php

namespace App\Services\Production;

use RuntimeException;
use ZipArchive;

/**
 * Extracts a production-package ZIP safely: no absolute paths, no "..", no symlinks, limited entry
 * count and uncompressed size, allow-listed extensions only. Returns files in memory-free form
 * (paths in a fresh temp dir) for classification.
 */
class SecureArchiveExtractor
{
    public const ALLOWED = ['md', 'txt', 'srt', 'json', 'mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac', 'png', 'jpg', 'jpeg', 'webp'];

    /** @return list<array{name: string, path: string, ext: string}> */
    public function extract(string $zipPath, string $targetDir): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The archive could not be opened.');
        }
        $maxEntries = (int) config('motionforge.uploads.archive_max_entries', 40);
        $maxBytes = (int) config('motionforge.uploads.archive_max_uncompressed_kb', 409600) * 1024;
        if ($zip->numFiles > $maxEntries * 3) {
            throw new RuntimeException("The archive has too many entries ({$zip->numFiles}).");
        }
        if (! is_dir($targetDir) && ! mkdir($targetDir, 0700, true)) {
            throw new RuntimeException('Could not create the extraction directory.');
        }
        $total = 0; $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            if (str_ends_with($name, '/') || str_starts_with(basename($name), '.') || str_contains($name, '__MACOSX')) continue;
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('/^[a-z]:/i', $name) || str_contains($name, "\0")) {
                throw new RuntimeException("Unsafe path in archive: {$name}");
            }
            $zip->getExternalAttributesIndex($i, $os, $attr);
            if ($os === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                throw new RuntimeException("Symbolic links are not allowed in archives: {$name}");
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (! in_array($ext, self::ALLOWED, true)) continue;
            $total += $stat['size'];
            if ($total > $maxBytes) throw new RuntimeException('The archive expands beyond the allowed size.');
            if (count($files) >= $maxEntries) throw new RuntimeException('The archive contains too many files.');
            $dest = $targetDir.'/'.count($files).'_'.preg_replace('/[^A-Za-z0-9._-]/', '_', basename($name));
            $in = $zip->getStream($name);
            if (! $in) continue;
            $out = fopen($dest, 'wb'); $written = 0;
            while (! feof($in)) {
                $chunk = fread($in, 1 << 16);
                $written += strlen($chunk);
                if ($written > $stat['size'] + 1024) { fclose($out); fclose($in); throw new RuntimeException('Archive entry size mismatch.'); }
                fwrite($out, $chunk);
            }
            fclose($out); fclose($in);
            $files[] = ['name' => $name, 'path' => $dest, 'ext' => $ext];
        }
        $zip->close();

        return $files;
    }
}
