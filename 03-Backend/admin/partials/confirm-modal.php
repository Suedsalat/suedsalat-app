<?php
// Zweistufige Bestaetigung (Ja/Nein, dann 2FA-Code oder Passwort) fuer gefaehrliche Aktionen,
// gesteuert von assets/confirm-delete.js (requestDelete). Der Code wird serverseitig mit
// verify_admin_delete_confirmation() geprueft - das hier ist nur die Abfrage.
?>
<div id="confirm-step1" class="modal-overlay">
    <div class="modal-box">
        <p><strong>Bist du sicher?</strong></p>
        <p id="confirm-step1-text"></p>
        <div class="modal-actions">
            <button type="button" onclick="confirmStep1No()">Nein</button>
            <button type="button" class="button-danger" onclick="confirmStep1Yes()">Ja</button>
        </div>
    </div>
</div>
<div id="confirm-step2" class="modal-overlay">
    <div class="modal-box">
        <p><strong>Zur Bestätigung: Code aus deiner Authenticator-App</strong></p>
        <p style="font-size:0.85rem;color:#666;margin-top:-8px;">Falls du noch kein 2FA eingerichtet hast, geht hier auch dein normales Passwort.</p>
        <input type="text" inputmode="numeric" autocomplete="one-time-code" id="confirm-password" placeholder="Code oder Passwort">
        <p id="confirm-error" class="error" style="display:none;"></p>
        <div class="modal-actions">
            <button type="button" onclick="confirmStep2Cancel()">Abbrechen</button>
            <button type="button" class="button-danger" onclick="confirmStep2Ok()">OK</button>
        </div>
    </div>
</div>
