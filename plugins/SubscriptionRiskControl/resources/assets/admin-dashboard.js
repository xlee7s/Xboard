(function () {
  'use strict';

  var state = window.SUBSCRIPTION_RISK_CONTROL || {};
  var base = '/api/v2/' + String(state.adminPath || '').replace(/^\/+|\/+$/g, '') + '/subscription-risk-control';
  var toast = document.getElementById('toast');
  var confirmModal = document.getElementById('confirm-modal');
  var confirmResolve = null;
  var keywords = { watchlist: '', banlist: '', pulls: '', pullLogs: '', events: '' };
  var pullPeriod = 'day';
  var pageSize = 10;
  var pages = { watchlist: 1, banlist: 1, pulls: 1, pullLogs: 1, events: 1 };

  function token() {
    try {
      var stored = JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || 'null');
      return stored && stored.value ? stored.value : '';
    } catch (_) { return ''; }
  }
  function api(path, options) {
    options = options || {};
    // Pull-log decisions must always come from the current server response.
    // A cached GET response could otherwise show a previous decision after an
    // administrator changes the allowed subscription software list.
    options.cache = 'no-store';
    options.headers = Object.assign({ 'Content-Type': 'application/json', 'Authorization': token(), 'Content-Language': localStorage.getItem('i18nextLng') || 'zh-CN' }, options.headers || {});
    if (!options.method || String(options.method).toUpperCase() === 'GET') {
      path += (path.indexOf('?') === -1 ? '?' : '&') + '_src=' + Date.now();
    }
    return fetch(base + path, options).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (body) {
        if (!response.ok) throw new Error(body.message || '请求失败，请确认管理员登录状态。');
        return body.data || {};
      });
    });
  }
  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) { return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' })[char]; });
  }
  function time(value) {
    if (!value) return '–';
    return new Date(Number(value) * 1000).toLocaleString('zh-CN', { hour12: false });
  }
  function action(action) {
    if (action === 'token_uuid') return '<span class="src-badge danger">重置 Token + UUID</span>';
    if (action === 'token') return '<span class="src-badge">仅重置 Token</span>';
    if (action === 'manual_ban') return '<span class="src-badge danger">已封禁</span>';
    if (action === 'manual_unban') return '<span class="src-badge success">已解封</span>';
    if (action === 'auto_release') return '<span class="src-badge success">自动解除</span>';
    return '<span class="src-badge neutral">手动解除</span>';
  }
  function showToast(message, error) {
    toast.textContent = message;
    toast.className = 'src-toast show' + (error ? ' error' : '');
    window.clearTimeout(showToast.timer);
    showToast.timer = window.setTimeout(function () { toast.className = 'src-toast'; }, 3600);
  }
  function closeConfirm(confirmed) {
    if (!confirmModal || !confirmResolve) return;
    var resolve = confirmResolve;
    confirmResolve = null;
    confirmModal.hidden = true;
    confirmModal.setAttribute('aria-hidden', 'true');
    resolve(confirmed);
  }
  function requestConfirmation(title, message, submitText, danger) {
    return new Promise(function (resolve) {
      confirmResolve = resolve;
      document.getElementById('confirm-title').textContent = title;
      document.getElementById('confirm-message').textContent = message;
      document.getElementById('confirm-submit').textContent = submitText;
      document.getElementById('confirm-submit').classList.toggle('danger', danger);
      document.getElementById('confirm-icon').classList.toggle('danger', danger);
      confirmModal.hidden = false;
      confirmModal.setAttribute('aria-hidden', 'false');
      document.getElementById('confirm-submit').focus();
    });
  }
  function setText(id, value) { document.getElementById(id).textContent = value == null ? '–' : value; }
  function renderPager(name, data) {
    var element = document.getElementById(name === 'pullLogs' ? 'pull-logs-pager' : name + '-pager');
    var total = Number(data.total || 0);
    var page = Math.max(1, Number(data.page || 1));
    var perPage = Math.max(1, Number(data.per_page || pageSize));
    var totalPages = Math.max(1, Math.ceil(total / perPage));
    if (page > totalPages && total > 0) {
      pages[name] = totalPages;
      load();
      return;
    }
    if (total === 0) {
      element.innerHTML = '';
      return;
    }
    element.innerHTML = '<span class="src-page-info">第 ' + page + ' / ' + totalPages + ' 页 · 共 ' + total + ' 条</span>'
      + '<div class="src-page-actions"><button class="src-page-button" type="button" data-page-target="' + name + '" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>上一页</button>'
      + '<button class="src-page-button" type="button" data-page-target="' + name + '" data-page="' + (page + 1) + '"' + (page >= totalPages ? ' disabled' : '') + '>下一页</button></div>';
  }
  function renderWatchlist(data) {
    var body = document.getElementById('watchlist');
    setText('watch-count', data.total ? '共 ' + data.total + ' 人' : '');
    if (!data.items || !data.items.length) {
      body.innerHTML = '<tr><td colspan="6" class="src-empty">' + (keywords.watchlist ? '未发现该邮箱的记录，请检查后重试。' : '当前没有用户处于风控观察名单。') + '</td></tr>';
      renderPager('watchlist', data);
      return;
    }
    body.innerHTML = data.items.map(function (item) {
      return '<tr><td><b>' + esc(item.email) + '</b><small>ID: ' + esc(item.user_id) + '</small></td><td>' + action(item.last_action) + '</td><td class="src-reason">' + esc((item.last_reasons || []).join('；')) + '</td><td>' + esc(item.hit_count) + '</td><td>' + time(item.last_abnormal_at) + '</td><td><div class="src-row-actions"><button class="src-release" data-id="' + esc(item.user_id) + '" data-email="' + esc(item.email) + '">解除观察</button><button class="src-ban" data-id="' + esc(item.user_id) + '" data-email="' + esc(item.email) + '">封禁用户</button></div></td></tr>';
    }).join('');
    renderPager('watchlist', data);
  }
  function renderEvents(data) {
    var body = document.getElementById('events');
    if (!data.items || !data.items.length) {
      body.innerHTML = '<tr><td colspan="6" class="src-empty">' + (keywords.events ? '未发现该邮箱的记录，请检查后重试。' : '暂无风控事件。') + '</td></tr>';
      renderPager('events', data);
      return;
    }
    body.innerHTML = data.items.map(function (item) {
      var actor = item.actor && item.actor.email ? esc(item.actor.email) + '<small>ID: ' + esc(item.actor.id) + (item.actor.ip ? ' · ' + esc(item.actor.ip) : '') + '</small>' : '<span class="src-system-action">系统</span>';
      return '<tr><td>' + time(item.occurred_at) + '</td><td><b>' + esc(item.email) + '</b><small>ID: ' + esc(item.user_id) + '</small></td><td>' + action(item.action) + '</td><td>' + actor + '</td><td class="src-ip">' + esc(item.ip || '–') + '</td><td class="src-reason">' + esc((item.reasons || []).join('；')) + '</td></tr>';
    }).join('');
    renderPager('events', data);
  }
  function renderBanlist(data) {
    var body = document.getElementById('banlist');
    setText('ban-count', data.total ? '共 ' + data.total + ' 人' : '');
    if (!data.items || !data.items.length) {
      body.innerHTML = '<tr><td colspan="5" class="src-empty">' + (keywords.banlist ? '未发现该邮箱的记录，请检查后重试。' : '当前没有用户处于风控封禁名单。') + '</td></tr>';
      renderPager('banlist', data);
      return;
    }
    body.innerHTML = data.items.map(function (item) {
      return '<tr><td><b>' + esc(item.email) + '</b><small>ID: ' + esc(item.user_id) + '</small></td><td class="src-reason">' + esc((item.reasons || []).join('；')) + '</td><td>' + esc(item.hit_count) + '</td><td>' + time(item.banned_at) + '</td><td><button class="src-unban" data-id="' + esc(item.user_id) + '" data-email="' + esc(item.email) + '">解封用户</button></td></tr>';
    }).join('');
    renderPager('banlist', data);
  }
  function renderPulls(data) {
    var body = document.getElementById('pulls');
    var sevenDays = data.period === '7d';
    document.getElementById('pulls-title').textContent = sevenDays ? '近 7 天订阅拉取统计' : '今日订阅拉取统计';
    document.getElementById('pulls-description').textContent = sevenDays
      ? '按用户汇总近 7 天有效订阅拉取次数，并按次数从高到低排列。'
      : '按用户汇总今日有效订阅拉取次数，并按次数从高到低排列。';
    setText('pull-count', data.total ? '共 ' + data.total + ' 名用户' : '');
    if (!data.items || !data.items.length) {
      body.innerHTML = '<tr><td colspan="6" class="src-empty">' + (keywords.pulls ? '未发现该邮箱的记录，请检查后重试。' : (sevenDays ? '近 7 天暂无拉取数据。' : '今日暂无拉取数据。')) + '</td></tr>';
      renderPager('pulls', data);
      return;
    }
    body.innerHTML = data.items.map(function (item, index) {
      return '<tr><td><span class="src-rank">' + (((Number(data.page || 1) - 1) * Number(data.per_page || pageSize)) + index + 1) + '</span></td><td><b>' + esc(item.email) + '</b><small>ID: ' + esc(item.user_id) + '</small></td><td><b class="src-pull-number">' + esc(item.pull_count) + '</b></td><td>' + esc(item.ip_count) + '</td><td>' + esc(item.user_agent_count) + '</td><td>' + time(item.last_pulled_at) + '</td></tr>';
    }).join('');
    renderPager('pulls', data);
  }
  function renderPullLogs(data) {
    var body = document.getElementById('pull-logs');
    setText('pull-log-count', data.total ? '累计 ' + data.total + ' 条' : '');
    if (!data.items || !data.items.length) {
      body.innerHTML = '<tr><td colspan="5" class="src-empty">' + (keywords.pullLogs ? '未发现该邮箱的记录，请检查后重试。' : '暂无订阅拉取记录。') + '</td></tr>';
      renderPager('pullLogs', data);
      return;
    }
    body.innerHTML = data.items.map(function (item) {
      var result = item.result === 'blocked'
        ? '<span class="src-badge danger">已拦截</span><small>' + esc(item.block_reason || '未过白User-Agent') + '</small>'
        : '<span class="src-badge success">已允许</span>';
      return '<tr><td>' + time(item.requested_at) + '</td><td><b>' + esc(item.email) + '</b><small>ID: ' + esc(item.user_id) + '</small></td><td class="src-ip">' + esc(item.ip || '–') + '</td><td class="src-user-agent">' + esc(item.user_agent || '未提供 UA') + '</td><td>' + result + '</td></tr>';
    }).join('');
    renderPager('pullLogs', data);
  }
  function loadPullLogs(path, attempt) {
    return api(path).then(function (data) {
      if (data.decision_schema === 1) return data;
      // Do not render an old worker response: it does not contain the
      // authoritative decision and would make intercepted records appear as
      // allowed. Retry briefly in case PHP-FPM is rotating workers.
      if (attempt < 3) {
        return new Promise(function (resolve) { window.setTimeout(resolve, 250); })
          .then(function () { return loadPullLogs(path, attempt + 1); });
      }
      throw new Error('订阅拉取记录服务仍在使用旧版本，请重启 PHP-FPM 或 Octane 后再刷新。');
    });
  }
  function load() {
    document.getElementById('refresh').disabled = true;
    var watchlistPath = '/watchlist?per_page=' + pageSize + '&page=' + pages.watchlist;
    var banlistPath = '/banlist?per_page=' + pageSize + '&page=' + pages.banlist;
    var pullsPath = '/pulls?per_page=' + pageSize + '&page=' + pages.pulls + '&period=' + pullPeriod;
    var pullLogPath = '/pull-logs?per_page=' + pageSize + '&page=' + pages.pullLogs;
    var eventsPath = '/events?per_page=' + pageSize + '&page=' + pages.events;
    if (keywords.watchlist) watchlistPath += '&keyword=' + encodeURIComponent(keywords.watchlist);
    if (keywords.banlist) banlistPath += '&keyword=' + encodeURIComponent(keywords.banlist);
    if (keywords.pulls) pullsPath += '&keyword=' + encodeURIComponent(keywords.pulls);
    if (keywords.pullLogs) pullLogPath += '&keyword=' + encodeURIComponent(keywords.pullLogs);
    if (keywords.events) eventsPath += '&keyword=' + encodeURIComponent(keywords.events);
    Promise.all([api('/summary'), api(watchlistPath), api(banlistPath), api(pullsPath), loadPullLogs(pullLogPath, 0), api(eventsPath)]).then(function (values) {
      var summary = values[0];
      setText('watching', summary.watching);
      setText('today-pulls', summary.today_pulls);
      setText('normal', summary.today_normal);
      setText('high', summary.today_high);
      setText('released', summary.recent_auto_released);
      renderWatchlist(values[1]);
      renderBanlist(values[2]);
      renderPulls(values[3]);
      renderPullLogs(values[4]);
      renderEvents(values[5]);
    }).catch(function (error) {
      showToast(error.message || '加载失败。', true);
      document.getElementById('watchlist').innerHTML = '<tr><td colspan="6" class="src-empty">无法读取数据，请确认已登录管理员后台。</td></tr>';
    }).finally(function () { document.getElementById('refresh').disabled = false; });
  }
  document.getElementById('refresh').addEventListener('click', load);
  document.querySelectorAll('[data-confirm-cancel]').forEach(function (element) {
    element.addEventListener('click', function () { closeConfirm(false); });
  });
  document.getElementById('confirm-submit').addEventListener('click', function () { closeConfirm(true); });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && confirmResolve) closeConfirm(false);
  });
  var mobileBack = document.getElementById('mobile-back');
  if (mobileBack) {
    mobileBack.addEventListener('click', function () {
      window.top.location.assign('/' + String(state.adminPath || '').replace(/^\/+|\/+$/g, '') + '#/');
    });
  }
  function bindEmailSearch(formId, inputId, section) {
    document.getElementById(formId).addEventListener('submit', function (event) {
      event.preventDefault();
      keywords[section] = document.getElementById(inputId).value.trim();
      pages[section] = 1;
      load();
    });
  }
  bindEmailSearch('watchlist-search', 'watchlist-keyword', 'watchlist');
  bindEmailSearch('banlist-search', 'banlist-keyword', 'banlist');
  bindEmailSearch('pulls-search', 'pulls-keyword', 'pulls');
  bindEmailSearch('pull-log-search', 'pull-log-keyword', 'pullLogs');
  bindEmailSearch('events-search', 'events-keyword', 'events');
  document.addEventListener('click', function (event) {
    var pageButton = event.target.closest('.src-page-button');
    if (pageButton && !pageButton.disabled) {
      pages[pageButton.dataset.pageTarget] = Math.max(1, Number(pageButton.dataset.page));
      load();
      return;
    }
    var periodButton = event.target.closest('[data-pull-period]');
    if (periodButton) {
      pullPeriod = periodButton.dataset.pullPeriod === '7d' ? '7d' : 'day';
      pages.pulls = 1;
      document.querySelectorAll('[data-pull-period]').forEach(function (item) {
        item.classList.toggle('active', item.dataset.pullPeriod === pullPeriod);
      });
      load();
      return;
    }
    var button = event.target.closest('.src-release, .src-ban, .src-unban');
    if (!button) return;
    var isBan = button.classList.contains('src-ban');
    var isUnban = button.classList.contains('src-unban');
    var confirmation = isUnban
      ? { title: '确认解封用户？', message: '解封后「' + button.dataset.email + '」可重新登录和使用订阅，并会重新加入风控观察名单。旧 Token 或 UUID 不会恢复。', submit: '确认解封', danger: false }
      : (isBan
      ? { title: '确认封禁用户？', message: '封禁后「' + button.dataset.email + '」将无法登录或使用订阅，并会从观察名单移除。', submit: '确认封禁', danger: true }
      : { title: '确认解除观察？', message: '解除后「' + button.dataset.email + '」将退出风控观察名单。旧 Token 或 UUID 不会恢复。', submit: '确认解除', danger: false });
    requestConfirmation(confirmation.title, confirmation.message, confirmation.submit, confirmation.danger).then(function (confirmed) {
      if (!confirmed) return;
      button.disabled = true;
      api(isUnban ? '/unban' : (isBan ? '/ban' : '/release'), { method: 'POST', body: JSON.stringify({ user_id: Number(button.dataset.id) }) }).then(function (data) {
        showToast(data.message || (isUnban ? '用户已解封。' : (isBan ? '用户已封禁。' : '已解除观察。')));
        load();
      }).catch(function (error) { showToast(error.message || '操作失败。', true); button.disabled = false; });
    });
  });
  load();
}());
