// Oeffnet/schliesst die Sidebar-Navigation auf schmalen Bildschirmen (siehe
// admin.css @media max-width:900px) - auf breiten Bildschirmen steht die
// Sidebar sowieso fest und dieses Skript tut nichts Sichtbares.
(function () {
    var toggle = document.getElementById('sidebar-toggle');
    var sidebar = document.getElementById('admin-sidebar');
    var backdrop = document.getElementById('sidebar-backdrop');
    if (!toggle || !sidebar || !backdrop) return;

    function open() {
        sidebar.classList.add('is-open');
        backdrop.classList.add('is-open');
    }
    function close() {
        sidebar.classList.remove('is-open');
        backdrop.classList.remove('is-open');
    }

    toggle.addEventListener('click', function () {
        if (sidebar.classList.contains('is-open')) {
            close();
        } else {
            open();
        }
    });
    backdrop.addEventListener('click', close);
})();
