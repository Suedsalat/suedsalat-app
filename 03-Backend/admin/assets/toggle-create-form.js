(function () {
    // Blendet das per data-show-create-form referenzierte Formular ein und
    // versteckt den Button selbst - damit oben zunaechst nur Button + Liste
    // zu sehen sind, statt immer gleich die volle Eingabemaske.
    document.querySelectorAll('[data-show-create-form]').forEach(function (button) {
        button.addEventListener('click', function () {
            var target = document.getElementById(button.getAttribute('data-show-create-form'));
            if (target) target.style.display = '';
            button.style.display = 'none';
        });
    });
})();
