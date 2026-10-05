(function () {
  'use strict';

  var API = 'api.php';
  var TEN_DELAY = window.GACHA_TEN_DELAY || 160;

  var state = { pools: [], current: null, detail: null, busy: false };

  var $tabs   = document.getElementById('pool-tabs');
  var $banner = document.getElementById('banner');
  var $bName  = document.getElementById('banner-name');
  var $bSub   = document.getElementById('banner-sub');
  var $bDesc  = document.getElementById('banner-desc');
  var $pity   = document.getElementById('pity-num');
  var $results= document.getElementById('results');
  var $overlay= document.getElementById('overlay');
  var $stage  = document.getElementById('stage');
  var $modal  = document.getElementById('modal');
  var $mBody  = document.getElementById('modal-body');
  var $mTitle = document.getElementById('modal-title');

  var $btnSingle = document.getElementById('btn-single');
  var $btnTen    = document.getElementById('btn-ten');
  var $btnInfo   = document.getElementById('btn-info');
  var $btnMClose = document.getElementById('modal-close');

  var $ovProgress = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
  }

  function api(action, params) {
    var body = new URLSearchParams(Object.assign({ action: action }, params || {}));
    return fetch(API, { method: 'POST', body: body }).then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.error) throw new Error(j.error);
        return j;
      });
  }

  /* ========== 卡池 ========== */
  function loadPools() {
    return api('pools').then(function (data) {
      state.pools = data.pools || [];
      if (!state.pools.length) {
        $tabs.innerHTML = '<div style="color:#8a8aa0;padding:20px">暂无已上架卡池</div>';
        return;
      }
      renderTabs();
      var DEFAULT_POOL = window.GACHA_DEFAULT_POOL || 0;
      var initial = state.pools.filter(function (p) { return p.id === DEFAULT_POOL; })[0] || state.pools[0];
      return selectPool(initial.id);
    });
  }

  function renderTabs() {
    $tabs.innerHTML = state.pools.map(function (p) {
      var on = state.current && state.current.id === p.id ? ' on' : '';
      return '<div class="pool-tab' + on + '" data-id="' + p.id + '">' + esc(p.name) + '</div>';
    }).join('');
    Array.prototype.forEach.call($tabs.querySelectorAll('.pool-tab'), function (el) {
      el.addEventListener('click', function () {
        if (state.busy) return;
        var id = parseInt(el.dataset.id, 10);
        if (state.current && state.current.id === id) return;
        selectPool(id);
      });
    });
  }

  function selectPool(id) {
    state.current = state.pools.filter(function (p) { return p.id === id; })[0] || null;
    if (!state.current) return Promise.resolve();

    $bName.textContent = state.current.name;
    $bSub.textContent  = state.current.subtitle || '';
    $bDesc.textContent = state.current.desc || '';
    $banner.style.setProperty('--ba', state.current.color_a);
    $banner.style.setProperty('--bb', state.current.color_b);
    renderTabs();

    $results.innerHTML = '<div class="empty-hint">点击上方按钮开始抽卡 ✨</div>';

    return Promise.all([
      api('pool_detail', { pool_id: id }),
      api('last_draw',   { pool_id: id })
    ]).then(function (arr) {
      state.detail = arr[0];
      updatePity(arr[0].pity);
      if (arr[1] && arr[1].results && arr[1].results.length) {
        renderResults(arr[1].results, false);
      }
    }).catch(function (e) {
      $results.innerHTML = '<div class="empty-hint">加载失败：' + esc(e.message) + '</div>';
    });
  }

  function updatePity(pity) {
    if (!pity) return;
    $pity.textContent = pity.until_hard;
  }

  /* ========== 抽卡 ========== */
  function doDraw(times) {
    if (state.busy || !state.current) return;
    state.busy = true;
    setButtonsDisabled(true);

    api('draw', { pool_id: state.current.id, times: times }).then(function (data) {
      var results = data.results || [];
      updatePity(data.pity);

      if (times === 10 && results.length) {
        playTenPull(results).then(function () {
          renderResults(results, true);
          state.busy = false;
          setButtonsDisabled(false);
        });
      } else {
        renderResults(results, true);
        state.busy = false;
        setButtonsDisabled(false);
      }
    }).catch(function (e) {
      alert('抽卡失败：' + e.message);
      state.busy = false;
      setButtonsDisabled(false);
    });
  }

  function setButtonsDisabled(dis) {
    $btnSingle.disabled = dis;
    $btnTen.disabled = dis;
  }

  /* ========== 预加载所有图片 ========== */
  function preloadImages(results) {
    results.forEach(function (c) {
      [c.img_full, c.img_chibi, c.img_half].forEach(function (u) {
        if (u) { var img = new Image(); img.src = u; }
      });
    });
  }

  /* ========== 十连过场 ========== */
  function playTenPull(results) {
    return new Promise(function (resolve) {
      if (!results.length) { resolve(); return; }

      // 一次性预加载所有图，避免切张时再请求
      preloadImages(results);

      var idx = 0;
      var total = results.length;
      var locked = false;

      if (!$ovProgress) {
        $ovProgress = document.createElement('div');
        $ovProgress.className = 'overlay-progress';
        $overlay.appendChild($ovProgress);
      }

      function buildStage(c) {
        // 立绘
        var artHtml = '';
        var artUrl = c.img_full || c.img_half || '';
        if (artUrl) {
          artHtml = '<div class="stage-art"><img src="' + esc(artUrl) + '" alt=""></div>';
        } else {
          var glyph = c.avatar_text || (c.name ? c.name.charAt(0) : '?');
          artHtml = '<div class="stage-art"><div class="stage-glyph" style="--ca:' +
                    esc(c.color_a || '#5b8def') + ';--cb:' + esc(c.color_b || '#2b2b4a') + '">' +
                    esc(glyph) + '</div></div>';
        }

        // Q 版
        var chibiHtml = '';
        if (c.img_chibi) {
          chibiHtml = '<div class="stage-chibi"><img src="' + esc(c.img_chibi) + '" alt=""></div>';
        } else if (c.avatar_text || c.name) {
          var g2 = c.avatar_text || c.name.charAt(0);
          chibiHtml = '<div class="stage-chibi"><div class="stage-chibi-fb" style="background:linear-gradient(160deg,' +
                      esc(c.color_a || '#5b8def') + ',' + esc(c.color_b || '#2b2b4a') + ')">' +
                      esc(g2) + '</div></div>';
        }

        // 名字 / 称号 / 星级
        var tierCls = c.rarity >= 6 ? 'tier-3' : (c.rarity >= 4 ? 'tier-2' : 'tier-1');
        var stars = '★'.repeat(c.rarity);

        var infoHtml =
          '<div class="stage-info">' +
            '<div class="stage-name">' + esc(c.name) + '</div>' +
            '<div class="stage-title">' + esc(c.title || '') + '</div>' +
            '<div class="stage-stars ' + tierCls + '">' + stars + '</div>' +
          '</div>';

        return artHtml + '<div class="stage-side">' + infoHtml + chibiHtml + '</div>';
      }

      function renderCurrent() {
        $stage.innerHTML = buildStage(results[idx]);
        $ovProgress.textContent = (idx + 1) + ' / ' + total;
      }

      function playEnter() {
        $stage.classList.remove('leaving');
        void $stage.offsetWidth;
        $stage.classList.add('entering');
        setTimeout(function () { $stage.classList.remove('entering'); }, 400);
      }

      function next() {
        if (locked) return;
        locked = true;

        idx++;
        if (idx >= total) {
          $overlay.classList.remove('show');
          $overlay.removeEventListener('click', onClick);
          setTimeout(function () { $stage.innerHTML = ''; resolve(); }, 260);
          return;
        }

        // 当前向左滑出
        $stage.classList.remove('entering');
        $stage.classList.add('leaving');

        setTimeout(function () {
          renderCurrent();
          $stage.classList.remove('leaving');
          void $stage.offsetWidth;
          playEnter();
          setTimeout(function () { locked = false; }, 180);
        }, 200);
      }

      function onClick() { next(); }

      renderCurrent();
      $overlay.addEventListener('click', onClick);
      $overlay.classList.add('show');
      setTimeout(playEnter, 50);
    });
  }

  /* ========== 结果渲染 ========== */
  function renderResults(results, animate) {
    if (!results || !results.length) {
      $results.innerHTML = '<div class="empty-hint">没有结果</div>';
      return;
    }
    var html = '';
    for (var i = 0; i < results.length; i++) {
      var c = results[i];
      var url = c.img_half || c.img_chibi || c.img_full || '';
      var art = url
        ? '<div class="card-art"><img src="' + esc(url) + '" alt=""></div>'
        : '<div class="card-art" style="--ca:' + esc(c.color_a) + ';--cb:' + esc(c.color_b) + '">' +
            '<div class="card-glyph">' + esc(c.avatar_text || (c.name ? c.name.charAt(0) : '?')) + '</div>' +
          '</div>';
      var stars = '★'.repeat(c.rarity);
      var delay = animate ? (i * 80) : 0;
      html +=
        '<div class="card rarity-' + c.rarity + '" style="animation-delay:' + delay + 'ms">' +
          art +
          (c.is_up ? '<div class="card-up">UP</div>' : '') +
          '<div class="card-rarity">' + c.rarity + '★</div>' +
          '<div class="card-body">' +
            '<div class="card-name">' + esc(c.name) + '</div>' +
            '<div class="card-title">' + esc(c.title) + '</div>' +
            '<div class="card-stars">' + stars + '</div>' +
          '</div>' +
        '</div>';
    }
    $results.innerHTML = html;
  }

  /* ========== 弹窗 ========== */
  function openModal() {
    if (!state.detail) return;
    renderModalContent();
    $modal.classList.add('show');
  }
  function closeModal() { $modal.classList.remove('show'); }

  function renderModalContent() {
    var d = state.detail;
    $mTitle.textContent = d.pool.name + ' · 概率 & 池内角色';

    var html = '<div class="rate-table">';
    var legend6plus = 0;
    for (var s = 6; s <= 9; s++) legend6plus += (d.rates[s] || 0);

    html += '<div class="rate-row tier"><span class="lbl">★★★★★★ 及以上</span>' +
            '<span class="pct">' + legend6plus.toFixed(3) + '%</span></div>';

    for (var i = 9; i >= 1; i--) {
      var pct = (d.rates[i] || 0).toFixed(4);
      var starStr = '★'.repeat(i);
      var cls = i >= 6 ? ' legend-' + i : '';
      html += '<div class="rate-row' + cls + '"><span class="lbl">' + starStr + '</span>' +
              '<span class="pct">' + pct + '%</span></div>';
    }
    html += '</div>';

    var byStar = {};
    d.chars.forEach(function (c) {
      if (!byStar[c.rarity]) byStar[c.rarity] = [];
      byStar[c.rarity].push(c);
    });

    html += '<div class="chars-block">';
    for (var star = 9; star >= 1; star--) {
      if (!byStar[star]) continue;
      var list = byStar[star].map(function (c) {
        var upCls = c.is_up ? ' up' : '';
        return '<span class="cn' + upCls + '">' + esc(c.name) + '</span>';
      }).join('<span style="color:#4a4a5a"> / </span>');
      html += '<h4>' + star + '★</h4>';
      html += '<div class="chars-line">' + list + '</div>';
    }
    html += '</div>';

    $mBody.innerHTML = html;
  }

  /* ========== 绑定 ========== */
  $btnSingle.addEventListener('click', function () { doDraw(1); });
  $btnTen.addEventListener('click', function () { doDraw(10); });
  $btnInfo.addEventListener('click', openModal);
  $btnMClose.addEventListener('click', closeModal);
  $modal.addEventListener('click', function (e) { if (e.target === $modal) closeModal(); });

  loadPools();
})();