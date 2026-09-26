(function () {
  'use strict';
  // Keep the filter panel just below the fixed header, whatever its height.
  var header = document.querySelector('.top');
  function setHeaderHeight() {
    if (header) document.documentElement.style.setProperty('--hh', header.offsetHeight + 'px');
  }
  setHeaderHeight();
  window.addEventListener('resize', setHeaderHeight);

  var grid = document.getElementById('grid');
  if (!grid) return;

  var cards = Array.prototype.slice.call(grid.children);
  var q = document.getElementById('q');
  var sort = document.getElementById('sort');
  var count = document.getElementById('count');
  var none = document.getElementById('none');
  var filters = document.getElementById('filters');
  var toggle = document.getElementById('filterToggle');
  var filterCount = document.getElementById('filterCount');
  var boxes = Array.prototype.slice.call(filters.querySelectorAll('input[type=checkbox]'));

  function checked(name) {
    return boxes.filter(function (b) { return b.name === name && b.checked; })
                .map(function (b) { return b.value; });
  }
  function words(s) { return s ? s.split(' ') : []; }
  function overlaps(a, b) {
    for (var i = 0; i < a.length; i++) if (b.indexOf(a[i]) !== -1) return true;
    return false;
  }

  function apply() {
    var subjects = checked('subject');
    var subs = checked('sub');
    var grades = checked('grade');
    var terms = q.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    var shown = 0;

    cards.forEach(function (c) {
      var ok = true;
      if (subjects.length || subs.length) {
        ok = overlaps(subjects, words(c.dataset.subjects)) || overlaps(subs, words(c.dataset.subs));
      }
      if (ok && grades.length) ok = overlaps(grades, words(c.dataset.grades));
      if (ok && terms.length) {
        var t = c.dataset.title;
        ok = terms.every(function (w) { return t.indexOf(w) !== -1; });
      }
      c.hidden = !ok;
      if (ok) shown++;
    });

    count.textContent = shown === cards.length
      ? cards.length + ' simulations'
      : shown + ' of ' + cards.length + ' simulations';
    none.hidden = shown !== 0;

    var n = subjects.length + subs.length + grades.length;
    filterCount.hidden = n === 0;
    filterCount.textContent = n;
    saveHash(subjects, subs, grades);
  }

  function reorder() {
    var mode = sort.value;
    cards.sort(function (a, b) {
      if (mode === 'new') {
        var d = (+b.dataset.added) - (+a.dataset.added);
        if (d) return d;
      }
      return a.dataset.title.localeCompare(b.dataset.title);
    });
    cards.forEach(function (c) { grid.appendChild(c); });
  }

  // Filters live in the address (e.g. #subject=chemistry&grade=1) so a teacher can share a link.
  function saveHash(subjects, subs, grades) {
    var parts = [];
    if (subjects.length) parts.push('subject=' + subjects.join(','));
    if (subs.length) parts.push('sub=' + subs.join(','));
    if (grades.length) parts.push('grade=' + grades.join(','));
    if (q.value.trim()) parts.push('q=' + encodeURIComponent(q.value.trim()));
    var h = parts.length ? '#' + parts.join('&') : ' ';
    if (history.replaceState) history.replaceState(null, '', h === ' ' ? location.pathname + location.search : h);
  }

  function loadHash() {
    var h = location.hash.replace(/^#/, '');
    if (!h) return;
    h.split('&').forEach(function (p) {
      var kv = p.split('=');
      var key = kv[0], val = decodeURIComponent(kv[1] || '');
      if (key === 'q') { q.value = val; return; }
      val.split(',').forEach(function (v) {
        boxes.forEach(function (b) { if (b.name === key && b.value === v) b.checked = true; });
      });
    });
  }

  function clearAll() {
    boxes.forEach(function (b) { b.checked = false; });
    q.value = '';
    apply();
  }

  boxes.forEach(function (b) { b.addEventListener('change', apply); });
  q.addEventListener('input', apply);
  sort.addEventListener('change', function () { reorder(); apply(); });
  document.getElementById('clear').addEventListener('click', clearAll);
  document.getElementById('clear2').addEventListener('click', clearAll);
  toggle.addEventListener('click', function () {
    var open = filters.classList.toggle('open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && document.activeElement !== q) { e.preventDefault(); q.focus(); }
  });

  loadHash();
  apply();
})();
