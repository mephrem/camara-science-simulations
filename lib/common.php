<?php
/**
 * Shared helpers: settings, subject/grade taxonomy, local catalog.
 */

// Minimal fallbacks in case the php-mbstring extension isn't installed.
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s) { return strtolower($s); }
    function mb_strtoupper($s) { return strtoupper($s); }
    function mb_substr($s, $start, $len = null) {
        preg_match_all('/./us', (string)$s, $m);
        return implode('', array_slice($m[0], $start, $len));
    }
}

define('APP_DIR', dirname(__DIR__));
define('SIMS_DIR', APP_DIR . '/sims');
define('THUMBS_DIR', APP_DIR . '/sims/thumbs');
define('DATA_DIR', APP_DIR . '/data');
define('CATALOG_FILE', DATA_DIR . '/catalog.json');
define('LOG_FILE', DATA_DIR . '/update.log');
define('LOCK_FILE', DATA_DIR . '/update.lock');

function cfg(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require APP_DIR . '/config.php';
        // Per-server overrides (e.g. the real admin password), kept out of git.
        if (is_file(APP_DIR . '/config.local.php')) {
            $local = require APP_DIR . '/config.local.php';
            if (is_array($local)) {
                $cfg = array_replace($cfg, $local);
            }
        }
        // Allows testing against a different server.
        if (getenv('PHET_BASE')) {
            $cfg['phet_base'] = getenv('PHET_BASE');
        }
        $cfg['locales'] = array_values(array_filter(
            (array)($cfg['locales'] ?? ['en']),
            fn($l) => is_valid_locale($l)
        )) ?: ['en'];
        if (!in_array($cfg['default_locale'] ?? '', $cfg['locales'], true)) {
            $cfg['default_locale'] = $cfg['locales'][0];
        }
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_valid_sim_name($s): bool
{
    return is_string($s) && preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', $s) === 1;
}

function is_valid_locale($s): bool
{
    return is_string($s) && preg_match('/^[a-zA-Z]{2,3}(_[a-zA-Z0-9]{2,4})?$/', $s) === 1;
}

/**
 * Subject IDs as used by PhET's metadata service
 * (from phetsims/rosetta SimMetadataTypes.ts), grouped the way phet.colorado.edu shows them.
 */
function subject_tree(): array
{
    return [
        'physics' => ['name' => 'Physics', 'ids' => [4], 'children' => [
            5  => 'Motion',
            6  => 'Sound & Waves',
            7  => 'Work, Energy & Power',
            8  => 'Heat & Thermodynamics',
            9  => 'Quantum Phenomena',
            10 => 'Light & Radiation',
            11 => 'Electricity, Magnets & Circuits',
        ]],
        'chemistry' => ['name' => 'Chemistry', 'ids' => [13], 'children' => [
            19 => 'General Chemistry',
            20 => 'Quantum Chemistry',
        ]],
        'math' => ['name' => 'Math & Statistics', 'ids' => [15], 'children' => [
            30 => 'Math Concepts',
            31 => 'Math Applications',
        ]],
        'earth-space' => ['name' => 'Earth & Space', 'ids' => [14], 'children' => []],
        'biology'     => ['name' => 'Biology', 'ids' => [12], 'children' => []],
    ];
}

/** Map a list of PhET subject IDs to [top-level keys, sub-subject IDs]. */
function classify_subjects(array $ids): array
{
    $tops = [];
    $subs = [];
    foreach (subject_tree() as $key => $node) {
        foreach ($ids as $id) {
            $id = (int)$id;
            if (in_array($id, $node['ids'], true)) {
                $tops[$key] = true;
            }
            if (isset($node['children'][$id])) {
                $tops[$key] = true;
                $subs[$id] = true;
            }
        }
    }
    return [array_keys($tops), array_keys($subs)];
}

function grade_levels(): array
{
    return [
        0 => 'Elementary School',
        1 => 'Middle School',
        2 => 'High School',
        3 => 'University',
    ];
}

function locale_name(string $loc): string
{
    static $names = [
        'en' => 'English', 'am' => 'አማርኛ', 'om' => 'Afaan Oromoo', 'ti' => 'ትግርኛ',
        'so' => 'Soomaali', 'ar' => 'العربية', 'fr' => 'Français', 'es' => 'Español', 'sw' => 'Kiswahili',
        'pt' => 'Português', 'pt_BR' => 'Português (Brasil)', 'de' => 'Deutsch', 'zh_CN' => '中文 (简体)',
        'hi' => 'हिन्दी', 'ru' => 'Русский', 'tr' => 'Türkçe', 'it' => 'Italiano',
    ];
    return $names[$loc] ?? $loc;
}

function sim_file(string $sim, string $loc): string
{
    return SIMS_DIR . "/{$sim}_{$loc}.html";
}

function thumb_file(string $sim): string
{
    return THUMBS_DIR . "/{$sim}-600.png";
}

function load_catalog(): array
{
    $empty = ['updated' => null, 'checked' => null, 'sims' => []];
    if (!is_file(CATALOG_FILE)) {
        return $empty;
    }
    $data = json_decode((string)file_get_contents(CATALOG_FILE), true);
    return is_array($data) ? $data + $empty : $empty;
}

function save_catalog(array $catalog): void
{
    ensure_dirs();
    $tmp = CATALOG_FILE . '.tmp';
    file_put_contents($tmp, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, CATALOG_FILE);
}

function ensure_dirs(): void
{
    foreach ([SIMS_DIR, THUMBS_DIR, DATA_DIR] as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
    }
}

/** Title-case a sim name, e.g. "build-an-atom" -> "Build An Atom". */
function title_from_name(string $sim): string
{
    return ucwords(str_replace('-', ' ', $sim));
}

/**
 * Sims that are actually on disk, for the landing page.
 * Includes files copied in by hand that the updater hasn't seen yet.
 */
function local_library(): array
{
    $catalog = load_catalog();
    $out = [];
    $locales = cfg('locales');

    foreach ($catalog['sims'] as $sim => $info) {
        if (!is_valid_sim_name($sim)) {
            continue;
        }
        $files = [];
        foreach ($locales as $loc) {
            if (is_file(sim_file($sim, $loc))) {
                $files[$loc] = "sims/{$sim}_{$loc}.html";
            }
        }
        if (!$files) {
            continue;
        }
        $out[$sim] = $info + ['files' => []];
        $out[$sim]['files'] = $files;
    }

    // Files on disk the catalog doesn't know about yet (e.g. copied in manually).
    foreach (glob(SIMS_DIR . '/*.html') ?: [] as $path) {
        if (!preg_match('/^([a-z0-9-]+)_([a-zA-Z_0-9]+)\.html$/', basename($path), $m)) {
            continue;
        }
        [, $sim, $loc] = $m;
        if (!in_array($loc, $locales, true) || isset($out[$sim]['files'][$loc])) {
            continue;
        }
        if (!isset($out[$sim])) {
            $out[$sim] = [
                'title' => [$loc => title_from_name($sim)],
                'description' => [],
                'subjects' => [],
                'low' => null,
                'high' => null,
                'added' => filemtime($path),
                'files' => [],
            ];
        }
        $out[$sim]['files'][$loc] = "sims/{$sim}_{$loc}.html";
    }

    return $out;
}

function sim_title(array $info, string $loc, string $sim): string
{
    $t = $info['title'] ?? [];
    return $t[$loc] ?? $t['en'] ?? (reset($t) ?: title_from_name($sim));
}

function sim_description(array $info, string $loc): string
{
    $d = $info['description'] ?? [];
    return $d[$loc] ?? $d['en'] ?? '';
}

function human_time(?int $ts): string
{
    if (!$ts) {
        return 'never';
    }
    return date('j M Y, H:i', $ts);
}
