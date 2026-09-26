<?php
require_once __DIR__ . '/lib/updater.php';
require_once __DIR__ . '/lib/pack.php';

session_name('phetadmin');
session_start();

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$password = (string)cfg('admin_password');
$defaultPassword = $password === '' || $password === 'change-me';
$authed = !empty($_SESSION['phet_admin']);
$error = null;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// ---- login / logout
if (($_POST['do'] ?? '') === 'login') {
    usleep(400000); // slow down guessing
    if (hash_equals($password, (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['phet_admin'] = true;
        header('Location: admin.php');
        exit;
    }
    $error = 'Wrong password.';
}
if (($_GET['do'] ?? '') === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ---- JSON actions (logged-in only)
$action = $_POST['action'] ?? $_GET['action'] ?? null;
if ($action) {
    if (!$authed) {
        json_out(['error' => 'Please log in again.'], 403);
    }
    if ($action === 'status') {
        $catalog = load_catalog();
        $log = is_file(LOG_FILE) ? (string)file_get_contents(LOG_FILE) : '';
        json_out([
            'running' => PhetUpdater::isRunning(),
            'log' => mb_substr($log, -20000),
            'count' => count(local_library()),
            'updated' => human_time($catalog['updated'] ?? null),
            'checked' => human_time($catalog['checked'] ?? null),
        ]);
    }
    if ($action === 'usage_csv') {
        $u = load_usage();
        $cat = load_catalog()['sims'];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sim-usage-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8 correctly
        fputcsv($out, ['simulation', 'title', 'language', 'opens']);
        foreach ($u['sims'] as $sim => $byLang) {
            foreach ($byLang as $loc => $n) {
                fputcsv($out, [$sim, $cat[$sim]['title']['en'] ?? title_from_name($sim), $loc, $n]);
            }
        }
        fputcsv($out, []);
        fputcsv($out, ['month', '', '', 'opens']);
        foreach ($u['months'] as $m => $n) {
            fputcsv($out, [$m, '', '', $n]);
        }
        exit;
    }
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => 'Session expired — reload the page.'], 400);
    }
    session_write_close(); // don't block the status requests while working

    @ini_set('memory_limit', '512M');
    @set_time_limit(0);

    if ($action === 'pack') {
        $langs = array_values(array_intersect((array)($_POST['langs'] ?? []), cfg('locales')));
        if (!$langs) {
            $langs = cfg('locales');
        }
        $files = pack_files($langs, !empty($_POST['with_app']));
        ignore_user_abort(false);
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/x-tar');
        header('Content-Length: ' . tar_size($files));
        header('Content-Disposition: attachment; filename="camara-sims-' . implode('-', $langs) . '-' . date('Y-m-d') . '.tar"');
        header('Cache-Control: no-store');
        stream_tar($files, function ($chunk) {
            echo $chunk;
            flush();
        });
        exit;
    }

    if ($action === 'usage_reset') {
        @unlink(USAGE_FILE);
        json_out(['ok' => true]);
    }

    if ($action === 'check') {
        if (PhetUpdater::isRunning()) {
            json_out(['error' => 'An update is running right now — wait for it to finish.'], 409);
        }
        try {
            $plan = (new PhetUpdater())->plan();
            $pick = fn($items) => array_map(fn($i) => [
                'title' => $i['title'], 'locale' => $i['locale'], 'version' => $i['version'], 'old' => $i['old'],
            ], $items);
            json_out([
                'ok' => true,
                'total' => count($plan['remote']),
                'new' => $pick($plan['new']),
                'update' => $pick($plan['update']),
                'adopt' => count($plan['adopt']),
                'thumbs' => count($plan['thumbs']),
            ]);
        } catch (Throwable $e) {
            json_out(['error' => $e->getMessage()], 502);
        }
    }

    if ($action === 'run') {
        ignore_user_abort(true); // keep going even if the browser tab is closed
        try {
            $result = (new PhetUpdater(null, true))->run();
            json_out(['ok' => true] + $result);
        } catch (Throwable $e) {
            if (is_writable(DATA_DIR)) {
                file_put_contents(LOG_FILE, '[' . date('H:i:s') . '] ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
            }
            json_out(['error' => $e->getMessage()], 502);
        }
    }
    json_out(['error' => 'Unknown action'], 400);
}

// ---- page
$catalog = load_catalog();
$library = local_library();
$sizes = library_sizes();
$thumbBytes = thumbs_size();
$free = @disk_free_space(APP_DIR);
$usage = load_usage();
$thisMonth = $usage['months'][date('Y-m')] ?? 0;
$top = [];
foreach ($usage['sims'] as $sim => $byLang) {
    $top[$sim] = array_sum($byLang);
}
arsort($top);
$top = array_slice($top, 0, 15, true);
$maxTop = $top ? max($top) : 1;
$checks = [
    ['sims folder is writable', is_dir(SIMS_DIR) && is_writable(SIMS_DIR)],
    ['sims/thumbs folder is writable', is_dir(THUMBS_DIR) && is_writable(THUMBS_DIR)],
    ['data folder is writable', is_dir(DATA_DIR) && is_writable(DATA_DIR)],
    ['PHP can download files (curl)', function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)],
];
$allOk = !in_array(false, array_column($checks, 1), true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Admin — <?= h(cfg('site_title')) ?></title>
<link rel="icon" href="assets/camara-mark.png" type="image/png">
<link rel="stylesheet" href="assets/style.css?v=10">
</head>
<body>
<header class="top">
  <div class="top-inner">
    <a class="brand" href="./">
      <img class="logo" src="assets/camara-logo-white.png" alt="Camara Education Ethiopia" width="562" height="209">
      <span class="brand-text"><strong><?= h(cfg('site_title')) ?></strong><small>Admin &amp; updates</small></span>
    </a>
    <?php if ($authed): ?><a class="logout" href="admin.php?do=logout">Log out</a><?php endif; ?>
  </div>
</header>

<script>
(function () { // leave room for the fixed header
  var h = document.querySelector('.top');
  function set() { document.documentElement.style.setProperty('--hh', h.offsetHeight + 'px'); }
  set(); window.addEventListener('resize', set);
})();
</script>

<?php if (!$authed): ?>
<main class="login panel">
  <h2>Admin login</h2>
  <?php if ($error): ?><p class="msg err"><?= h($error) ?></p><?php endif; ?>
  <?php if ($defaultPassword): ?>
    <p class="msg warn">The password is still the default (<code>change-me</code>). Copy <code>config.local.example.php</code> to <code>config.local.php</code> and set <code>admin_password</code> there.</p>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="do" value="login">
    <label for="pw">Password</label>
    <input id="pw" type="password" name="password" autofocus required>
    <button class="btn primary" type="submit">Log in</button>
  </form>
  <p><a href="./">← Back to simulations</a></p>
</main>
<?php else: ?>
<main class="admin">
  <?php if ($defaultPassword): ?>
    <p class="msg warn">Set an admin password in <code>config.local.php</code> (copy <code>config.local.example.php</code>) so students can&apos;t start downloads.</p>
  <?php endif; ?>

  <section class="panel">
    <h2>Library</h2>
    <dl class="stats">
      <div><dt>Sims here</dt><dd id="stCount"><?= count($library) ?></dd></div>
      <div><dt>Languages</dt><dd><?= h(implode(', ', cfg('locales'))) ?></dd></div>
      <div><dt>Last update</dt><dd id="stUpdated" style="font-size:15px"><?= h(human_time($catalog['updated'] ?? null)) ?></dd></div>
      <div><dt>Last check</dt><dd id="stChecked" style="font-size:15px"><?= h(human_time($catalog['checked'] ?? null)) ?></dd></div>
    </dl>
    <table class="table">
      <thead><tr><th>Language</th><th class="num">Sims</th><th class="num">Size</th></tr></thead>
      <tbody>
        <?php foreach (cfg('locales') as $loc): $sz = $sizes[$loc] ?? ['files' => 0, 'bytes' => 0]; ?>
          <tr><td><?= h(locale_name($loc)) ?> <small>(<?= h($loc) ?>)</small></td>
              <td class="num"><?= (int)$sz['files'] ?></td><td class="num"><?= h(human_bytes((int)$sz['bytes'])) ?></td></tr>
        <?php endforeach; ?>
        <tr><td>Pictures</td><td class="num"></td><td class="num"><?= h(human_bytes($thumbBytes)) ?></td></tr>
      </tbody>
      <?php if ($free !== false): ?>
      <tfoot><tr><td colspan="2">Free disk space on this server</td><td class="num"><?= h(human_bytes((int)$free)) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </section>

  <section class="panel">
    <h2>Check for new simulations</h2>
    <p>This computer needs internet for this step. Students keep using the library while it runs.</p>
    <div class="actions">
      <button class="btn" id="btnCheck" <?= $allOk ? '' : 'disabled' ?>>Check for updates</button>
      <button class="btn primary" id="btnRun" <?= $allOk ? '' : 'disabled' ?>>Update now</button>
    </div>
    <div id="result"></div>
    <pre class="log" id="log" hidden></pre>
  </section>

  <section class="panel">
    <h2>Usage</h2>
    <?php if (!$usage['total']): ?>
      <p>No simulations opened yet. Counts appear here as students use the library.</p>
    <?php else: ?>
    <dl class="stats">
      <div><dt>Opened in total</dt><dd><?= number_format((int)$usage['total']) ?></dd></div>
      <div><dt>This month</dt><dd><?= number_format((int)$thisMonth) ?></dd></div>
      <div><dt>Counting since</dt><dd style="font-size:15px"><?= h(date('j M Y', (int)$usage['since'])) ?></dd></div>
      <div><dt>By language</dt><dd style="font-size:14px;font-weight:600">
        <?php foreach ($usage['langs'] as $loc => $n): ?><?= h($loc) ?>&nbsp;<?= (int)$n ?> &nbsp;<?php endforeach; ?></dd></div>
    </dl>
    <h3 class="sub">Most opened</h3>
    <ol class="bars">
      <?php foreach ($top as $sim => $n): ?>
        <li><span class="bar-label"><?= h($catalog['sims'][$sim]['title']['en'] ?? title_from_name($sim)) ?></span>
            <span class="meter"><span style="width:<?= max(2, round($n * 100 / $maxTop)) ?>%"></span></span>
            <span class="bar-n"><?= (int)$n ?></span></li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
    <p class="hint">Counts only which simulation was opened, in which language and month. No names or other personal data.</p>
    <div class="actions">
      <a class="btn" href="admin.php?action=usage_csv">Download as spreadsheet (CSV)</a>
      <?php if ($usage['total']): ?><button class="btn" id="btnReset">Reset counts</button><?php endif; ?>
    </div>
  </section>

  <section class="panel">
    <h2>Offline copy for another server</h2>
    <p>Download the whole library as one file, copy it to a USB drive, and unpack it on another server.
       That server won't need to download anything from the internet.</p>
    <form method="post" action="admin.php" id="packForm">
      <input type="hidden" name="action" value="pack">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <fieldset class="langs">
        <legend>Languages</legend>
        <?php foreach (cfg('locales') as $loc): $sz = $sizes[$loc] ?? ['files' => 0, 'bytes' => 0]; ?>
          <label class="check"><input type="checkbox" name="langs[]" value="<?= h($loc) ?>" checked data-bytes="<?= (int)$sz['bytes'] ?>">
            <?= h(locale_name($loc)) ?> <em><?= (int)$sz['files'] ?> sims · <?= h(human_bytes((int)$sz['bytes'])) ?></em></label>
        <?php endforeach; ?>
        <label class="check"><input type="checkbox" name="with_app" value="1" checked>
          Include the app itself <em>for a brand-new server</em></label>
      </fieldset>
      <div class="actions">
        <button class="btn primary" type="submit">Download library pack (.tar)</button>
        <span class="hint" id="packSize"></span>
      </div>
    </form>
    <details class="howto">
      <summary>How to unpack it on the other server</summary>
      <p><b>Ubuntu:</b> copy the file from the USB drive, then run:</p>
      <pre class="log">sudo tar -xf camara-sims-*.tar -C /var/www/html/
sudo chown -R www-data:www-data /var/www/html/phet/sims /var/www/html/phet/data</pre>
      <p><b>Windows (Laragon):</b> open Laragon's Terminal and run:</p>
      <pre class="log">tar -xf camara-sims-*.tar -C C:\laragon\www\</pre>
      <p>Then set the admin password on that server in <code>config.local.php</code>.
         Tip: on a server you can also make the pack straight onto a USB drive with
         <code>php pack-cli.php /media/usb/camara-sims.tar</code>.</p>
    </details>
  </section>

  <section class="panel">
    <h2>System check</h2>
    <ul class="checks">
      <?php foreach ($checks as [$label, $ok]): ?>
        <li><?= $ok ? '✅' : '❌' ?> <?= h($label) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$allOk): ?>
      <p class="msg err" style="margin-top:12px">Fix the items above. In a terminal, run:<br>
        <code>sudo chown -R www-data:www-data <?= h(SIMS_DIR) ?> <?= h(DATA_DIR) ?></code><br>
        <code>sudo apt install php-curl &amp;&amp; sudo systemctl restart apache2</code></p>
    <?php endif; ?>
  </section>
</main>

<script>
(function () {
  var csrf = <?= json_encode($csrf) ?>;
  var btnCheck = document.getElementById('btnCheck');
  var btnRun = document.getElementById('btnRun');
  var result = document.getElementById('result');
  var logEl = document.getElementById('log');
  var timer = null;
  var active = false; // a request from this page is in progress

  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function post(action) {
    var body = new FormData(); body.append('action', action); body.append('csrf', csrf);
    return fetch('admin.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { error: 'Server error (HTTP ' + r.status + ')' }; }); });
  }
  function busy(on) { btnCheck.disabled = on; btnRun.disabled = on; }
  function show(html, kind) { result.innerHTML = '<div class="msg ' + (kind || '') + '" style="margin:16px 0 0">' + html + '</div>'; }
  function items(list) {
    return '<ul class="list">' + list.map(function (i) {
      return '<li>' + esc(i.title) + ' <small>[' + esc(i.locale) + '] ' + (i.old ? esc(i.old) + ' → ' : '') + esc(i.version) + '</small></li>';
    }).join('') + '</ul>';
  }

  function poll() {
    fetch('admin.php?action=status', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (s) {
      if (s.log) { logEl.hidden = false; logEl.textContent = s.log; logEl.scrollTop = logEl.scrollHeight; }
      document.getElementById('stCount').textContent = s.count;
      document.getElementById('stUpdated').textContent = s.updated;
      document.getElementById('stChecked').textContent = s.checked;
      if (s.running || active) { busy(true); timer = setTimeout(poll, 1500); }
      else { timer = null; busy(false); }
    }).catch(function () { timer = setTimeout(poll, 3000); });
  }

  var btnReset = document.getElementById('btnReset');
  if (btnReset) btnReset.addEventListener('click', function () {
    if (!confirm('Reset all usage counts to zero?')) return;
    post('usage_reset').then(function () { location.reload(); });
  });

  var packForm = document.getElementById('packForm');
  var packSize = document.getElementById('packSize');
  function updatePackSize() {
    var total = 0;
    packForm.querySelectorAll('input[name="langs[]"]').forEach(function (b) { if (b.checked) total += +b.dataset.bytes; });
    packSize.textContent = 'About ' + (total >= 1073741824 ? (total / 1073741824).toFixed(1) + ' GB' : Math.round(total / 1048576) + ' MB');
  }
  packForm.addEventListener('change', updatePackSize);
  updatePackSize();

  btnCheck.addEventListener('click', function () {
    busy(true); active = true; show('Checking PhET for new simulations…');
    post('check').then(function (r) {
      active = false; busy(false);
      if (r.error) return show(esc(r.error), 'err');
      var n = r.new.length, u = r.update.length;
      var html = 'PhET has <b>' + r.total + '</b> simulations. ';
      if (!n && !u) html += 'Your library is up to date. 🎉';
      else html += '<b>' + n + '</b> new and <b>' + u + '</b> updated. Click <b>Update now</b> to download them.';
      if (r.adopt) html += '<br>' + r.adopt + ' files you copied in already will be kept.';
      if (n) html += '<br><br><b>New:</b>' + items(r['new']);
      if (u) html += '<br><b>Updated:</b>' + items(r.update);
      show(html, n || u ? 'warn' : 'ok');
      poll();
    });
  });

  btnRun.addEventListener('click', function () {
    busy(true); active = true; show('Updating… you can leave this page open or close it; the download continues.');
    logEl.hidden = false; logEl.textContent = 'Starting…';
    post('run').then(function (r) {
      active = false;
      if (r.error && /HTTP 5\d\d/.test(r.error)) show('The page lost its connection to the server, but the update keeps running. Watch the log below.', 'warn');
      else if (r.error) show(esc(r.error), 'err');
      else show('Done: <b>' + r.downloaded + '</b> downloaded' + (r.failed.length ? ', <b>' + r.failed.length + '</b> failed (try again later)' : '') + '.', r.failed.length ? 'warn' : 'ok');
      if (timer) clearTimeout(timer);
      busy(false); poll();
    });
    setTimeout(poll, 800);
  });

  poll();
})();
</script>
<?php endif; ?>
</body>
</html>
