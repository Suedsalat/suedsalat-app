// Einfacher Foto-Editor: laedt ein Bild in ein <canvas>, laesst Emoji-Aufkleber
// (z.B. ein Smilie ueber ein Kindergesicht) platzieren/verschieben/vergroessern
// und exportiert das Ergebnis als flaches Bild. Bei Fotos mit erhaltenem
// Original (options.nonDestructive) laedt das Canvas das unveraenderte
// Original, die Aufkleber-Liste wird zusaetzlich als JSON mitgeschickt, damit
// sie beim naechsten Bearbeiten wieder als bewegliche Objekte startet, statt
// endgueltig ins Bild "eingebrannt" zu sein (siehe admin/photo-editor-save.php).
window.PhotoEditor = (function () {
    function init(options) {
        var canvas = document.getElementById('editorCanvas');
        var ctx = canvas.getContext('2d');
        var img = new Image();
        // {emoji, x, y, size} - x/y/size in Bild-Pixeln (nicht CSS-Pixeln). Bei
        // einem Foto mit erhaltenem Original (options.nonDestructive) startet
        // das mit den zuletzt gespeicherten Aufklebern, nicht leer - die lassen
        // sich dann direkt weiter verschieben/entfernen statt neu platziert
        // werden zu muessen.
        var stickers = (options.existingStickers || []).map(function (s) {
            return { emoji: s.emoji, x: s.x, y: s.y, size: s.size };
        });
        var selectedIndex = -1;
        var selectedEmoji = '😊';
        var scale = 1; // CSS-Pixel pro Bild-Pixel

        var drag = null; // {index, offsetX, offsetY} beim Verschieben
        var resizing = null; // {index} beim Groessenziehen
        var sizeSlider = document.getElementById('sizeSlider');

        // Haelt den Groessen-Regler mit dem gerade ausgewaehlten Aufkleber
        // synchron - egal ob die Groesse per Anfasser gezogen oder per Regler
        // eingestellt wurde, beides soll sich gegenseitig sofort widerspiegeln.
        function updateSizeSlider() {
            if (selectedIndex === -1) {
                sizeSlider.disabled = true;
                return;
            }
            sizeSlider.disabled = false;
            sizeSlider.value = String(Math.round(stickers[selectedIndex].size));
        }

        function imageToCanvasCoords(evt) {
            var rect = canvas.getBoundingClientRect();
            var clientX = evt.touches ? evt.touches[0].clientX : evt.clientX;
            var clientY = evt.touches ? evt.touches[0].clientY : evt.clientY;
            return {
                x: (clientX - rect.left) * (canvas.width / rect.width),
                y: (clientY - rect.top) * (canvas.height / rect.height),
            };
        }

        function stickerHandle(sticker) {
            // Anfasser zum Vergroessern unten rechts am Aufkleber.
            return { x: sticker.x + sticker.size / 2, y: sticker.y + sticker.size / 2 };
        }

        function hitTestSticker(pt) {
            for (var i = stickers.length - 1; i >= 0; i--) {
                var s = stickers[i];
                var half = s.size / 2;
                if (pt.x >= s.x - half && pt.x <= s.x + half && pt.y >= s.y - half && pt.y <= s.y + half) {
                    return i;
                }
            }
            return -1;
        }

        function hitTestHandle(pt) {
            if (selectedIndex === -1) return false;
            var h = stickerHandle(stickers[selectedIndex]);
            var tolerance = 14;
            return Math.abs(pt.x - h.x) <= tolerance && Math.abs(pt.y - h.y) <= tolerance;
        }

        function redraw() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            stickers.forEach(function (s, i) {
                ctx.font = s.size + 'px "Segoe UI Emoji", "Noto Color Emoji", "Apple Color Emoji", sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(s.emoji, s.x, s.y);
                if (i === selectedIndex) {
                    var half = s.size / 2;
                    ctx.save();
                    ctx.strokeStyle = '#77B538';
                    ctx.lineWidth = Math.max(2, canvas.width * 0.002);
                    ctx.setLineDash([6, 4]);
                    ctx.strokeRect(s.x - half, s.y - half, s.size, s.size);
                    ctx.setLineDash([]);
                    var h = stickerHandle(s);
                    ctx.fillStyle = '#77B538';
                    ctx.beginPath();
                    ctx.arc(h.x, h.y, 7, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.restore();
                }
            });
        }

        function onPointerDown(evt) {
            evt.preventDefault();
            var pt = imageToCanvasCoords(evt);

            if (hitTestHandle(pt)) {
                resizing = { index: selectedIndex };
                return;
            }

            var hit = hitTestSticker(pt);
            if (hit !== -1) {
                selectedIndex = hit;
                drag = { index: hit, offsetX: pt.x - stickers[hit].x, offsetY: pt.y - stickers[hit].y };
                updateSizeSlider();
                redraw();
                return;
            }

            // Leere Stelle: neuen Aufkleber platzieren.
            var defaultSize = canvas.width * 0.12;
            stickers.push({ emoji: selectedEmoji, x: pt.x, y: pt.y, size: defaultSize });
            selectedIndex = stickers.length - 1;
            drag = { index: selectedIndex, offsetX: 0, offsetY: 0 };
            updateSizeSlider();
            redraw();
        }

        function onPointerMove(evt) {
            if (!drag && !resizing) return;
            evt.preventDefault();
            var pt = imageToCanvasCoords(evt);

            if (resizing) {
                var s = stickers[resizing.index];
                var half = Math.max(10, Math.max(Math.abs(pt.x - s.x), Math.abs(pt.y - s.y)));
                s.size = half * 2;
                updateSizeSlider();
                redraw();
                return;
            }

            if (drag) {
                var sticker = stickers[drag.index];
                sticker.x = pt.x - drag.offsetX;
                sticker.y = pt.y - drag.offsetY;
                redraw();
            }
        }

        function onPointerUp() {
            drag = null;
            resizing = null;
        }

        function deleteSelected() {
            if (selectedIndex === -1) return;
            stickers.splice(selectedIndex, 1);
            selectedIndex = -1;
            updateSizeSlider();
            redraw();
        }

        img.crossOrigin = 'anonymous';
        img.onload = function () {
            canvas.width = img.naturalWidth;
            canvas.height = img.naturalHeight;
            // Sichtbare Groesse begrenzen, damit sehr grosse Fotos nicht die
            // ganze Seite sprengen - die interne Aufloesung bleibt unveraendert,
            // dank getBoundingClientRect()-Umrechnung oben bleiben Klicks exakt.
            var maxDisplayWidth = 900;
            if (img.naturalWidth > maxDisplayWidth) {
                canvas.style.width = maxDisplayWidth + 'px';
            }
            // Regler-Spanne an die tatsaechliche Bildgroesse anpassen, statt
            // fixer Pixelwerte, die bei einem sehr kleinen oder sehr grossen
            // Foto nicht passen wuerden.
            sizeSlider.min = String(Math.round(canvas.width * 0.02));
            sizeSlider.max = String(Math.round(canvas.width * 0.6));
            redraw();
        };
        img.onerror = function () {
            document.getElementById('saveStatus').textContent = 'Bild konnte nicht geladen werden.';
        };
        img.src = options.imageUrl;

        canvas.addEventListener('mousedown', onPointerDown);
        canvas.addEventListener('mousemove', onPointerMove);
        window.addEventListener('mouseup', onPointerUp);
        canvas.addEventListener('touchstart', onPointerDown, { passive: false });
        canvas.addEventListener('touchmove', onPointerMove, { passive: false });
        window.addEventListener('touchend', onPointerUp);

        document.addEventListener('keydown', function (evt) {
            if (evt.key === 'Delete' || evt.key === 'Backspace') {
                if (document.activeElement === canvas || document.activeElement === document.body) {
                    deleteSelected();
                }
            }
        });

        var picker = document.getElementById('emojiPicker');
        picker.querySelectorAll('button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                picker.querySelectorAll('button').forEach(function (b) { b.classList.remove('is-selected'); });
                btn.classList.add('is-selected');
                selectedEmoji = btn.getAttribute('data-emoji');
            });
        });

        document.getElementById('deleteSelectedBtn').addEventListener('click', deleteSelected);
        document.getElementById('clearAllBtn').addEventListener('click', function () {
            stickers = [];
            selectedIndex = -1;
            updateSizeSlider();
            redraw();
        });

        sizeSlider.addEventListener('input', function () {
            if (selectedIndex === -1) return;
            stickers[selectedIndex].size = Number(sizeSlider.value);
            redraw();
        });

        document.getElementById('saveBtn').addEventListener('click', function () {
            var statusEl = document.getElementById('saveStatus');
            statusEl.style.color = '';
            statusEl.textContent = 'Speichert …';
            // Vor dem Export die Auswahl-Markierung (gestrichelter Rahmen +
            // Anfasser-Punkt) abwaehlen und neu zeichnen - die ist nur eine
            // Bearbeitungshilfe und darf nicht mit ins gespeicherte Bild.
            var previousSelection = selectedIndex;
            selectedIndex = -1;
            redraw();
            canvas.toBlob(function (blob) {
                if (!blob) {
                    statusEl.style.color = '#b00020';
                    statusEl.textContent = 'Export fehlgeschlagen.';
                    selectedIndex = previousSelection;
                    updateSizeSlider();
                    redraw();
                    return;
                }
                var formData = new FormData();
                formData.append('path', options.savePath);
                formData.append('image', blob, 'edited.jpg');
                formData.append('nondestructive', options.nonDestructive ? '1' : '0');
                if (options.nonDestructive) {
                    // Aufkleber-Liste separat mitschicken, damit sie beim naechsten
                    // Oeffnen wieder als bewegliche/loeschbare Objekte geladen werden
                    // koennen, statt nur als fest gespeicherte Pixel im Bild.
                    formData.append('stickers', JSON.stringify(stickers));
                }

                fetch(options.saveUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && data.ok) {
                            statusEl.style.color = '#2e7d32';
                            statusEl.textContent = 'Gespeichert.';
                            setTimeout(function () { window.location.href = options.returnUrl; }, 600);
                        } else {
                            statusEl.style.color = '#b00020';
                            statusEl.textContent = (data && data.error) || 'Speichern fehlgeschlagen.';
                            selectedIndex = previousSelection;
                            updateSizeSlider();
                            redraw();
                        }
                    })
                    .catch(function () {
                        statusEl.style.color = '#b00020';
                        statusEl.textContent = 'Speichern fehlgeschlagen (Netzwerk).';
                        selectedIndex = previousSelection;
                        updateSizeSlider();
                        redraw();
                    });
            }, 'image/jpeg', 0.9);
        });
    }

    return { init: init };
})();
