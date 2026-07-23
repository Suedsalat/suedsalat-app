(function () {
    // Blendet das per data-show-create-form referenzierte Formular ein und
    // versteckt den Button selbst - damit oben zunaechst nur Button + Liste
    // zu sehen sind, statt immer gleich die volle Eingabemaske.
    document.querySelectorAll('[data-show-create-form]').forEach(function (button) {
        var target = document.getElementById(button.getAttribute('data-show-create-form'));
        if (!target) return;
        button.addEventListener('click', function () {
            target.style.display = '';
            button.style.display = 'none';
        });
    });

    // Gegenstueck: klappt das Formular wieder ein und zeigt den urspruenglichen
    // "+ ..."-Button wieder an, damit man die Eingabemaske jederzeit wieder
    // minimieren kann, ohne die Seite neu zu laden.
    document.querySelectorAll('[data-hide-create-form]').forEach(function (button) {
        var targetId = button.getAttribute('data-hide-create-form');
        var target = document.getElementById(targetId);
        var showButton = document.querySelector('[data-show-create-form="' + targetId + '"]');
        if (!target) return;
        button.addEventListener('click', function () {
            target.style.display = 'none';
            if (showButton) showButton.style.display = '';
        });
    });
})();
