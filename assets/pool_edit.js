/* 池编辑页专用 JS —— 独立外部文件 */
(function () {
  'use strict';

  // 全局错误捕获：任何异常都上报到诊断面板
  window.onerror = function (msg, url, line) {
    var diag = document.getElementById('diag-panel');
    if (diag) {
      var err = document.createElement('div');
      err.style.color = '#f87171';
      err.textContent = '⚠ JS错误：' + msg + ' (' + line + ')';
      diag.appendChild(err);
    }
  };

  function mark(id, text, ok) {
    var el = document.getElementById(id);
    if (!el) return;
    el.textContent = text;
    el.className = ok ? 'ok' : 'err';
  }

  document.addEventListener('DOMContentLoaded', function () {
    mark('js-status', '已执行 ✓', true);

    var wrap = document.getElementById('assoc-wrap');
    if (!wrap) {
      mark('rendered-rows', '未找到表格容器', false);
      return;
    }

    var rows = wrap.querySelectorAll('.ar');
    var expected = window.POOL_EDIT_EXPECTED || 0;
    if (rows.length === expected) {
      mark('rendered-rows', rows.length + ' 行（符合预期）', true);
    } else {
      mark('rendered-rows', rows.length + ' 行 / 期望 ' + expected + ' 行', false);
    }

    if (!rows.length) return;

    var searchInput = document.getElementById('search-input');
    var sfBar       = document.getElementById('sf-bar');
    var hint        = document.getElementById('count-hint');
    var currentStar = 'all';

    function apply() {
      var q = (searchInput ? searchInput.value : '').trim().toLowerCase();
      var visible = 0;
      for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var key = (r.getAttribute('data-search') || '').toLowerCase();
        var st  = r.getAttribute('data-star') || '';
        var okT = (q === '' || key.indexOf(q) !== -1);
        var okS = (currentStar === 'all' || st === currentStar);
        if (okT && okS) { r.style.display = ''; visible++; }
        else { r.style.display = 'none'; }
      }
      if (hint) hint.textContent = '显示 ' + visible + ' / ' + rows.length + ' 个角色';
    }

    if (searchInput) {
      searchInput.addEventListener('input', apply);
    }

    if (sfBar) {
      sfBar.addEventListener('click', function (e) {
        var item = e.target.closest ? e.target.closest('.sf') : null;
        if (!item) return;
        var all = sfBar.querySelectorAll('.sf');
        for (var i = 0; i < all.length; i++) all[i].classList.remove('on');
        item.classList.add('on');
        currentStar = item.getAttribute('data-star');
        apply();
      });
    }

    wrap.addEventListener('change', function (e) {
      var t = e.target;
      if (!t.classList.contains('char-cb')) return;
      var row = t.parentNode.parentNode;
      var fields = row.querySelectorAll('.w-in, .up-in');
      for (var i = 0; i < fields.length; i++) fields[i].disabled = !t.checked;
    });

    apply();
  });
})();