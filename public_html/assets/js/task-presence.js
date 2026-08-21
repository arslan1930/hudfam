/**
 * Small “Also here” presence chip — heartbeat only, no locking.
 */
(function () {
  'use strict';

  var INTERVAL_MS = 30000;
  var nodes = document.querySelectorAll('[data-task-presence]');
  if (!nodes.length) return;

  function formatNames(others) {
    var names = (others || []).map(function (o) {
      return String((o && o.name) || 'User');
    }).filter(Boolean);
    if (!names.length) return '';
    if (names.length === 1) return names[0];
    if (names.length === 2) return names[0] + ' · ' + names[1];
    return names[0] + ' · ' + names[1] + ' +' + (names.length - 2);
  }

  function updateNode(node, data) {
    var namesEl = node.querySelector('[data-presence-names]');
    var others = (data && data.others) || [];
    var count = others.length;
    if (!count) {
      node.hidden = true;
      if (namesEl) namesEl.textContent = '';
      return;
    }
    node.hidden = false;
    if (namesEl) namesEl.textContent = formatNames(others);
    node.title = count === 1
      ? (others[0].name + ' is also on this task')
      : (count + ' teammates are also on this task');
  }

  function ping(node) {
    var url = node.getAttribute('data-ping-url') || 'index.php?page=presence_ping';
    var key = node.getAttribute('data-task-key') || '';
    if (!key) return;
    var body = new URLSearchParams();
    body.set('task_key', key);
    fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        Accept: 'application/json'
      },
      body: body.toString(),
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (res) {
        return res.json().then(function (data) {
          if (!res.ok || !data || data.ok === false) {
            throw new Error((data && data.error) || 'Presence failed');
          }
          return data;
        });
      })
      .then(function (data) {
        updateNode(node, data);
      })
      .catch(function () {
        // Keep quiet — presence is advisory only.
      });
  }

  function pingAll() {
    if (document.hidden) return;
    nodes.forEach(ping);
  }

  pingAll();
  window.setInterval(pingAll, INTERVAL_MS);

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) pingAll();
  });
})();
