<?php
/**
 * Make an offline library pack from a terminal, straight onto a USB drive.
 *
 *   php pack-cli.php /media/usb/camara-sims.tar                 # all languages + the app
 *   php pack-cli.php --langs=en,am /media/usb/camara-sims.tar   # only English and Amharic
 *   php pack-cli.php --no-app /media/usb/camara-sims.tar        # sims only (server already has the app)
 *
 * Unpack on the new server:  sudo tar -xf camara-sims.tar -C /var/www/html/
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/pack.php';
set_time_limit(0);

$langs = cfg('locales');
$withApp = true;
$dest = null;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--langs=') === 0) {
        $langs = array_values(array_intersect(explode(',', substr($a, 8)), cfg('locales')));
    } elseif ($a === '--no-app') {
        $withApp = false;
    } elseif ($a[0] !== '-') {
        $dest = $a;
    }
}
if (!$dest) {
    fwrite(STDERR, "Usage: php pack-cli.php [--langs=en,am] [--no-app] /path/to/camara-sims.tar\n");
    exit(2);
}

$files = pack_files($langs, $withApp);
$size = tar_size($files);
echo 'Packing ' . count($files) . ' files (' . human_bytes($size) . ') for: ' . implode(', ', $langs) . "\n";

$fh = fopen($dest, 'wb');
if (!$fh) {
    fwrite(STDERR, "Cannot write to $dest\n");
    exit(2);
}
$written = 0;
$lastPct = -1;
stream_tar($files, function ($chunk) use ($fh, &$written, $size, &$lastPct) {
    fwrite($fh, $chunk);
    $written += strlen($chunk);
    $pct = (int)floor($written * 100 / $size);
    if ($pct !== $lastPct && $pct % 10 === 0) {
        echo "  $pct%\n";
        $lastPct = $pct;
    }
});
fclose($fh);
echo "Done: $dest\n";
echo "On the new server run:  sudo tar -xf " . basename($dest) . " -C /var/www/html/\n";
