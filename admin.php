<?php
require_once __DIR__ . '/lib/updater.php';

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
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => 'Session expired — reload the page.'], 400);
    }
    session_write_close(); // don't block the status requests while working

    @ini_set('memory_limit', '512M');
    @set_time_limit(0);

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
<link rel="stylesheet" href="assets/style.css?v=8">
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
