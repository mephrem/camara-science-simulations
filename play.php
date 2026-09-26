<?php
/**
 * Plays one simulation in the same tab, under a slim bar with a way back to the library.
 *   play.php?sim=build-an-atom&lang=am
 */
require_once __DIR__ . '/lib/common.php';

$locales = cfg('locales');
$sim = (string)($_GET['sim'] ?? '');
$lang = (string)($_GET['lang'] ?? cfg('default_locale'));
if (!in_array($lang, $locales, true)) {
    $lang = cfg('default_locale');
}
set_ui_lang($lang);

$library = local_library();
$info = is_valid_sim_name($sim) ? ($library[$sim] ?? null) : null;
if ($info && !isset($info['files'][$lang])) {
    // Not available in this language: fall back to English if we have it.
    $lang = isset($info['files']['en']) ? 'en' : (string)array_key_first($info['files']);
}
$backUrl = './' . ($lang !== cfg('default_locale') ? '?lang=' . rawurlencode($lang) : '');

if (!$info) {
    http_response_code(404);
} else {
    // Count this opening (sim + language + month only; ignores reloads within 30 minutes).
    $seen = 'seen_' . substr(md5($sim . '|' . $lang), 0, 10);
    if (empty($_COOKIE[$seen])) {
        record_usage($sim, $lang);
        setcookie($seen, '1', ['expires' => time() + 1800, 'path' => '/', 'samesite' => 'Lax']);
    }
}
$title = $info ? sim_title($info, $lang, $sim) : t('not_found');
?>
<!DOCTYPE html>
<html lang="<?= h(str_replace('_', '-', $lang)) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — <?= h(cfg('site_title')) ?></title>
<link rel="icon" href="assets/camara-mark.png" type="image/png">
<link rel="stylesheet" href="assets/style.css?v=10">
</head>
<body class="player">

<header class="player-bar">
  <a class="back" id="back" href="<?= h($backUrl) ?>">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>
    <span><?= h(t('all_sims')) ?></span>
  </a>
  <h1 title="<?= h($title) ?>"><?= h($title) ?></h1>
  <?php if ($info): ?>
  <button type="button" class="fs" id="fs" title="<?= h(t('full_screen')) ?>" aria-label="<?= h(t('full_screen')) ?>">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>
  </button>
  <?php endif; ?>
  <img class="mark" src="assets/camara-mark.png" alt="" width="28" height="28">
</header>

<?php if ($info): ?>
  <iframe id="sim" class="sim-frame" src="<?= h($info['files'][$lang]) ?>" title="<?= h($title) ?>"
          allow="fullscreen" allowfullscreen></iframe>
<?php else: ?>
  <main class="empty-lib">
    <h1><?= h(t('not_found')) ?></h1>
    <p><a href="./">← <?= h(t('all_sims')) ?></a></p>
  </main>
<?php endif; ?>

<script>
(function () {
  // "All simulations" goes back in history when we came from the library,
  // so the student's search and filters are still there.
  var back = document.getElementById('back');
  back.addEventListener('click', function (e) {
    try {
      var ref = document.referrer ? new URL(document.referrer) : null;
      if (ref && ref.origin === location.origin && !/play\.php/.test(ref.pathname) && history.length > 1) {
        e.preventDefault();
        history.back();
      }
    } catch (err) {}
  });

  var fs = document.getElementById('fs');
  var frame = document.getElementById('sim');
  if (fs && frame) {
    var el = frame;
    if (!el.requestFullscreen && !el.webkitRequestFullscreen) { fs.hidden = true; }
    fs.addEventListener('click', function () {
      (el.requestFullscreen || el.webkitRequestFullscreen).call(el);
    });
  }
  if (frame) frame.focus();
})();
</script>
</body>
</html>
