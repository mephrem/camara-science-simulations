<?php
/**
 * Checks phet.colorado.edu for new/updated HTML5 sims and downloads them.
 * Used by admin.php (from the browser) and update-cli.php (from cron / terminal).
 */

require_once __DIR__ . '/common.php';

class PhetUpdater
{
    /** @var callable */
    private $logger;
    private $logHandle = null;

    public function __construct(?callable $logger = null, bool $writeLogFile = false)
    {
        $this->logger = $logger ?? function ($msg) {};
        ensure_dirs();
        if ($writeLogFile) {
            $this->logHandle = @fopen(LOG_FILE, 'w');
        }
    }

    public function __destruct()
    {
        if ($this->logHandle) {
            fclose($this->logHandle);
        }
    }

    public function log(string $msg): void
    {
        $line = '[' . date('H:i:s') . '] ' . $msg;
        ($this->logger)($line);
        if ($this->logHandle) {
            fwrite($this->logHandle, $line . "\n");
            fflush($this->logHandle);
        }
    }

    /* ------------------------------------------------------------ HTTP */

    /**
     * GET a URL. If $dest is given the body is written to that file.
     * Returns [httpStatus, body|null, errorMessage|null].
     */
    public static function httpGet(string $url, ?string $dest = null, int $timeout = 60): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $fh = null;
            $opts = [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_USERAGENT      => 'PhET-Offline-Library/1.0 (school offline mirror)',
                CURLOPT_FAILONERROR    => false,
            ];
            if ($dest) {
                $fh = fopen($dest, 'wb');
                $opts[CURLOPT_FILE] = $fh;
            } else {
                $opts[CURLOPT_RETURNTRANSFER] = true;
            }
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_errno($ch) ? curl_error($ch) : null;
            curl_close($ch);
            if ($fh) {
                fclose($fh);
            }
            return [$status, $dest ? null : ($body === false ? null : $body), $err];
        }

        // Fallback without the curl extension.
        $ctx = stream_context_create(['http' => [
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'user_agent' => 'PhET-Offline-Library/1.0 (school offline mirror)',
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $hdr) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hdr, $m)) {
                $status = (int)$m[1];
            }
        }
        if ($body === false) {
            $err = error_get_last()['message'] ?? 'request failed';
            return [$status, null, $err];
        }
        if ($dest) {
            file_put_contents($dest, $body);
            return [$status, null, null];
        }
        return [$status, $body, null];
    }

    /* -------------------------------------------------------- Metadata */

    private function metadataUrl(string $locale): string
    {
        return rtrim(cfg('phet_base'), '/')
            . '/services/metadata/1.3/simulations?format=json&type=html&locale=' . rawurlencode($locale);
    }

    /**
     * Fetch the list of published HTML5 sims from PhET.
     * Returns [simName => [title, description, subjects, low, high, isNew, version, locales[]]].
     */
    public function fetchRemote(): array
    {
        $wanted = cfg('locales');
        $fetch = array_values(array_unique(array_merge(['en'], $wanted)));
        $remote = [];

        foreach ($fetch as $loc) {
            $this->log("Fetching sim list from PhET ($loc)…");
            [$status, $body, $err] = self::httpGet($this->metadataUrl($loc), null, 120);
            if ($err || $status !== 200 || !$body) {
                throw new RuntimeException(
                    "Could not reach PhET (" . ($err ?: "HTTP $status") . "). Is this computer connected to the internet?"
                );
            }
            $json = json_decode($body, true);
            unset($body);
            if (!is_array($json) || !isset($json['projects']) || !is_array($json['projects'])) {
                throw new RuntimeException('PhET returned data in an unexpected format.');
            }

            foreach ($json['projects'] as $p) {
                $pname = (string)($p['name'] ?? '');
                if (strpos($pname, 'html/') !== 0) {
                    continue;
                }
                $name = substr($pname, 5);
                if (!is_valid_sim_name($name)) {
                    continue;
                }
                $s = null;
                foreach ($p['simulations'] ?? [] as $cand) {
                    if (($cand['name'] ?? '') === $name) {
                        $s = $cand;
                        break;
                    }
                }
                $s = $s ?? ($p['simulations'][0] ?? null);
                if (!is_array($s)) {
                    continue;
                }
                if (!empty($s['isPrototype']) || (array_key_exists('visible', $s) && !$s['visible'])) {
                    continue;
                }
                $version = (string)($p['version']['string'] ?? '');
                if ($version === '') {
                    continue;
                }

                $ls = $s['localizedSimulations'] ?? [];
                if (!isset($remote[$name])) {
                    $remote[$name] = [
                        'title' => [],
                        'description' => [],
                        'subjects' => array_values(array_map('intval', (array)($s['subjects'] ?? []))),
                        'low' => isset($s['lowGradeLevel']) ? (int)$s['lowGradeLevel'] : null,
                        'high' => isset($s['highGradeLevel']) ? (int)$s['highGradeLevel'] : null,
                        'isNew' => !empty($s['isNew']),
                        'version' => $version,
                        'locales' => [],
                    ];
                }
                if (isset($ls[$loc]) && is_array($ls[$loc])) {
                    $title = trim((string)($ls[$loc]['title'] ?? ''));
                    if ($title !== '') {
                        $remote[$name]['title'][$loc] = $title;
                    }
                    $desc = trim(html_entity_decode(strip_tags((string)($ls[$loc]['description'] ?? '')), ENT_QUOTES, 'UTF-8'));
                    if ($desc !== '') {
                        $remote[$name]['description'][$loc] = $desc;
                    }
                    if (in_array($loc, $wanted, true) && translation_ok($remote[$name]['title'], $loc)) {
                        $remote[$name]['locales'][$loc] = true;
                    }
                }
            }
            unset($json);
        }

        foreach ($remote as &$r) {
            $r['locales'] = array_keys($r['locales']);
        }
        unset($r);
        ksort($remote);

        if (!$remote) {
            throw new RuntimeException('PhET returned an empty sim list; nothing was changed.');
        }
        $this->log('PhET lists ' . count($remote) . ' HTML5 sims.');
        return $remote;
    }

    /* ------------------------------------------------------------ Plan */

    /**
     * Compare PhET's list with what's on disk.
     * Returns ['remote' => ..., 'new' => [...], 'update' => [...], 'adopt' => [...], 'thumbs' => [...]].
     * Each item in new/update/adopt: ['sim', 'locale', 'title', 'version', 'old'].
     */
    public function plan(?array $remote = null): array
    {
        $remote = $remote ?? $this->fetchRemote();
        $catalog = load_catalog();
        $plan = ['remote' => $remote, 'new' => [], 'update' => [], 'adopt' => [], 'thumbs' => [], 'remove' => []];

        foreach ($remote as $sim => $r) {
            foreach ($r['locales'] as $loc) {
                $have = is_file(sim_file($sim, $loc)) && filesize(sim_file($sim, $loc)) > 0;
                $old = $catalog['sims'][$sim]['versions'][$loc] ?? null;
                $item = [
                    'sim' => $sim,
                    'locale' => $loc,
                    'title' => $r['title'][$loc] ?? $r['title']['en'] ?? title_from_name($sim),
                    'version' => $r['version'],
                    'old' => $old,
                ];
                if (!$have) {
                    $plan['new'][] = $item;
                } elseif ($old === null) {
                    // File was copied in by hand (e.g. from the earlier download script):
                    // keep it and record the current version instead of downloading again.
                    $plan['adopt'][] = $item;
                } elseif ($old !== $r['version']) {
                    $plan['update'][] = $item;
                }
            }
            // Files we downloaded earlier for a language the sim isn't really translated into.
            foreach (cfg('locales') as $loc) {
                if ($loc !== 'en' && !in_array($loc, $r['locales'], true)
                    && isset($catalog['sims'][$sim]['versions'][$loc]) && is_file(sim_file($sim, $loc))) {
                    $plan['remove'][] = ['sim' => $sim, 'locale' => $loc, 'title' => $r['title'][$loc] ?? $sim];
                }
            }
            if (cfg('download_thumbnails') && $r['locales'] && !is_file(thumb_file($sim))) {
                $plan['thumbs'][] = $sim;
            }
        }

        $catalog['checked'] = time();
        $catalog['pending'] = count($plan['new']) + count($plan['update']);
        save_catalog($catalog);
        return $plan;
    }

    /* ------------------------------------------------------------- Run */

    /** Check, then download everything new or changed. Returns a summary. */
    public function run(): array
    {
        $lock = fopen(LOCK_FILE, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('An update is already running.');
        }

        try {
            $plan = $this->plan();
            $remote = $plan['remote'];
            $this->log(sprintf(
                'To do: %d new, %d updated, %d already here, %d pictures.',
                count($plan['new']), count($plan['update']), count($plan['adopt']), count($plan['thumbs'])
            ));

            $catalog = load_catalog();
            $now = time();
            // Everything in the first download is "the library"; only later additions get a New badge.
            $catalog['first_run'] = $catalog['first_run'] ?? $now;

            // Refresh titles/subjects/grades for every sim PhET lists.
            foreach ($remote as $sim => $r) {
                $prev = $catalog['sims'][$sim] ?? [];
                $catalog['sims'][$sim] = [
                    'title' => $r['title'],
                    'description' => $r['description'],
                    'subjects' => $r['subjects'],
                    'low' => $r['low'],
                    'high' => $r['high'],
                    'isNew' => $r['isNew'],
                    'latest' => $r['version'],
                    'versions' => $prev['versions'] ?? [],
                    'added' => $prev['added'] ?? null,
                ];
            }

            foreach ($plan['remove'] as $it) {
                @unlink(sim_file($it['sim'], $it['locale']));
                unset($catalog['sims'][$it['sim']]['versions'][$it['locale']]);
                $this->log("Removed {$it['sim']} [{$it['locale']}]: not really translated yet.");
            }
            foreach ($plan['adopt'] as $it) {
                $catalog['sims'][$it['sim']]['versions'][$it['locale']] = $it['version'];
                $catalog['sims'][$it['sim']]['added'] = $catalog['sims'][$it['sim']]['added']
                    ?? filemtime(sim_file($it['sim'], $it['locale']));
            }
            save_catalog($catalog);

            $ok = 0;
            $failed = [];
            $jobs = array_merge($plan['new'], $plan['update']);
            $total = count($jobs);
            foreach ($jobs as $i => $it) {
                $label = sprintf('(%d/%d) %s [%s]', $i + 1, $total, $it['title'], $it['locale']);
                $this->log($label . ($it['old'] ? " {$it['old']} → {$it['version']}" : ' new') . ' …');
                $err = $this->downloadSim($it['sim'], $it['locale']);
                if ($err) {
                    $failed[] = $it + ['error' => $err];
                    $this->log("   ✗ failed: $err");
                    continue;
                }
                $ok++;
                $catalog['sims'][$it['sim']]['versions'][$it['locale']] = $it['version'];
                $catalog['sims'][$it['sim']]['added'] = $catalog['sims'][$it['sim']]['added'] ?? $now;
                save_catalog($catalog); // save progress after every sim
                $this->log('   ✓ done');
            }

            $thumbsOk = 0;
            foreach ($plan['thumbs'] as $sim) {
                if ($this->downloadThumb($sim) === null) {
                    $thumbsOk++;
                }
            }
            if ($plan['thumbs']) {
                $this->log("Pictures: $thumbsOk of " . count($plan['thumbs']) . ' downloaded.');
            }

            $catalog['updated'] = time();
            $catalog['checked'] = time();
            $catalog['pending'] = count($failed);
            save_catalog($catalog);

            $this->log(sprintf('Finished: %d downloaded, %d failed.', $ok, count($failed)));
            return ['downloaded' => $ok, 'failed' => $failed, 'adopted' => count($plan['adopt']), 'thumbs' => $thumbsOk];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function isRunning(): bool
    {
        if (!is_file(LOCK_FILE)) {
            return false;
        }
        $fh = fopen(LOCK_FILE, 'c');
        if (!$fh) {
            return false;
        }
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        return !$free;
    }

    /** Download one sim to a temp file, check it, then swap it in. Returns error string or null. */
    private function downloadSim(string $sim, string $loc): ?string
    {
        $url = rtrim(cfg('phet_base'), '/') . "/sims/html/{$sim}/latest/{$sim}_{$loc}.html";
        $dest = sim_file($sim, $loc);
        $tmp = $dest . '.part';
        [$status, , $err] = self::httpGet($url, $tmp, (int)cfg('download_timeout'));
        if ($err || $status !== 200) {
            @unlink($tmp);
            return $err ?: "HTTP $status";
        }
        $size = filesize($tmp);
        $head = (string)file_get_contents($tmp, false, null, 0, 2048);
        if ($size < 10000 || stripos($head, '<html') === false) {
            @unlink($tmp);
            return 'downloaded file does not look like a simulation';
        }
        if (!rename($tmp, $dest)) {
            @unlink($tmp);
            return 'could not write to sims folder (check permissions)';
        }
        @chmod($dest, 0664);
        return null;
    }

    private function downloadThumb(string $sim): ?string
    {
        $url = rtrim(cfg('phet_base'), '/') . "/sims/html/{$sim}/latest/{$sim}-600.png";
        $dest = thumb_file($sim);
        $tmp = $dest . '.part';
        [$status, , $err] = self::httpGet($url, $tmp, 60);
        $head = is_file($tmp) ? (string)file_get_contents($tmp, false, null, 0, 8) : '';
        if ($err || $status !== 200 || $head !== "\x89PNG\r\n\x1a\n") {
            @unlink($tmp);
            return $err ?: "HTTP $status";
        }
        rename($tmp, $dest);
        @chmod($dest, 0664);
        return null;
    }
}
