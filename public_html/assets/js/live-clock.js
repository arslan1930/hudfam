(function () {
  function pad(n) {
    return n < 10 ? '0' + n : String(n);
  }

  function formatNow(d) {
    var days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var day = days[d.getDay()];
    var date = pad(d.getDate()) + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
    var time = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    return day + ' · ' + date + ' · ' + time;
  }

  function tick() {
    var el = document.getElementById('live-datetime');
    if (!el) return;
    el.textContent = formatNow(new Date());
  }

  tick();
  setInterval(tick, 1000);
})();
