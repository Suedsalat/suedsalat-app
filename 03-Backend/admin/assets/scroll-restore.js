// Merkt sich die Scroll-Position vor jedem Formular-Absenden (z.B. Filter,
// "Anzeigen"-Button) und stellt sie nach dem Neuladen der Seite wieder her -
// sonst springt der Browser bei jedem Klick zurueck nach ganz oben und man
// muss jedes Mal wieder manuell zu der Stelle runterscrollen, an der man war.
(function () {
    var storageKey = 'admin-scroll:' + location.pathname;

    document.addEventListener('submit', function () {
        try {
            sessionStorage.setItem(storageKey, String(window.scrollY));
        } catch (e) {}
    }, true);

    try {
        var saved = sessionStorage.getItem(storageKey);
        if (saved !== null) {
            sessionStorage.removeItem(storageKey);
            window.scrollTo(0, parseInt(saved, 10) || 0);
        }
    } catch (e) {}
})();
