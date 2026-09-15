// Steuert die kleine Formatierungsleiste (Fett/Kursiv/Liste/Link) ueber dem
// Newsletter-Textfeld: das eigentliche Eingabefeld ist ein contenteditable-Div
// (body_text_editor), dessen HTML-Inhalt vor dem Absenden des Formulars in das
// verborgene textarea body_text_hidden kopiert wird - das ist das Feld, das
// serverseitig ankommt (siehe admin/newsletter.php sanitize_newsletter_body_html).
(function () {
    var editor = document.getElementById('body_text_editor');
    var hidden = document.getElementById('body_text_hidden');
    var toolbar = document.getElementById('body_text_toolbar');
    if (!editor || !hidden) {
        return;
    }

    if (toolbar) {
        toolbar.querySelectorAll('button[data-cmd]').forEach(function (button) {
            button.addEventListener('click', function () {
                editor.focus();
                var cmd = button.getAttribute('data-cmd');
                if (cmd === 'link') {
                    var url = window.prompt('Link-Ziel (mit https:// oder mailto:):');
                    if (url) {
                        document.execCommand('createLink', false, url);
                    }
                } else {
                    document.execCommand(cmd, false, null);
                }
            });
        });
    }

    var form = editor.closest('form');
    if (form) {
        form.addEventListener('submit', function () {
            hidden.value = editor.innerHTML;
        });
    }
})();
