(function () {
    var el = document.getElementById('logout-countdown');
    if (!el) return;

    var remaining = parseInt(el.dataset.timeoutSeconds, 10) || 0;

    function format(sec) {
        var m = Math.floor(sec / 60);
        var s = sec % 60;
        return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    el.textContent = format(remaining);

    var timer = setInterval(function () {
        remaining -= 1;
        if (remaining <= 0) {
            clearInterval(timer);
            window.location.reload();
            return;
        }
        el.textContent = format(remaining);
    }, 1000);
})();
