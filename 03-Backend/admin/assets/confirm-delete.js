// Zweistufige Loesch-Bestaetigung: 1) Ja/Nein mit Erklaerungstext, 2) Passwort.
// Das Passwort wird serverseitig geprueft (siehe delete_id-Handler) - dieses
// Skript sorgt nur fuer die Abfrage, keine Sicherheit ohne Server-Check.
let pendingDeleteForm = null;

function requestDelete(form, message) {
    pendingDeleteForm = form;
    document.getElementById('confirm-step1-text').textContent = message;
    document.getElementById('confirm-step1').classList.add('is-open');
}

function confirmStep1No() {
    document.getElementById('confirm-step1').classList.remove('is-open');
    pendingDeleteForm = null;
}

function confirmStep1Yes() {
    document.getElementById('confirm-step1').classList.remove('is-open');
    document.getElementById('confirm-password').value = '';
    document.getElementById('confirm-error').style.display = 'none';
    document.getElementById('confirm-step2').classList.add('is-open');
    document.getElementById('confirm-password').focus();
}

function confirmStep2Cancel() {
    document.getElementById('confirm-step2').classList.remove('is-open');
    pendingDeleteForm = null;
}

function confirmStep2Ok() {
    const password = document.getElementById('confirm-password').value;
    if (!password) {
        const errorEl = document.getElementById('confirm-error');
        errorEl.textContent = 'Bitte Passwort eingeben.';
        errorEl.style.display = 'block';
        return;
    }
    if (pendingDeleteForm) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'confirm_password';
        input.value = password;
        pendingDeleteForm.appendChild(input);
        pendingDeleteForm.submit();
    }
}
