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

/* ---------------------------------------------------------------- Page labels */

/** Choose the language for page labels (the same as the sims' language). */
function set_ui_lang(string $loc): void
{
    $GLOBALS['__ui_lang'] = $loc;
}

function ui_strings(): array
{
    static $cache = [];
    $loc = $GLOBALS['__ui_lang'] ?? 'en';
    if (!isset($cache[$loc])) {
        $en = require APP_DIR . '/lang/en.php';
        $file = APP_DIR . '/lang/' . $loc . '.php';
        $tr = (is_valid_locale($loc) && $loc !== 'en' && is_file($file)) ? (require $file) : [];
        // Missing labels fall back to English, including inside the grades/subjects lists.
        foreach (['grades', 'subjects'] as $k) {
            $tr[$k] = ($tr[$k] ?? []) + $en[$k];
        }
        $cache[$loc] = $tr + $en;
    }
    return $cache[$loc];
}

/** A translated label, with {placeholders} filled in. */
function t(string $key, array $vars = []): string
{
    $s = (string)(ui_strings()[$key] ?? $key);
    foreach ($vars as $k => $v) {
        $s = str_replace('{' . $k . '}', (string)$v, $s);
    }
    return $s;
}

function subject_name($key): string
{
    return ui_strings()['subjects'][$key] ?? (string)$key;
}

/**
 * Grade bands. PhET's four levels line up with Ethiopia's 6-2-4 school structure:
 * elementary = primary (1–6), middle = middle (7–8), high = secondary (9–12), university.
 */
function grade_levels(): array
{
    return ui_strings()['grades'];
}

/** Short label for a card, e.g. "Grades 7–12" or "Grade 9 – University". */
function grade_label(?int $low, ?int $high): string
{
    if ($low === null || $high === null) {
        return '';
    }
    [$low, $high] = [min($low, $high), max($low, $high)];
    $first = [0 => 1, 1 => 7, 2 => 9];
    $last = [0 => 6, 1 => 8, 2 => 12];
    if ($low >= 3) {
        return t('university');
    }
    if ($high >= 3) {
        return t('grades_to_univ', ['a' => $first[$low]]);
    }
    return t('grades_range', ['a' => $first[$low], 'b' => $last[$high]]);
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

/**
 * Script a language is written in, for languages that don't use Latin letters.
 * Used to spot "translations" that are really English (or another language) left in place.
 */
function locale_script(string $loc): ?string
{
    static $map = [
        'am' => 'Ethiopic', 'ti' => 'Ethiopic',
        'ar' => 'Arabic', 'fa' => 'Arabic', 'ur' => 'Arabic', 'ps' => 'Arabic', 'ku' => 'Arabic',
        'he' => 'Hebrew', 'el' => 'Greek', 'th' => 'Thai', 'ko' => 'Hangul', 'ka' => 'Georgian',
        'hy' => 'Armenian', 'bn' => 'Bengali', 'ta' => 'Tamil', 'te' => 'Telugu', 'kn' => 'Kannada',
        'ml' => 'Malayalam', 'gu' => 'Gujarati', 'pa' => 'Gurmukhi', 'si' => 'Sinhala', 'km' => 'Khmer',
        'lo' => 'Lao', 'my' => 'Myanmar', 'hi' => 'Devanagari', 'mr' => 'Devanagari', 'ne' => 'Devanagari',
        'ru' => 'Cyrillic', 'uk' => 'Cyrillic', 'bg' => 'Cyrillic', 'mk' => 'Cyrillic', 'mn' => 'Cyrillic',
        'kk' => 'Cyrillic', 'be' => 'Cyrillic', 'zh_CN' => 'Han', 'zh_TW' => 'Han', 'ja' => 'Han',
    ];
    return $map[$loc] ?? null;
}

/**
 * Is this sim genuinely translated into $loc?
 * PhET lists a sim under a language as soon as a translation is started, so we also require
 * the title to be translated (different from English) and, for non-Latin languages,
 * written in that language's script.
 */
function translation_ok(array $titles, string $loc): bool
{
    if ($loc === 'en') {
        return true;
    }
    $norm = fn($s) => mb_strtolower(trim(preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\s]+/u', ' ', (string)$s)));
    $t = $norm($titles[$loc] ?? '');
    if ($t === '' || $t === $norm($titles['en'] ?? '')) {
        return false;
    }
    $script = locale_script($loc);
    if ($script === 'Han') {
        return preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $t) === 1;
    }
    return $script === null || preg_match('/\p{' . $script . '}/u', $t) === 1;
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
            if (is_file(sim_file($sim, $loc)) && translation_ok($info['title'] ?? [], $loc)) {
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
        // Skip sims the catalog already knows (it decided above whether this language counts).
        if (!in_array($loc, $locales, true) || isset($catalog['sims'][$sim]) || isset($out[$sim]['files'][$loc])) {
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

/* ---------------------------------------------------------------- Usage counts */

define('USAGE_FILE', DATA_DIR . '/usage.json');

/**
 * Count one opening of a sim. Stores only sim name, language and month; no personal data.
 * Never breaks the page: if the data folder isn't writable, it silently does nothing.
 */
function record_usage(string $sim, string $lang): void
{
    $fh = @fopen(USAGE_FILE, 'c+');
    if (!$fh) {
        return;
    }
    if (flock($fh, LOCK_EX)) {
        $raw = stream_get_contents($fh);
        $u = json_decode($raw ?: '', true);
        if (!is_array($u)) {
            $u = ['since' => time(), 'total' => 0, 'sims' => [], 'langs' => [], 'months' => []];
        }
        $month = date('Y-m');
        $u['total'] = ($u['total'] ?? 0) + 1;
        $u['sims'][$sim][$lang] = ($u['sims'][$sim][$lang] ?? 0) + 1;
        $u['langs'][$lang] = ($u['langs'][$lang] ?? 0) + 1;
        $u['months'][$month] = ($u['months'][$month] ?? 0) + 1;
        $u['last'] = time();
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($u, JSON_UNESCAPED_SLASHES));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

function load_usage(): array
{
    $u = is_file(USAGE_FILE) ? json_decode((string)file_get_contents(USAGE_FILE), true) : null;
    return is_array($u) ? $u + ['since' => null, 'total' => 0, 'sims' => [], 'langs' => [], 'months' => [], 'last' => null]
        : ['since' => null, 'total' => 0, 'sims' => [], 'langs' => [], 'months' => [], 'last' => null];
}
