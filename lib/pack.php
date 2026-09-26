<?php
/**
 * Builds an offline "library pack": one .tar file with the downloaded sims, pictures and catalog
 * (and optionally the app itself), to copy to another server by USB drive.
 *
 * Unpack on Ubuntu:   sudo tar -xf camara-sims.tar -C /var/www/html/
 * Unpack on Windows:  tar -xf camara-sims.tar -C C:\laragon\www\
 * Everything is stored under "phet/", so it lands in the phet folder.
 */

require_once __DIR__ . '/common.php';

const PACK_ROOT = 'phet/';

/** Sizes of the downloaded sims per language: [loc => ['files' => n, 'bytes' => n]]. */
function library_sizes(): array
{
    $out = [];
    foreach (glob(SIMS_DIR . '/*.html') ?: [] as $path) {
        if (preg_match('/_([a-zA-Z_0-9]+)\.html$/', basename($path), $m)) {
            $out[$m[1]]['files'] = ($out[$m[1]]['files'] ?? 0) + 1;
            $out[$m[1]]['bytes'] = ($out[$m[1]]['bytes'] ?? 0) + filesize($path);
        }
    }
    ksort($out);
    return $out;
}

function thumbs_size(): int
{
    $n = 0;
    foreach (glob(THUMBS_DIR . '/*.png') ?: [] as $p) {
        $n += filesize($p);
    }
    return $n;
}

function human_bytes(int $b): string
{
    if ($b >= 1073741824) return round($b / 1073741824, 1) . ' GB';
    if ($b >= 1048576) return round($b / 1048576) . ' MB';
    if ($b >= 1024) return round($b / 1024) . ' KB';
    return $b . ' B';
}

/**
 * List of files for the pack: [pathInArchive => pathOnDisk].
 * $locales: languages to include. $withApp: also include the app's own files.
 */
function pack_files(array $locales, bool $withApp): array
{
    $files = [];
    foreach ($locales as $loc) {
        if (!is_valid_locale($loc)) {
            continue;
        }
        foreach (glob(SIMS_DIR . '/*_' . $loc . '.html') ?: [] as $p) {
            $files[PACK_ROOT . 'sims/' . basename($p)] = $p;
        }
    }
    foreach (glob(THUMBS_DIR . '/*.png') ?: [] as $p) {
        $files[PACK_ROOT . 'sims/thumbs/' . basename($p)] = $p;
    }
    if (is_file(CATALOG_FILE)) {
        $files[PACK_ROOT . 'data/catalog.json'] = CATALOG_FILE;
    }

    if ($withApp) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_DIR, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(APP_DIR) + 1));
            // Skip content (handled above), runtime data, per-server secrets and git internals.
            if (preg_match('#^(sims/|data/(?!\.htaccess$)|\.git/|config\.local\.php$)#', $rel)) {
                continue;
            }
            $files[PACK_ROOT . $rel] = $f->getPathname();
        }
        $files[PACK_ROOT . 'sims/thumbs/.keep'] = null; // empty marker so the folder exists
    }
    ksort($files);
    return $files;
}

/** Exact size of the tar that stream_tar() will produce. */
function tar_size(array $files): int
{
    $total = 1024; // two empty end-of-archive blocks
    foreach ($files as $disk) {
        $size = $disk === null ? 0 : filesize($disk);
        $total += 512 + (int)ceil($size / 512) * 512;
    }
    return $total;
}

function tar_header(string $name, int $size, int $mtime): string
{
    $prefix = '';
    if (strlen($name) > 100) { // ustar: split long paths into prefix + name
        $cut = strrpos(substr($name, 0, 155), '/');
        $prefix = substr($name, 0, $cut);
        $name = substr($name, $cut + 1);
    }
    $h = str_pad($name, 100, "\0")
        . str_pad('0000644', 7, '0', STR_PAD_LEFT) . "\0"
        . "0000000\0" . "0000000\0"
        . str_pad(decoct($size), 11, '0', STR_PAD_LEFT) . "\0"
        . str_pad(decoct($mtime), 11, '0', STR_PAD_LEFT) . "\0"
        . '        '                       // checksum placeholder
        . '0'                              // regular file
        . str_repeat("\0", 100)            // link name
        . "ustar\0" . '00'
        . str_pad('root', 32, "\0") . str_pad('root', 32, "\0")
        . str_repeat("\0", 16)             // dev major/minor
        . str_pad($prefix, 155, "\0");
    $h = str_pad($h, 512, "\0");
    $sum = 0;
    for ($i = 0; $i < 512; $i++) {
        $sum += ord($h[$i]);
    }
    return substr_replace($h, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);
}

/** Write a tar of $files through $out (a callable taking a string chunk). */
function stream_tar(array $files, callable $out): void
{
    foreach ($files as $name => $disk) {
        $size = $disk === null ? 0 : filesize($disk);
        $out(tar_header($name, $size, $disk === null ? time() : filemtime($disk)));
        if ($size > 0) {
            $fh = fopen($disk, 'rb');
            while (!feof($fh)) {
                $out(fread($fh, 1048576));
            }
            fclose($fh);
            $pad = (512 - $size % 512) % 512;
            if ($pad) {
                $out(str_repeat("\0", $pad));
            }
        }
    }
    $out(str_repeat("\0", 1024));
}
