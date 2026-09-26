<?php
require_once __DIR__ . '/lib/common.php';

$locales = cfg('locales');
$lang = $_GET['lang'] ?? cfg('default_locale');
if (!in_array($lang, $locales, true)) {
    $lang = cfg('default_locale');
}

$library = local_library();
$tree = subject_tree();
$grades = grade_levels();
$gradeShort = [0 => 'Elementary', 1 => 'Middle', 2 => 'High School', 3 => 'University'];
$newCutoff = time() - 86400 * (int)cfg('new_days');
// Sims from the very first download aren't "new"; only ones added by later updates are.
$firstRun = (int)(load_catalog()['first_run'] ?? time());
$newCutoff = max($newCutoff, $firstRun + 6 * 3600);

$cards = [];
$subjectCounts = [];
$subCounts = [];
$gradeCounts = [];
foreach ($library as $sim => $info) {
    // Only list sims that exist in the chosen language.
    if (!isset($info['files'][$lang])) {
        continue;
    }
    [$tops, $subs] = classify_subjects($info['subjects'] ?? []);
    $fileLang = $lang;
    $low = $info['low'] ?? null;
    $high = $info['high'] ?? null;
    $gradeList = ($low !== null && $high !== null) ? range(min($low, $high), max($low, $high)) : [];
    foreach ($tops as $t) $subjectCounts[$t] = ($subjectCounts[$t] ?? 0) + 1;
    foreach ($subs as $s) $subCounts[$s] = ($subCounts[$s] ?? 0) + 1;
    foreach ($gradeList as $g) $gradeCounts[$g] = ($gradeCounts[$g] ?? 0) + 1;

    $gradeLabel = '';
    if ($gradeList) {
        $gradeLabel = $low === $high ? $gradeShort[$low] : $gradeShort[min($low, $high)] . ' – ' . $gradeShort[max($low, $high)];
    }
    $added = (int)($info['added'] ?? 0);
    $cards[] = [
        'sim' => $sim,
        'title' => sim_title($info, $fileLang, $sim),
        'desc' => sim_description($info, $fileLang),
        'href' => 'play.php?sim=' . rawurlencode($sim) . '&lang=' . rawurlencode($fileLang),
        'fileLang' => $fileLang,
        'thumb' => is_file(thumb_file($sim)) ? "sims/thumbs/{$sim}-600.png" : null,
        'tops' => $tops,
        'subs' => $subs,
        'grades' => $gradeList,
        'gradeLabel' => $gradeLabel,
        'isNew' => $added > $newCutoff,
        'added' => $added,
    ];
}
usort($cards, fn($a, $b) => strcasecmp($a['title'], $b['title']));
$hasUncategorized = (bool)array_filter($cards, fn($c) => !$c['tops']);

function initials(string $t): string
{
    $w = preg_split('/[\s:–-]+/u', $t, -1, PREG_SPLIT_NO_EMPTY);
    $s = mb_substr($w[0] ?? '', 0, 1) . mb_substr($w[1] ?? '', 0, 1);
    return mb_strtoupper($s);
}
?>
<!DOCTYPE html>
<html lang="<?= h(str_replace('_', '-', $lang)) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(cfg('site_title')) ?></title>
<link rel="icon" href="assets/camara-mark.png" type="image/png">
<link rel="stylesheet" href="assets/style.css?v=8">
</head>
<body>

<header class="top">
  <div class="top-inner">
    <a class="brand" href="./">
      <img class="logo" src="assets/camara-logo-white.png" alt="Camara Education Ethiopia" width="562" height="209">
      <span class="brand-text">
        <strong><?= h(cfg('site_title')) ?></strong>
        <small><?= h(cfg('site_subtitle')) ?></small>
      </span>
    </a>
    <div class="search">
      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
      <input id="q" type="search" placeholder="Search simulations…" autocomplete="off" aria-label="Search simulations">
    </div>
    <?php if (count($locales) > 1): ?>
    <form class="lang" method="get">
      <label for="lang" class="sr">Language</label>
      <select id="lang" name="lang" onchange="location.href = '?lang=' + encodeURIComponent(this.value) + location.hash">
        <?php foreach ($locales as $l): ?>
          <option value="<?= h($l) ?>" <?= $l === $lang ? 'selected' : '' ?>><?= h(locale_name($l)) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>
</header>

<?php if (!$cards && $library): ?>
<main class="empty-lib">
  <h1>No simulations in <?= h(locale_name($lang)) ?> yet</h1>
  <p>Choose another language from the menu above.</p>
</main>
<?php elseif (!$cards): ?>
<main class="empty-lib">
  <h1>No simulations yet</h1>
  <p>Open the <a href="admin.php">admin page</a> while connected to the internet and click <b>Update now</b>
     to download the simulations. Or copy <code>.html</code> sim files into the <code>sims</code> folder.</p>
</main>
<?php else: ?>

<div class="layout">
  <button class="filter-toggle" id="filterToggle" aria-expanded="false" aria-controls="filters">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18M6 12h12M10 19h4"/></svg>
    Filters <span id="filterCount" class="pill" hidden></span>
  </button>

  <aside class="filters" id="filters">
    <section>
      <h2>Subject</h2>
      <?php foreach ($tree as $key => $node): if (empty($subjectCounts[$key])) continue; ?>
        <div class="fgroup subj-<?= h($key) ?>">
          <label class="check top-check">
            <input type="checkbox" name="subject" value="<?= h($key) ?>">
            <span class="dot"></span><?= h($node['name']) ?>
            <em><?= (int)$subjectCounts[$key] ?></em>
          </label>
          <?php $kids = array_filter($node['children'], fn($id) => !empty($subCounts[$id]), ARRAY_FILTER_USE_KEY); ?>
          <?php if ($kids): ?>
          <div class="kids">
            <?php foreach ($kids as $id => $name): ?>
              <label class="check">
                <input type="checkbox" name="sub" value="<?= (int)$id ?>">
                <?= h($name) ?> <em><?= (int)$subCounts[$id] ?></em>
              </label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($hasUncategorized): ?>
        <label class="check top-check">
          <input type="checkbox" name="subject" value="none">
          <span class="dot"></span>Not yet sorted
        </label>
      <?php endif; ?>
    </section>

    <section>
      <h2>Grade level</h2>
      <?php foreach ($grades as $g => $name): if (empty($gradeCounts[$g])) continue; ?>
        <label class="check">
          <input type="checkbox" name="grade" value="<?= (int)$g ?>">
          <?= h($name) ?> <em><?= (int)$gradeCounts[$g] ?></em>
        </label>
      <?php endforeach; ?>
    </section>

    <button type="button" class="clear" id="clear">Clear all filters</button>
  </aside>

  <main class="results">
    <div class="bar">
      <p id="count" aria-live="polite"><?= count($cards) ?> simulations</p>
      <label class="sort">Sort
        <select id="sort">
          <option value="az">A – Z</option>
          <option value="new">Newest here</option>
        </select>
      </label>
    </div>

    <ul class="grid" id="grid">
      <?php foreach ($cards as $c): ?>
      <li class="card"
          data-title="<?= h(mb_strtolower($c['title'] . ' ' . $c['sim'] . ' ' . $c['desc'])) ?>"
          data-subjects="<?= h($c['tops'] ? implode(' ', $c['tops']) : 'none') ?>"
          data-subs="<?= h(implode(' ', $c['subs'])) ?>"
          data-grades="<?= h(implode(' ', $c['grades'])) ?>"
          data-added="<?= (int)$c['added'] ?>">
        <a href="<?= h($c['href']) ?>" title="<?= h($c['desc']) ?>">
          <div class="thumb subj-<?= h($c['tops'][0] ?? 'none') ?>">
            <?php if ($c['thumb']): ?>
              <img src="<?= h($c['thumb']) ?>" alt="" loading="lazy" decoding="async">
            <?php else: ?>
              <span class="initials"><?= h(initials($c['title'])) ?></span>
            <?php endif; ?>
            <?php if ($c['isNew']): ?><span class="badge new">New</span><?php endif; ?>
            <?php if ($c['fileLang'] !== $lang): ?><span class="badge lang"><?= h(strtoupper($c['fileLang'])) ?></span><?php endif; ?>
          </div>
          <div class="meta">
            <h3><?= h($c['title']) ?></h3>
            <?php if ($c['gradeLabel']): ?><p class="grade"><?= h($c['gradeLabel']) ?></p><?php endif; ?>
            <?php if ($c['tops']): ?>
            <p class="chips">
              <?php foreach ($c['tops'] as $t): ?><span class="chip subj-<?= h($t) ?>"><?= h($tree[$t]['name']) ?></span><?php endforeach; ?>
            </p>
            <?php endif; ?>
          </div>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>

    <div class="none" id="none" hidden>
      <p>No simulations match these filters.</p>
      <button type="button" class="clear" id="clear2">Clear all filters</button>
    </div>
  </main>
</div>
<?php endif; ?>

<footer class="foot">
  <p><?= h(cfg('site_title')) ?> · Camara Education Ethiopia</p>
  <p>Simulations by <b>PhET Interactive Simulations</b>, University of Colorado Boulder —
     phet.colorado.edu. Used under PhET's licensing terms.</p>
</footer>

<script src="assets/app.js?v=5"></script>
</body>
</html>
