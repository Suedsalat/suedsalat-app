(function () {
    // Fuegt vor jeder breiten Tabelle (.table-scroll) eine duenne, synchronisierte
    // Scrollleiste ein, damit man nicht erst ganz nach unten scrollen muss, um
    // seitwaerts zu scrollen, und danach wieder nach oben, um die oberste Zeile
    // vollstaendig zu sehen.
    document.querySelectorAll('.table-scroll').forEach(function (scrollBox) {
        var table = scrollBox.querySelector('table');
        if (!table) return;

        var topBar = document.createElement('div');
        topBar.className = 'table-scroll-top';
        var topBarInner = document.createElement('div');
        topBarInner.className = 'table-scroll-top-inner';
        topBar.appendChild(topBarInner);
        scrollBox.parentNode.insertBefore(topBar, scrollBox);

        function update() {
            topBarInner.style.width = table.scrollWidth + 'px';
            topBar.style.display = table.scrollWidth > scrollBox.clientWidth ? 'block' : 'none';
        }
        update();
        window.addEventListener('resize', update);

        var syncing = false;
        topBar.addEventListener('scroll', function () {
            if (syncing) return;
            syncing = true;
            scrollBox.scrollLeft = topBar.scrollLeft;
            syncing = false;
        });
        scrollBox.addEventListener('scroll', function () {
            if (syncing) return;
            syncing = true;
            topBar.scrollLeft = scrollBox.scrollLeft;
            syncing = false;
        });
    });
})();
