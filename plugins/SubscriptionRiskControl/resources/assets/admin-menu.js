(function () {
  'use strict';

  // Reuse Xboard's existing plugin-management route. A standalone hash route
  // would be rejected by the compiled admin SPA after a browser refresh.
  var dashboardHash = '#/config/plugin?subscription-risk-control=1';

  function findPluginManagementItem() {
    return Array.prototype.slice.call(document.querySelectorAll('a, [role="link"]')).find(function (node) {
      return (node.textContent || '').replace(/\s+/g, '') === '插件管理';
    }) || null;
  }

  function addMenu() {
    if (document.getElementById('subscription-risk-control-menu-link') || !window.settings || !window.settings.secure_path) return;
    var pluginItem = findPluginManagementItem();
    if (!pluginItem) return;

    var securePath = String(window.settings.secure_path).replace(/^\/+|\/+$/g, '');
    var link = pluginItem.cloneNode(true);
    link.id = 'subscription-risk-control-menu-link';
    link.href = '/' + securePath + '/subscription-risk-control';
    link.setAttribute('aria-label', '订阅封控管理');
    link.removeAttribute('aria-current');
    link.removeAttribute('data-active');
    // The source item can be the currently selected plugin page. Start clear;
    // the highlight is applied only while this console is actually open.
    link.style.background = 'transparent';
    link.style.boxShadow = 'none';
    var icon = link.querySelector('svg');
    if (icon) {
      // Keep the admin's icon sizing classes, but use a distinct shield for
      // subscription risk control instead of the plugin-management cube.
      icon.setAttribute('viewBox', '0 0 24 24');
      icon.setAttribute('fill', 'none');
      icon.setAttribute('stroke', 'currentColor');
      icon.setAttribute('stroke-width', '2');
      icon.setAttribute('stroke-linecap', 'round');
      icon.setAttribute('stroke-linejoin', 'round');
      icon.innerHTML = '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="M12 8v4"></path><path d="M12 16h.01"></path>';
    }
    var textNode = Array.prototype.slice.call(link.childNodes).find(function (node) {
      return node.nodeType === Node.TEXT_NODE && (node.nodeValue || '').indexOf('插件管理') !== -1;
    });
    if (textNode) {
      textNode.nodeValue = textNode.nodeValue.replace('插件管理', '订阅封控');
    } else {
      var textElement = Array.prototype.slice.call(link.querySelectorAll('*')).find(function (node) {
        return (node.textContent || '').replace(/\s+/g, '') === '插件管理';
      });
      if (textElement) textElement.textContent = '订阅封控';
    }

    pluginItem.insertAdjacentElement('afterend', link);
    link.addEventListener('click', function (event) {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;
      event.preventDefault();
      if (window.location.hash !== dashboardHash) {
        history.pushState(null, '', window.location.pathname + window.location.search + dashboardHash);
      }
      syncDashboardRoute();
    });

    syncDashboardRoute();
  }

  function setMenuSelected(selected) {
    var link = document.getElementById('subscription-risk-control-menu-link');
    if (!link) return;
    link.style.setProperty('background', selected ? '#eef2f7' : 'transparent', 'important');
    link.setAttribute('aria-current', selected ? 'page' : 'false');

    var sidebar = document.querySelector('aside, [data-sidebar], [role="navigation"]');
    var items = Array.prototype.slice.call((sidebar || document).querySelectorAll('a, [role="link"]'));
    items.forEach(function (item) {
      if (item === link) return;
      if (selected) {
        if (!item.dataset.subscriptionRiskBackground) {
          item.dataset.subscriptionRiskBackground = JSON.stringify([
            item.style.getPropertyValue('background'),
            item.style.getPropertyPriority('background'),
            item.style.getPropertyValue('box-shadow'),
            item.style.getPropertyPriority('box-shadow')
          ]);
        }
        item.style.setProperty('background', 'transparent', 'important');
        item.style.setProperty('box-shadow', 'none', 'important');
        return;
      }
      if (!item.dataset.subscriptionRiskBackground) return;
      var original = JSON.parse(item.dataset.subscriptionRiskBackground);
      if (original[0]) item.style.setProperty('background', original[0], original[1]);
      else item.style.removeProperty('background');
      if (original[2]) item.style.setProperty('box-shadow', original[2], original[3]);
      else item.style.removeProperty('box-shadow');
      delete item.dataset.subscriptionRiskBackground;
    });
  }

  function openDashboard(url) {
    var existing = document.getElementById('subscription-risk-control-frame');
    if (existing) {
      existing.querySelector('iframe').src = url;
      layoutDashboard(existing);
      return;
    }
    var shell = document.createElement('section');
    shell.id = 'subscription-risk-control-frame';
    shell.style.cssText = 'position:fixed;z-index:9999;background:#f6f7fb';
    shell.innerHTML = '<iframe title="订阅封控" src="' + url.replace(/"/g, '&quot;') + '" style="display:block;width:100%;height:100%;border:0;background:#f6f7fb"></iframe>';
    document.body.appendChild(shell);
    layoutDashboard(shell);
  }

  function layoutDashboard(shell) {
    if (window.matchMedia('(max-width: 767px)').matches) {
      shell.style.cssText = 'position:fixed;z-index:9999;inset:0;background:#f6f7fb';
      return;
    }
    var sidebar = document.querySelector('aside, [data-sidebar], [role="navigation"]');
    var left = sidebar ? Math.max(0, Math.round(sidebar.getBoundingClientRect().right)) : 252;
    shell.style.cssText = 'position:fixed;z-index:9999;left:' + left + 'px;right:0;top:0;bottom:0;background:#f6f7fb;border-left:1px solid #e7e9f0';
  }

  function closeDashboard() {
    var shell = document.getElementById('subscription-risk-control-frame');
    if (shell) shell.remove();
    setMenuSelected(false);
  }

  function syncDashboardRoute() {
    var link = document.getElementById('subscription-risk-control-menu-link');
    if (!link) return;
    if (window.location.hash === dashboardHash) {
      setMenuSelected(true);
      openDashboard(link.href + '?embedded=1');
      return;
    }
    closeDashboard();
  }

  // Behave like a real admin page: selecting another navigation item replaces
  // this right-side content and clears this item's selected state.
  document.addEventListener('click', function (event) {
    var target = event.target.closest('a, [role="link"]');
    if (!target || target.id === 'subscription-risk-control-menu-link') return;
    if (document.getElementById('subscription-risk-control-frame')) closeDashboard();
  }, true);

  window.addEventListener('resize', function () {
    var shell = document.getElementById('subscription-risk-control-frame');
    if (shell) layoutDashboard(shell);
  });

  // Make the URL behave like the existing hash-routed admin pages: refresh,
  // browser back and browser forward all restore the matching page state.
  window.addEventListener('hashchange', syncDashboardRoute);
  window.addEventListener('message', function (event) {
    if (event.origin !== window.location.origin || !event.data || event.data.type !== 'subscription-risk-control:back-to-dashboard') return;
    history.pushState(null, '', window.location.pathname + window.location.search + '#/');
    syncDashboardRoute();
  });

  var queued = false;
  function scheduleAdd() {
    if (queued) return;
    queued = true;
    window.setTimeout(function () { queued = false; addMenu(); }, 80);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scheduleAdd);
  else scheduleAdd();
  new MutationObserver(scheduleAdd).observe(document.documentElement, { childList: true, subtree: true });
}());
