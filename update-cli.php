<?php
/**
 * Update the library from a terminal or cron.
 *
 *   sudo -u www-data php /var/www/html/phet/update-cli.php          # download new/updated sims
 *   sudo -u www-data php /var/www/html/phet/update-cli.php --check  # only list what's new
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/lib/updater.php';
@ini_set('memory_limit', '512M');
set_time_limit(0);

$checkOnly = in_array('--check', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$up = new PhetUpdater(function ($line) use ($quiet) {
    if (!$quiet) {
        echo $line, PHP_EOL;
    }
}, !$checkOnly);

try {
    if ($checkOnly) {
        $plan = $up->plan();
        echo count($plan['new']), " new, ", count($plan['update']), " updated.\n";
        foreach (['new' => 'NEW', 'update' => 'UPDATED'] as $k => $label) {
            foreach ($plan[$k] as $i) {
                echo "  $label  {$i['title']} [{$i['locale']}] " . ($i['old'] ? "{$i['old']} -> " : '') . "{$i['version']}\n";
            }
        }
        exit(0);
    }
    $r = $up->run();
    exit($r['failed'] ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
