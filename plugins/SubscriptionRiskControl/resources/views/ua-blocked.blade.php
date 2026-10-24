<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>订阅请求已拦截 · {{ $app_name }}</title>
  <style>
    :root{font-family:Inter,"Microsoft YaHei",system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1e293b;background:#f4f6fb;color-scheme:light}
    *{box-sizing:border-box}body{min-height:100vh;margin:0;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at 7% 8%,rgba(124,58,237,.12),transparent 32rem),radial-gradient(circle at 93% 94%,rgba(14,165,233,.10),transparent 34rem),#f5f7fc}
    .card{width:min(100%,620px);overflow:hidden;border:1px solid #e5e8f1;border-radius:24px;background:#fff;box-shadow:0 24px 65px rgba(27,39,73,.12)}
    .top{height:7px;background:linear-gradient(90deg,#7c3aed,#a855f7 52%,#22c55e)}.content{padding:43px 48px 38px}.mark{width:56px;height:56px;display:grid;place-items:center;border-radius:18px;background:#f3edff;color:#7c3aed;font-weight:900;font-size:29px;box-shadow:inset 0 0 0 1px #e4d8ff}
    .eyebrow{margin:25px 0 7px;color:#7c3aed;font-size:13px;font-weight:800;letter-spacing:.08em}.title{margin:0;color:#172554;font-size:28px;line-height:1.25;letter-spacing:-.02em}.copy{margin:16px 0 0;color:#64748b;font-size:15px;line-height:1.8}.notice{display:flex;gap:11px;margin-top:25px;padding:15px 16px;border:1px solid #fde1dc;border-radius:14px;background:#fff8f7;color:#9f3131;font-size:14px;line-height:1.6}.notice b{color:#c43a3a;white-space:nowrap}.guide{display:grid;grid-template-columns:auto 1fr;gap:13px;margin-top:22px;padding-top:23px;border-top:1px solid #edf0f5}.guide-icon{width:31px;height:31px;display:grid;place-items:center;border-radius:10px;background:#eef6ff;color:#2563eb;font-weight:800}.guide h2{margin:1px 0 5px;color:#283655;font-size:15px}.guide p{margin:0;color:#71809a;font-size:13px;line-height:1.65}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:30px}.button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border-radius:10px;padding:0 17px;text-decoration:none;font-size:14px;font-weight:800}.primary{background:#7c3aed;color:#fff;box-shadow:0 8px 18px rgba(124,58,237,.22)}.secondary{border:1px solid #dce1ed;color:#5d6a82;background:#fff}.footer{padding:15px 48px;border-top:1px solid #eef0f5;background:#fafbfe;color:#98a2b5;font-size:12px}@media(max-width:560px){body{padding:16px}.content{padding:31px 24px 27px}.footer{padding:14px 24px}.title{font-size:24px}.actions{display:grid;grid-template-columns:1fr}.button{width:100%}}
  </style>
</head>
<body>
  <main class="card" role="main">
    <div class="top"></div>
    <section class="content">
      <div class="mark" aria-hidden="true">!</div>
      <p class="eyebrow">订阅访问保护 · 403</p>
      <h1 class="title">此订阅链接只能用于订阅软件</h1>
      <p class="copy">为保护订阅链接安全，此链接仅限受支持的订阅软件使用。当前访问方式无法获取订阅内容。</p>
      <div class="notice"><b>提示</b><span>这不会重置你的订阅链接，也不会影响已正常导入的设备。</span></div>
      <div class="guide"><div class="guide-icon" aria-hidden="true">i</div><div><h2>如何继续</h2><p>请使用订阅软件重新导入或更新订阅；若仍无法使用，请联系管理员确认该订阅软件是否受支持。</p></div></div>
    </section>
    <footer class="footer">{{ $app_name }} · 订阅链接访问保护</footer>
  </main>
</body>
</html>
