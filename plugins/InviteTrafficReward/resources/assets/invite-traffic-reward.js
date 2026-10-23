(function () {
  'use strict';

  var TRIGGER_ID = 'invite-traffic-reward-trigger';
  var MODAL_ID = 'invite-traffic-reward-modal';
  var STYLE_ID = 'invite-traffic-reward-style';
  var API_BASE = (window.routerBase || '/').replace(/\/$/, '') + '/api/v1/invite-traffic-reward';
  var arrangeFrame = null;

  function token() {
    try { return (JSON.parse(localStorage.getItem('VUE_NAIVE_ACCESS_TOKEN') || '{}')).value || null; } catch (_) { return null; }
  }

  function request(path, options) {
    var accessToken = token();
    if (!accessToken) return Promise.reject(new Error('登录已过期，请重新登录。'));
    return fetch(API_BASE + path, Object.assign({
      headers: { 'Authorization': accessToken, 'Content-Type': 'application/json', 'Content-Language': document.documentElement.lang || 'zh-CN' }
    }, options || {})).then(function (response) {
      return response.text().then(function (text) {
        var body;
        try { body = JSON.parse(text); } catch (_) { throw new Error('邀请奖励服务暂不可用，请联系管理员检查插件。'); }
        if (!response.ok || body.status !== 'success') throw new Error(body.message || '请求失败');
        return body.data;
      });
    });
  }

  function closeModal() { var modal = document.getElementById(MODAL_ID); if (modal) modal.remove(); }

  function arrangeRewardTriggers() {
    var isMobile = window.matchMedia('(max-width: 767px)').matches;
    var triggers = Array.prototype.slice.call(document.querySelectorAll('[data-xboard-reward-float]'));
    var totalHeight = triggers.reduce(function (total, trigger) { return total + trigger.offsetHeight; }, 0) + Math.max(0, triggers.length - 1) * 12;
    var top = Math.max(16, Math.round((window.innerHeight - totalHeight) / 2));
    triggers.reverse().forEach(function (trigger) {
      trigger.style.left = 'auto';
      trigger.style.right = isMobile ? '18px' : '24px';
      trigger.style.top = top + 'px';
      trigger.style.bottom = 'auto';
      top += trigger.offsetHeight + 12;
    });
  }

  function scheduleRewardTriggers() {
    if (arrangeFrame !== null) return;
    arrangeFrame = window.requestAnimationFrame(function () {
      arrangeFrame = null;
      arrangeRewardTriggers();
    });
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>'"]/g, function (character) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[character];
    });
  }

  function renderRecords(records, emptyText, textKey) {
    if (!Array.isArray(records) || !records.length) return '<li class="itr-record-empty">' + escapeHtml(emptyText) + '</li>';
    return records.map(function (record) {
      return '<li><div><strong>' + escapeHtml(record.email) + '</strong><span>' + escapeHtml(record.time) + '</span></div><em>' + escapeHtml(record[textKey]) + '</em></li>';
    }).join('');
  }

  function claim(modal, data, mode) {
    var actions = modal.querySelector('.itr-actions');
    var hint = modal.querySelector('.itr-hint');
    actions.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
    hint.hidden = false;
    hint.textContent = '领取中…';
    request('/claim', { method: 'POST', body: JSON.stringify({ mode: mode }) }).then(function (result) {
      data.pending_count = result.remaining_count;
      data.claimed_count = Number(data.claimed_count || 0) + 1;
      modal.querySelector('.itr-claimed').textContent = data.claimed_count;
      modal.querySelector('.itr-pending').textContent = result.remaining_count;
      hint.className = 'itr-hint itr-success';
      hint.textContent = result.message + (result.remaining_count ? ' 还可领取 ' + result.remaining_count + ' 次。' : ' 所有有效邀请均已领取。');
      actions.innerHTML = result.remaining_count ? '<button type="button" class="itr-again">继续领取</button>' : '<button type="button" class="itr-done">完成</button>';
      actions.querySelector('button').onclick = result.remaining_count ? function () { closeModal(); openReward(); } : closeModal;
    }).catch(function (error) {
      hint.textContent = error.message;
      actions.querySelectorAll('button').forEach(function (button) { button.disabled = false; });
    });
  }

  function showModal(data) {
    closeModal();
    var modal = document.createElement('div');
    modal.id = MODAL_ID;
    modal.innerHTML = '<div class="itr-mask"></div><section class="itr-card" role="dialog" aria-modal="true">'
      + '<button class="itr-close" type="button" aria-label="关闭">×</button><div class="itr-icon">🎉</div><h2></h2><p class="itr-description"></p>'
      + '<div class="itr-stats"><div><strong class="itr-invited"></strong><span>已邀请</span></div><div><strong class="itr-valid"></strong><span>有效邀请</span></div><div><strong class="itr-claimed"></strong><span>已领取</span></div><div><strong class="itr-pending"></strong><span>待领取</span></div></div><div class="itr-reward"></div><p class="itr-rule"></p><p class="itr-hint"></p><div class="itr-actions"></div><div class="itr-records"><details><summary>邀请记录（最近 5 条）</summary><ul class="itr-invite-records"></ul></details><details><summary>领取记录（最近 5 条）</summary><ul class="itr-claim-records"></ul></details></div></section>';
    modal.querySelector('h2').textContent = data.reward_title;
    modal.querySelector('.itr-description').textContent = data.reward_description || '';
    modal.querySelector('.itr-invited').textContent = data.invited_count;
    modal.querySelector('.itr-valid').textContent = data.valid_invite_count;
    modal.querySelector('.itr-claimed').textContent = data.claimed_count;
    modal.querySelector('.itr-pending').textContent = data.pending_count;
    modal.querySelector('.itr-invite-records').innerHTML = renderRecords(data.invite_records, '暂无邀请记录', 'status');
    modal.querySelector('.itr-claim-records').innerHTML = renderRecords(data.claim_records, '暂无领取记录', 'reward');
    modal.querySelector('.itr-close').onclick = closeModal;
    modal.querySelector('.itr-mask').onclick = closeModal;

    var reward = modal.querySelector('.itr-reward');
    var rule = modal.querySelector('.itr-rule');
    var hint = modal.querySelector('.itr-hint');
    var actions = modal.querySelector('.itr-actions');
    var showHint = !data.can_claim || (data.account_type === 'periodic' && !data.can_reduce_traffic);
    hint.hidden = !showHint;
    hint.textContent = showHint ? data.message : '';
    if (data.account_type === 'none') {
      reward.innerHTML = '<span>领取奖励</span><strong>指定不限时套餐</strong><em>并发放 ' + data.reward_traffic + ' 总流量</em>';
      rule.textContent = data.no_plan_reason || '领取后会发放后台设置的不限时套餐和奖励总流量，并消耗 1 次有效邀请奖励。';
      actions.innerHTML = '<button type="button" class="itr-primary">领取不限时套餐</button>';
      actions.querySelector('button').onclick = function () { claim(modal, data, 'plan'); };
    } else if (data.account_type === 'onetime') {
      reward.innerHTML = '<span>每位有效邀请可增加</span><strong>' + data.reward_traffic + ' 总流量</strong>';
      rule.textContent = '领取后直接增加套餐总流量，不减免已用流量。';
      actions.innerHTML = '<button type="button" class="itr-primary">领取总流量</button>';
      actions.querySelector('button').onclick = function () { claim(modal, data, 'quota'); };
    } else {
      reward.innerHTML = '<span>每位有效邀请奖励</span><strong>' + data.reward_traffic + '</strong><em>或延长 ' + data.extension_duration + '</em>';
      rule.textContent = '请选择一种方式领取。本次选择不会影响之后其他邀请奖励的领取方式。';
      actions.innerHTML = '<button type="button" class="itr-secondary">减免已用流量</button><button type="button" class="itr-primary">延长套餐有效期</button>';
      actions.querySelector('.itr-secondary').onclick = function () { claim(modal, data, 'traffic'); };
      actions.querySelector('.itr-primary').onclick = function () { claim(modal, data, 'extension'); };
      if (!data.can_reduce_traffic) {
        actions.querySelector('.itr-secondary').disabled = true;
      }
    }
    if (!data.can_claim) {
      actions.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
    }
    document.body.appendChild(modal);
  }

  function openReward() { request('/status').then(showModal).catch(function (error) { window.alert(error.message); }); }

  function syncTrigger() {
    var existing = document.getElementById(TRIGGER_ID);
    if (!token()) {
      if (existing) existing.remove();
      scheduleRewardTriggers();
      return;
    }
    if (existing) {
      scheduleRewardTriggers();
      return;
    }
    var trigger = document.createElement('button');
    trigger.id = TRIGGER_ID;
    trigger.type = 'button';
    trigger.innerHTML = '<span>🎁</span> 邀请奖励';
    trigger.setAttribute('aria-label', '领取邀请奖励');
    trigger.setAttribute('data-xboard-reward-float', 'invite');
    trigger.onclick = openReward;
    document.body.appendChild(trigger);
    scheduleRewardTriggers();
  }

  function addStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = '#invite-traffic-reward-trigger{position:fixed;right:24px;top:50%;bottom:auto;z-index:9996;border:0;border-radius:999px;padding:12px 17px;background:linear-gradient(135deg,#7c3aed,#a855f7);box-shadow:0 10px 25px rgba(124,58,237,.32);color:#fff;font-size:14px;font-weight:700;cursor:pointer;opacity:.8;transition:.2s}#invite-traffic-reward-trigger:hover{opacity:1;transform:translateY(-2px)}#invite-traffic-reward-trigger span{font-size:17px}.itr-mask{position:fixed;inset:0;z-index:9998;background:rgba(15,23,42,.58);backdrop-filter:blur(3px)}.itr-card{position:fixed;left:50%;top:50%;z-index:9999;width:min(395px,calc(100vw - 32px));max-height:calc(100vh - 32px);overflow-y:auto;transform:translate(-50%,-50%);padding:25px;text-align:center;color:#1e293b;border-radius:22px;background:linear-gradient(160deg,#fff,#f8fafc);box-shadow:0 25px 70px rgba(15,23,42,.35)}.dark .itr-card{color:#f8fafc;background:linear-gradient(160deg,#292a31,#1d1e22)}.itr-close{position:absolute;right:14px;top:8px;border:0;background:none;color:inherit;font-size:28px;cursor:pointer}.itr-icon{display:grid;place-items:center;width:56px;height:56px;margin:auto;border-radius:18px;background:linear-gradient(135deg,#ede9fe,#ddd6fe);font-size:31px}.itr-card h2{margin:14px 0 6px;font-size:21px}.itr-description,.itr-hint,.itr-rule{font-size:13px;line-height:1.6;color:#64748b}.itr-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin:18px 0 12px}.itr-stats>div{padding:8px 3px;border-radius:10px;background:#f1f5f9}.dark .itr-stats>div{background:#313238}.itr-stats strong,.itr-stats span{display:block}.itr-stats strong{color:#7c3aed;font-size:19px}.itr-stats span{margin-top:2px;color:#64748b;font-size:11px}.itr-reward{padding:14px;border-radius:14px;background:linear-gradient(135deg,#f5f3ff,#faf5ff);color:#6d28d9}.dark .itr-reward{background:#312b4b}.itr-reward span,.itr-reward em{display:block;font-size:12px;font-style:normal}.itr-reward strong{display:block;margin:3px 0;font-size:21px}.itr-rule{margin:14px 0}.itr-hint{min-height:21px;margin:10px 0}.itr-success{color:#15803d;font-weight:600}.itr-actions{display:flex;gap:9px}.itr-actions button{flex:1;border:0;border-radius:11px;padding:12px 10px;color:#fff;font-size:14px;font-weight:700;cursor:pointer}.itr-primary,.itr-secondary,.itr-again,.itr-done{background:linear-gradient(135deg,#7c3aed,#a855f7);box-shadow:0 8px 17px rgba(124,58,237,.22)}.itr-secondary{background:linear-gradient(135deg,#5b21b6,#7c3aed)}.itr-actions button:disabled{background:#94a3b8;box-shadow:none;cursor:not-allowed}.itr-records{margin-top:14px;text-align:left}.itr-records details{margin-top:7px;border-radius:10px;background:#f1f5f9;overflow:hidden}.dark .itr-records details{background:#313238}.itr-records summary{padding:10px 12px;color:#475569;font-size:12px;font-weight:700;cursor:pointer}.dark .itr-records summary{color:#cbd5e1}.itr-records ul{margin:0;padding:0;list-style:none}.itr-records li{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:9px 12px;border-top:1px solid rgba(148,163,184,.2);font-size:12px}.itr-records li div{min-width:0}.itr-records li strong,.itr-records li span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.itr-records li strong{color:#334155}.dark .itr-records li strong{color:#e2e8f0}.itr-records li span{margin-top:2px;color:#94a3b8;font-size:11px}.itr-records li em{max-width:53%;color:#7c3aed;font-style:normal;text-align:right}.itr-records .itr-record-empty{display:block;color:#94a3b8;text-align:center}@media(max-width:767px){#invite-traffic-reward-trigger{right:18px;top:50%;bottom:auto;padding:11px 15px}.itr-stats{gap:4px}.itr-stats strong{font-size:17px}.itr-stats span{font-size:10px}}';
    document.head.appendChild(style);
  }

  addStyle();
  syncTrigger();
  new MutationObserver(syncTrigger).observe(document.documentElement, { childList: true, subtree: true });
  window.addEventListener('resize', scheduleRewardTriggers);
}());
