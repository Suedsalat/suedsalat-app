(function () {
    var el = document.getElementById('logout-countdown');
    if (!el) return;

    var totalSeconds = parseInt(el.dataset.timeoutSeconds, 10) || 0;
    var remaining = totalSeconds;

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

    // Bei echter Aktivitaet (Tippen, Klicken, Beruehren) soll die Sitzung
    // verlaengert werden, nicht nur bei komplettem Seiten-Neuaufbau (z.B.
    // Button-Klick mit Formular-Absenden) - sonst laeuft bei laengeren
    // Eingaben (z.B. Termin/Locationtipp mit viel Text) die Zeit ab, obwohl
    // gerade aktiv gearbeitet wird. Auf einmal alle 20 Sekunden gedrosselt,
    // um den Server nicht bei jedem Tastendruck anzufragen.
    var pingInFlight = false;
    var lastPing = 0;
    var THROTTLE_MS = 20000;

    function pingKeepAlive() {
        var now = Date.now();
        if (pingInFlight || (now - lastPing) < THROTTLE_MS) return;
        pingInFlight = true;
        lastPing = now;
        // Relativer Pfad statt BASE_PATH, da dieses Skript eine statische Datei ist
        // (kein PHP) - laeuft aber immer auf einer Seite innerhalb von admin/, daher
        // loest der Browser "activity-ping.php" automatisch relativ zum aktuellen Ordner auf.
        // Bewusst nicht "keep-alive.php" genannt: manche Werbe-/Trackingblocker filtern
        // Anfragen mit "keep-alive" im Pfad als bekanntes Tracking-Beacon-Muster.
        fetch('activity-ping.php', { credentials: 'same-origin' })
            .then(function (response) {
                if (response.ok) {
                    remaining = totalSeconds;
                    el.textContent = format(remaining);
                }
            })
            .catch(function () {
                // Netzwerkfehler ignorieren - der lokale Countdown laeuft einfach weiter.
            })
            .finally(function () {
                pingInFlight = false;
            });
    }

    ['keydown', 'input', 'mousedown', 'touchstart'].forEach(function (eventName) {
        document.addEventListener(eventName, pingKeepAlive, { passive: true });
    });
})();
