<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }}</title>
  <link rel="stylesheet" href="/plugins/subscription_risk_control/admin-dashboard.css?v=2.2.5">
  <link rel="stylesheet" href="/plugins/subscription_risk_control/admin-dashboard-extra.css?v=2.2.5">
</head>
<body>
  <main class="src-page">
    <header class="src-header">
      <div>
        <h1>订阅封控</h1>
        <p>订阅拉取异常检测、观察名单与人工解除</p>
      </div>
      <div class="src-header-actions">
        @if ($embedded)<button class="src-mobile-back" id="mobile-back" type="button">← 返回仪表盘</button>@endif
        <button class="src-refresh" id="refresh" type="button">刷新数据</button>
      </div>
    </header>

    <section class="src-stats" aria-label="风控统计">
      <article><span>当前观察</span><strong id="watching">–</strong><i class="violet">重点关注用户</i></article>
      <article><span>今日拉取次数</span><strong id="today-pulls">–</strong><i class="violet">全部有效订阅拉取</i></article>
      <article><span>今日普通异常</span><strong id="normal">–</strong><i>仅重置 Token</i></article>
      <article><span>今日高危异常</span><strong id="high">–</strong><i class="danger">重置 Token + UUID</i></article>
      <article><span>近 7 天自动解除</span><strong id="released">–</strong><i class="success">已恢复普通监控</i></article>
    </section>

    <section class="src-panel">
      <div class="src-panel-head"><div><h2>风控观察名单</h2><p>名单用户仍可使用新订阅链接，但会被持续重点监控。</p></div><div class="src-log-tools"><form id="watchlist-search" class="src-search"><input id="watchlist-keyword" type="search" maxlength="255" placeholder="搜索邮箱"><button type="submit">查询</button></form><span class="src-count" id="watch-count"></span></div></div>
      <div class="src-table-wrap"><table><thead><tr><th>用户</th><th>最近处理</th><th>最近命中原因</th><th>命中次数</th><th>最近异常</th><th>操作</th></tr></thead><tbody id="watchlist"><tr><td colspan="6" class="src-empty">正在加载…</td></tr></tbody></table></div>
      <div class="src-pager" id="watchlist-pager"></div>
    </section>

    <section class="src-panel">
      <div class="src-panel-head"><div><h2>风控封禁名单</h2><p>已封禁用户无法登录或使用订阅；解封后会回到观察名单继续监控。</p></div><div class="src-log-tools"><form id="banlist-search" class="src-search"><input id="banlist-keyword" type="search" maxlength="255" placeholder="搜索邮箱"><button type="submit">查询</button></form><span class="src-count" id="ban-count"></span></div></div>
      <div class="src-table-wrap"><table><thead><tr><th>用户</th><th>命中原因</th><th>命中次数</th><th>封禁时间</th><th>操作</th></tr></thead><tbody id="banlist"><tr><td colspan="5" class="src-empty">正在加载…</td></tr></tbody></table></div>
      <div class="src-pager" id="banlist-pager"></div>
    </section>

    <section class="src-panel">
      <div class="src-panel-head"><div><h2 id="pulls-title">今日订阅拉取统计</h2><p id="pulls-description">按用户汇总今日有效订阅拉取次数，并按次数从高到低排列。</p></div><div class="src-log-tools"><div class="src-toolbar" aria-label="拉取统计范围"><button class="src-period active" type="button" data-pull-period="day">今日</button><button class="src-period" type="button" data-pull-period="7d">近 7 天</button></div><form id="pulls-search" class="src-search"><input id="pulls-keyword" type="search" maxlength="255" placeholder="搜索邮箱"><button type="submit">查询</button></form><span class="src-count" id="pull-count"></span></div></div>
      <div class="src-table-wrap"><table><thead><tr><th>排名</th><th>用户</th><th>拉取次数</th><th>不同 IP</th><th>不同 UA</th><th>最近拉取</th></tr></thead><tbody id="pulls"><tr><td colspan="6" class="src-empty">正在加载…</td></tr></tbody></table></div>
      <div class="src-pager" id="pulls-pager"></div>
    </section>

    <section class="src-panel">
      <div class="src-panel-head"><div><h2>订阅拉取记录</h2><p>保存每一次有效订阅拉取，保留时长可在插件配置页设置。</p></div><div class="src-log-tools"><form id="pull-log-search" class="src-search"><input id="pull-log-keyword" type="search" maxlength="255" placeholder="搜索邮箱"><button type="submit">查询</button></form><span class="src-count" id="pull-log-count"></span></div></div>
      <div class="src-table-wrap"><table><thead><tr><th>拉取时间</th><th>用户</th><th>IP 地址</th><th>User-Agent（UA）</th><th>结果</th></tr></thead><tbody id="pull-logs"><tr><td colspan="5" class="src-empty">正在加载…</td></tr></tbody></table></div>
      <div class="src-pager" id="pull-logs-pager"></div>
    </section>

    <section class="src-grid">
      <section class="src-panel src-events">
        <div class="src-panel-head"><div><h2>最近风控事件</h2><p>保留异常、重置、解除与管理员操作记录。</p></div><div class="src-log-tools"><form id="events-search" class="src-search"><input id="events-keyword" type="search" maxlength="255" placeholder="搜索邮箱"><button type="submit">查询</button></form></div></div>
        <div class="src-table-wrap"><table><thead><tr><th>时间</th><th>用户</th><th>处理结果</th><th>操作管理员</th><th>IP</th><th>原因</th></tr></thead><tbody id="events"><tr><td colspan="6" class="src-empty">正在加载…</td></tr></tbody></table></div>
        <div class="src-pager" id="events-pager"></div>
      </section>
      <aside class="src-panel src-rules"><h2>规则说明</h2><div class="src-rule"><b>普通异常</b><p>命中普通阈值后，仅重置订阅 Token。</p></div><div class="src-rule high"><b>高危异常</b><p>命中高危阈值后，同时重置 Token 与节点认证 UUID。</p></div><div class="src-rule"><b>自动解除</b><p>从最近一次异常开始，连续 7 天未再次命中规则，将自动退出观察名单。</p></div><p class="src-tip">阈值和白名单均在插件配置页设置。</p></aside>
    </section>
  </main>
  <div class="src-confirm-modal" id="confirm-modal" hidden aria-hidden="true">
    <div class="src-confirm-backdrop" data-confirm-cancel></div>
    <section class="src-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-message">
      <div class="src-confirm-icon" id="confirm-icon">!</div>
      <div><h2 id="confirm-title">确认操作</h2><p id="confirm-message"></p></div>
      <div class="src-confirm-actions"><button class="src-confirm-cancel" data-confirm-cancel type="button">取消</button><button class="src-confirm-submit" id="confirm-submit" type="button">确认</button></div>
    </section>
  </div>
  <div class="src-toast" id="toast" role="status"></div>
  <script>window.SUBSCRIPTION_RISK_CONTROL = { adminPath: @json($admin_path), embedded: @json($embedded) };</script>
  <script src="/plugins/subscription_risk_control/admin-dashboard.js?v=2.2.5" defer></script>
</body>
</html>
