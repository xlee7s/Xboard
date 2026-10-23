(function () {
  'use strict';

  var MOBILE_TRIGGER_ID = 'reward-claim-mobile-trigger';
  var MODAL_ID = 'reward-claim-modal';
  var STYLE_ID = 'reward-claim-plugin-style';
  var API_BASE = (window.routerBase || '/').replace(/\/$/, '') + '/api/v1/reward-claim';
  var arrangeFrame = null;

  function accessToken() {
    try {
      var stored = JSON.parse(localStorage.getItem('VUE_NAIVE_ACCESS_TOKEN') || '{}');
      return stored.value || null;
    } catch (_) {
      return null;
    }
  }

  function request(path, options) {
    var token = accessToken();
    if (!token) return Promise.reject(new Error('登录已过期，请重新登录。'));
    return fetch(API_BASE + path, Object.assign({
      headers: {
        'Authorization': token,
        'Content-Type': 'application/json',
        'Content-Language': document.documentElement.lang || 'zh-CN'
      }
    }, options || {})).then(function (response) {
      return response.text().then(function (text) {
        var body;
        try { body = JSON.parse(text); } catch (_) {
          throw new Error('领取服务暂不可用，请联系管理员更新奖励插件。');
        }
        if (!response.ok || body.status !== 'success') throw new Error(body.message || '请求失败');
        return body.data;
      });
    });
  }

  function formatTime(timestamp) {
    return new Date(timestamp * 1000).toLocaleString('zh-CN', { hour12: false });
  }

  function arrangeRewardTriggers() {
    var isMobile = window.matchMedia('(max-width: 767px)').matches;
    var triggers = Array.prototype.slice.call(document.querySelectorAll('[data-xboard-reward-float]'));
    var totalHeight = triggers.reduce(function (total, trigger) { return total + trigger.offsetHeight; }, 0) + Math.max(0, triggers.length - 1) * 12;
    var offset = isMobile ? 120 : 180;
    var bottomReserve = isMobile ? 120 : 170;
    var top = Math.max(16, Math.min(window.innerHeight - totalHeight - bottomReserve, Math.round((window.innerHeight - totalHeight) / 2) + offset));
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

  function renderClaimRecords(records) {
    if (!Array.isArray(records) || !records.length) {
      return '<li class="reward-claim-records-empty">暂无领取记录</li>';
    }
    return records.slice(0, 5).map(function (record) {
      return '<li><time>' + String(record.date || '') + '</time><strong>' + String(record.traffic || '') + '</strong></li>';
    }).join('');
  }

  function showModal(data) {
    var old = document.getElementById(MODAL_ID);
    if (old) old.remove();
    var modal = document.createElement('div');
    modal.id = MODAL_ID;
    modal.innerHTML = '<div class="reward-claim-mask"></div><section class="reward-claim-card" role="dialog" aria-modal="true">'
      + '<button class="reward-claim-close" type="button" aria-label="关闭">×</button>'
      + '<div class="reward-claim-icon"><span>🎁</span></div><h2></h2><p class="reward-claim-description"></p>'
      + '<div class="reward-claim-amount"><span>随机减免已用流量</span><strong>' + data.reward_range.min + ' <i>—</i> ' + data.reward_range.max + '</strong></div>'
      + '<div class="reward-claim-rules"><div><b>✓ 可领取</b><p>账户正常、套餐未到期，有订单，并达到领取间隔。</p></div>'
      + '<div><b>× 不可领取</b><p>无套餐、到期、封禁、不限时套餐、试用或未到时间。</p></div></div>'
      + '<p class="reward-claim-hint"></p><button class="reward-claim-button" type="button"></button><div class="reward-claim-records"><b>最近领取记录</b><ul></ul></div></section>';
    modal.querySelector('h2').textContent = data.reward_title;
    modal.querySelector('.reward-claim-description').textContent = data.reward_description || '';
    var hint = modal.querySelector('.reward-claim-hint');
    var button = modal.querySelector('.reward-claim-button');
    var claimRecords = modal.querySelector('.reward-claim-records ul');
    claimRecords.innerHTML = renderClaimRecords(data.claim_records);
    button.textContent = data.can_claim ? '立即领取' : '暂不可领取';
    button.disabled = !data.can_claim;
    hint.textContent = data.can_claim ? '领取后会立即减去对应的已用流量。' : (data.eligibility_message || (data.insufficient_used_traffic ? '当前已用流量不足最小随机减免值，暂不可领取。' : ('下次可领取时间：' + formatTime(data.next_claim_at))));
    function close() { modal.remove(); }
    modal.querySelector('.reward-claim-mask').onclick = close;
    modal.querySelector('.reward-claim-close').onclick = close;
    button.onclick = function () {
      button.disabled = true;
      button.textContent = '领取中…';
      request('/claim', { method: 'POST', body: '{}' }).then(function (result) {
        var rules = modal.querySelector('.reward-claim-rules');
        if (rules) rules.remove();
        var success = document.createElement('div');
        success.className = 'reward-claim-success';
        success.innerHTML = '<span class="reward-claim-success-icon">✓</span><div><b>领取成功</b><p></p><small></small></div>';
        success.querySelector('p').textContent = result.message;
        success.querySelector('small').textContent = '下次可领取：' + formatTime(result.next_claim_at);
        hint.replaceWith(success);
        data.claim_records = [result.claim_record].concat(data.claim_records || []).slice(0, 5);
        claimRecords.innerHTML = renderClaimRecords(data.claim_records);
        button.disabled = false;
        button.textContent = '完成';
        button.onclick = close;
      }).catch(function (error) {
        hint.textContent = error.message;
        button.textContent = '重试领取';
        button.disabled = false;
      });
    };
    document.body.appendChild(modal);
  }

  function openReward() {
    request('/status').then(showModal).catch(function (error) { window.alert(error.message); });
  }

  function addMobileTrigger() {
    if (!accessToken()) return;
    var existing = document.getElementById(MOBILE_TRIGGER_ID);
    if (existing) {
      scheduleRewardTriggers();
      return;
    }
    var trigger = document.createElement('button');
    trigger.id = MOBILE_TRIGGER_ID;
    trigger.type = 'button';
    trigger.innerHTML = '<span>🎁</span> 每日流量';
    trigger.setAttribute('data-xboard-reward-float', 'daily');
    trigger.setAttribute('aria-label', '每日流量');
    trigger.addEventListener('click', openReward);
    document.body.appendChild(trigger);
    scheduleRewardTriggers();
  }

  function addStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = '#reward-claim-mobile-trigger{display:block;position:fixed;right:24px;bottom:24px;z-index:9997;border:0;border-radius:999px;padding:12px 17px;background:linear-gradient(135deg,#16a34a,#22c55e);box-shadow:0 10px 24px rgba(22,163,74,.35);color:#fff;font-size:14px;font-weight:700;cursor:pointer;opacity:.78;transition:opacity .2s,transform .2s}#reward-claim-mobile-trigger:hover,#reward-claim-mobile-trigger:focus{opacity:1;transform:translateY(-2px)}#reward-claim-mobile-trigger span{font-size:17px;vertical-align:-1px}.reward-claim-mask{position:fixed;inset:0;background:rgba(15,23,42,.56);backdrop-filter:blur(3px);z-index:9998}.reward-claim-card{position:fixed;z-index:9999;left:50%;top:50%;width:min(390px,calc(100vw - 32px));transform:translate(-50%,-50%);border-radius:22px;background:linear-gradient(180deg,#fff 0%,#f8fafc 100%);padding:24px 24px 22px;text-align:center;box-shadow:0 24px 70px rgba(15,23,42,.35);color:#1e293b}.dark .reward-claim-card{background:linear-gradient(180deg,#25272d 0%,#1d1e22 100%);color:#f8fafc}.reward-claim-close{position:absolute;right:14px;top:9px;border:0;background:transparent;font-size:28px;cursor:pointer;color:inherit}.reward-claim-icon{margin:auto;width:56px;height:56px;display:grid;place-items:center;border-radius:18px;background:linear-gradient(135deg,#dcfce7,#bbf7d0);box-shadow:0 8px 18px rgba(22,163,74,.18);font-size:32px}.reward-claim-card h2{font-size:21px;margin:14px 0 6px}.reward-claim-description,.reward-claim-hint{font-size:13px;color:#64748b;line-height:1.6}.reward-claim-amount{margin:18px 0 14px;padding:13px;border-radius:14px;background:linear-gradient(135deg,#ecfdf5,#f0fdf4);color:#15803d}.reward-claim-amount span{display:block;font-size:12px;font-weight:600;letter-spacing:.04em}.reward-claim-amount strong{display:block;font-size:23px;margin-top:3px}.reward-claim-amount i{font-style:normal;color:#86efac}.reward-claim-rules{display:grid;gap:8px;margin:14px 0;text-align:left;font-size:12px}.reward-claim-rules>div{padding:9px 11px;border-radius:10px;background:#f1f5f9}.dark .reward-claim-rules>div{background:#303134}.reward-claim-rules b{font-size:13px}.reward-claim-rules>div:first-child b{color:#15803d}.reward-claim-rules>div:last-child b{color:#dc2626}.reward-claim-rules p{margin:3px 0 0;line-height:1.5;color:#64748b}.reward-claim-success{display:flex;align-items:center;gap:11px;margin:16px 0;padding:12px 14px;text-align:left;border:1px solid #bbf7d0;border-radius:14px;background:#f0fdf4}.reward-claim-success-icon{display:grid;place-items:center;flex:0 0 30px;width:30px;height:30px;border-radius:50%;background:#22c55e;color:#fff;font-weight:800}.reward-claim-success b{display:block;color:#15803d;font-size:14px}.reward-claim-success p{margin:2px 0;color:#166534;font-size:13px;line-height:1.5}.reward-claim-success small{color:#64748b;font-size:12px}.dark .reward-claim-success{background:#183522;border-color:#28613d}.dark .reward-claim-success p{color:#bbf7d0}.reward-claim-button{width:100%;border:0;border-radius:11px;padding:12px 16px;background:linear-gradient(135deg,#16a34a,#22c55e);box-shadow:0 8px 16px rgba(22,163,74,.22);color:#fff;font-size:16px;font-weight:600;cursor:pointer}.reward-claim-button:disabled{background:#94a3b8;box-shadow:none;cursor:not-allowed}@media(max-width:767px){#reward-claim-mobile-trigger{right:18px;bottom:22px;padding:11px 15px}}';
    style.textContent += '#reward-claim-mobile-trigger{top:50%;bottom:auto}@media(max-width:767px){#reward-claim-mobile-trigger{top:50%;bottom:auto}}';
    style.textContent += '.reward-claim-records{margin-top:14px;text-align:left}.reward-claim-records>b{display:block;margin-bottom:6px;color:#475569;font-size:12px}.dark .reward-claim-records>b{color:#cbd5e1}.reward-claim-records ul{margin:0;padding:0;list-style:none;border-radius:10px;background:#f1f5f9;overflow:hidden}.dark .reward-claim-records ul{background:#303134}.reward-claim-records li{display:flex;justify-content:space-between;gap:12px;padding:8px 11px;border-top:1px solid rgba(148,163,184,.2);font-size:12px}.reward-claim-records li:first-child{border-top:0}.reward-claim-records time{color:#64748b}.reward-claim-records strong{color:#15803d}.reward-claim-records-empty{display:block!important;color:#94a3b8;text-align:center}';
    document.head.appendChild(style);
  }

  addStyle();
  function syncEntryPoints() {
    if (!accessToken()) {
      var mobileTrigger = document.getElementById(MOBILE_TRIGGER_ID);
      if (mobileTrigger) mobileTrigger.remove();
      scheduleRewardTriggers();
      return;
    }
    addMobileTrigger();
  }
  syncEntryPoints();
  new MutationObserver(syncEntryPoints).observe(document.documentElement, { childList: true, subtree: true });
  window.addEventListener('resize', scheduleRewardTriggers);
}());
