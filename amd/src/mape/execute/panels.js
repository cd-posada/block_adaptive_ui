// Adaptive_monitor.js — Monitor y Execute del ciclo MAPE-K (v2.5)
//
// block_adaptive_ui — Módulo de Adaptación Visual por Emoción para Moodle
//
// Copyright (C) 2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
// Politécnico Colombiano Jaime Isaza Cadavid
// Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AMD module: mape/execute/panels
 *
 * Panel de recomendación + cuadro de retroalimentación post-aceptación (extraído de
 * adaptive_monitor.js en la Fase B.3, 2026-07-22).
 *
 * Cambio de comportamiento en esta extracción: showFeedbackBox() pierde los parámetros
 * `trend` y `normanLevel` — nunca se usaban en su cuerpo (confirmado por lectura completa de
 * la función original antes de mover). Ambos call sites (dentro de showRecommendationPanel)
 * se actualizaron para dejar de pasarlos.
 *
 * currentCourseId ya no se lee del shell delgado (adaptive_monitor.js) por closure — este
 * módulo mantiene su propia copia interna (courseId), poblada por el shell vía
 * setCourseId(id) desde init().
 *
 * @module block_adaptive_ui/mape/execute/panels
 */
define([
    'core/ajax',
    'core/log',
    'block_adaptive_ui/mape/config',
    'block_adaptive_ui/mape/contrast',
    'block_adaptive_ui/mape/monitor/page_context_detector',
    'block_adaptive_ui/mape/monitor/emotion_log_reader',
    'block_adaptive_ui/mape/execute/dom_reverter',
    'block_adaptive_ui/mape/execute/dom_applier',
    'block_adaptive_ui/mape/execute/transition_manager',
], function(Ajax, Log, Config, Contrast, PageContextDetector, EmotionLogReader, DomReverter, DomApplier,
             TransitionManager) {
    'use strict';

    // Gris secundario de los paneles. Antes se usaban #777 (4.48:1) y #888 (3.55:1), ambos por
    // debajo de AA sobre blanco — el mismo defecto de contraste de las adaptaciones de página,
    // pero dentro de los paneles, que no pasan por las css_vars derivadas en PHP. #666 da 5.7:1.
    var MUTED_TEXT = '#666666';

    // courseid del curso donde vive el bloque (pasado por block_adaptive_ui.php::get_content()
    // a init(), reenviado aquí vía setCourseId()) — Fase 7, necesario para
    // block_adaptive_ui_effectiveness.courseid, ya que mdl_block_adaptive_ui_prefs no tiene
    // esa columna. undefined hasta que el shell llame a setCourseId(); recordAction() envía 0
    // en ese caso, igual que el comportamiento original.
    var courseId;

    // Etiquetas legibles para "suggested_resource" (decidido por el LLM, ver
    // adaptation_service/plan_engine.py sección RECURSOS SUGERIDOS). Local a este archivo —
    // único consumidor es buildResourceHtml.
    var RESOURCE_LABELS = {
        forum:         'Foro de dudas',
        glossary:      'Glosario',
        tutor_virtual: 'Tutor virtual',
    };

    var truncateDelegateAttached = false;

    /**
     * @param {string} str Texto a escapar.
     * @return {string} HTML seguro.
     */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    /** Elimina el panel de recomendación con transición. */
    function removePanel() {
        var panel = document.getElementById(Config.PANEL_ID);
        if (panel) {
            panel.style.opacity = '0';
            panel.style.transform = 'translateX(-100%)';
            setTimeout(function() {
                if (panel.parentNode) {
                    panel.parentNode.removeChild(panel);
                }
            }, 300);
        }
    }

    /** Elimina el cuadro de retroalimentación con transición. */
    function removeFeedbackBox() {
        var box = document.getElementById(Config.FEEDBACK_ID);
        if (box) {
            box.style.opacity = '0';
            setTimeout(function() {
                if (box.parentNode) {
                    box.parentNode.removeChild(box);
                }
            }, 300);
        }
    }

    /**
     * @param {string} emotion Emoción detectada.
     * @param {string} action  'activated' | 'closed'.
     */
    /**
     * true si css_params trae cambios reales para la página (css_vars o dom_actions).
     * @param {Object} cssParams css_params de apply_adaptation.
     * @return {boolean}
     */
    function hasRealChanges(cssParams) {
        return !!cssParams && (
            (cssParams.css_vars && Object.keys(cssParams.css_vars).length > 0) ||
            (Array.isArray(cssParams.dom_actions) && cssParams.dom_actions.length > 0));
    }

    /**
     * Latencia de adaptación (v3.3, user_model_v5_1): toma applied_at en el primer repintado
     * que ya muestra la adaptación y lo confirma al servidor (confirm_applied), que anota
     * applied_at_server; el trigger de la base deriva latency_total_ms.
     *
     * El callback de un requestAnimationFrame corre ANTES del pintado de ese cuadro; el del
     * requestAnimationFrame anidado corre ya con el cuadro anterior pintado, así que la marca
     * es el instante en que el estudiante ve la interfaz modificada (los tokens CSS se aplican
     * de forma síncrona en applyDOMChanges; las tandas escalonadas de dom_actions siguen
     * después). La hora es la del navegador SIN corregir (dato crudo): EmotionsClock de
     * local_emotions_send (performance.now() sobre una sola lectura de Date.now()) o Date.now().
     *
     * @param {number} eventId Adaptation_Event (apply_adaptation.event_id); 0 = no se registró.
     * @param {Object} cssParams css_params aplicados.
     */
    function confirmApplied(eventId, cssParams) {
        if (!(eventId > 0) || !hasRealChanges(cssParams)) {
            return;
        }
        window.requestAnimationFrame(function() {
            window.requestAnimationFrame(function() {
                var clock = window.EmotionsClock;
                var appliedAt = Math.round(clock && clock.nowMs ? clock.nowMs() : Date.now());
                Ajax.call([{
                    methodname: 'block_adaptive_ui_confirm_applied',
                    args: {
                        event_id: eventId, // eslint-disable-line camelcase
                        applied_at_ms: appliedAt, // eslint-disable-line camelcase
                    },
                }])[0].catch(function(err) {
                    Log.debug('adaptive_monitor: confirm_applied failed', err);
                });
            });
        });
    }

    function recordAction(emotion, action) {
        Ajax.call([{
            methodname: 'block_adaptive_ui_record_action',
            args: {
                emotion: emotion,
                action: action,
                courseid: courseId || 0,
                emotion_log: EmotionLogReader.getWindowJson(), // eslint-disable-line camelcase
            },
        }])[0].catch(function(err) {
            Log.debug('adaptive_monitor: record_action failed', err);
        });
    }

    /**
     * Construye el HTML del recurso sugerido por el LLM (suggested_resource), con un enlace
     * real cuando la URL está disponible en resourceUrls (rescatada de detectPageContext());
     * si no hay URL real (ej. "tutor_virtual", que no tiene un módulo Moodle equivalente
     * todavía — limitación documentada, no un bug), se muestra como texto informativo, NUNCA
     * como enlace roto (href="#").
     * @param {string} suggestedResource "forum" | "glossary" | "tutor_virtual" | null/undefined.
     * @param {Object} resourceUrls      {forum, glossary} de detectPageContext().
     * @param {string} color             Color identificador de la emoción.
     * @return {string} HTML (string vacío si no hay recurso sugerido).
     */
    function buildResourceHtml(suggestedResource, resourceUrls, color) {
        if (!suggestedResource || !RESOURCE_LABELS[suggestedResource]) {
            return '';
        }
        var label = RESOURCE_LABELS[suggestedResource];
        var url = (resourceUrls || {})[suggestedResource];
        // El color de la emoción aquí es TEXTO sobre el fondo blanco del cuadro, así que se
        // oscurece hasta AA conservando el tono — el pastel de Frustrado daba 1.75:1.
        var linkColor = Contrast.darkenUntilReadable(color, '#ffffff');
        var inner = url
            ? '<a href="' + url + '" style="font-size:13px;color:' + linkColor + '">' + escapeHtml(label) + '</a>'
            : '<span style="font-size:13px;color:' + MUTED_TEXT + '">' + escapeHtml(label) +
              ' (disponible desde el menú del curso)</span>';

        return '<div id="adaptive-feedback-resource" style="margin-top:10px;padding-top:10px;border-top:1px solid #eee">' +
            '<strong style="display:block;margin-bottom:4px;font-size:12px;color:' + MUTED_TEXT + '">Recurso sugerido</strong>' +
            inner +
            '</div>';
    }

    /**
     * Muestra un cuadro de retroalimentación contextual tras aceptar la adaptación, pintando
     * de inmediato el empathicMessage recibido (Fase B.0.2 — ya no llama a get_llm_feedback).
     *
     * @param {string} emotion     Emoción detectada.
     * @param {string} color       Color identificador de la emoción.
     * @param {Object} cssParams   css_params ya obtenidos de apply_adaptation.
     * cssParams.suggested_resource (si viene) es lo que decidió el LLM — ver buildResourceHtml.
     * @param {string} empathicMessage Texto empático ya generado por apply_adaptation (Fase
     * B.0.2) — siempre viene con contenido (LLM o el respaldo FALLBACKS de apply_adaptation.php)
     * cuando la llamada tuvo éxito; en el fallo de red se pasa FEEDBACK_FALLBACK directamente.
     * @param {number} eventId Adaptation_Event de esta adaptación (apply_adaptation.event_id).
     * Si es > 0 y la adaptación aplicó cambios reales, la caja agrega la valoración de la nueva
     * interfaz (1 OK / 2 Buena / 3 Mala), un comentario breve y el botón para revertirla
     * (v3.2) — ver buildRatingHtml()/attachRatingHandlers().
     */
    function showFeedbackBox(emotion, color, cssParams, empathicMessage, eventId) {
        if (document.getElementById(Config.FEEDBACK_ID)) {
            return;
        }

        Log.debug('adaptive_monitor: feedback — mostrando cuadro para ' + emotion);

        var box = document.createElement('div');
        box.id = Config.FEEDBACK_ID;
        box.style.cssText = [
            'position:fixed', 'bottom:24px', 'left:24px', 'width:320px',
            'background:white', 'border-radius:12px', 'padding:16px 20px',
            'box-shadow:0 4px 20px rgba(0,0,0,0.12)', 'z-index:100000',
            'font-family:inherit', 'border-right:4px solid ' + color,
            'transition:opacity 0.3s ease',
        ].join(';');

        var dot = '<span style="display:inline-block;width:10px;height:10px;' +
            'border-radius:50%;background:' + color + ';flex-shrink:0"></span>';
        var header =
            '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">' +
                dot +
                '<strong style="font-size:13px;color:#333">' + escapeHtml(emotion) + ' detectado</strong>' +
            '</div>';

        // El fondo del botón conserva el color de la emoción (identidad visual); lo que se
        // calcula es el TEXTO que va encima. Antes era 'white' fijo, que sobre el pastel de
        // Frustrado (#90CAF9) daba 1.75:1 — el botón se leía a duras penas.
        var btnOkHtml =
            '<button id="adaptive-feedback-ok" style="' +
                'padding:7px 16px;border:none;border-radius:6px;cursor:pointer;' +
                'font-size:13px;font-weight:500;background:' + color + ';color:' + Contrast.bestTextOn(color) +
            '">Entendido</button>';

        // Captura el contexto DOM en el momento de la aceptación (para el recurso sugerido).
        var pageCtx = PageContextDetector.detectPageContext();
        var resourceHtml = buildResourceHtml(cssParams && cssParams.suggested_resource, pageCtx.resource_urls, color);

        // v3.2: valoración + comentario + revertir, solo si hubo cambios reales en la página
        // (una decisión "mantener"/"sin_adaptacion" del Plan no cambia nada que calificar).
        var withRating = eventId > 0 && hasRealChanges(cssParams);

        box.innerHTML =
            header +
            '<div id="adaptive-feedback-content" style="font-size:13px;color:' + MUTED_TEXT +
                ';line-height:1.6;margin-bottom:14px">' +
                '<p style="margin:0">' + escapeHtml(empathicMessage) + '</p>' +
            '</div>' +
            resourceHtml +
            (withRating ? buildRatingHtml(color) : '') +
            btnOkHtml;

        document.body.appendChild(box);

        document.getElementById('adaptive-feedback-ok').addEventListener('click', removeFeedbackBox);

        Log.debug('adaptive_monitor: feedback — mostrado (' + empathicMessage.length + ' chars)');

        if (withRating) {
            // Sin auto-cierre: el estudiante necesita tiempo para calificar o revertir.
            attachRatingHandlers(eventId);
            return;
        }

        // Auto-cierre 8s después de mostrarse (ya no hay una llamada WS que esperar — el
        // texto ya viene resuelto en el mismo resultado de apply_adaptation, ver Fase B.0.2).
        setTimeout(function() {
            Log.debug('adaptive_monitor: feedback — auto-cierre (8s)');
            removeFeedbackBox();
        }, 8000);
    }

    /** Escala de valoración de la nueva interfaz (Adaptation_Feedback.preceived_usefulness). */
    var RATING_OPTIONS = [
        {value: 1, label: 'OK'},
        {value: 2, label: 'Buena'},
        {value: 3, label: 'Mala'},
    ];

    /**
     * HTML de la valoración (v3.2): grupo de radios con fieldset/legend (nombre accesible),
     * comentario con label, botones Enviar / Revertir y una región de estado (role="status")
     * para anunciar el resultado sin mover el foco (WCAG 4.1.3).
     * @param {string} color Color identificador de la emoción.
     * @return {string} HTML.
     */
    function buildRatingHtml(color) {
        var radios = RATING_OPTIONS.map(function(o) {
            return '<label style="display:inline-flex;align-items:center;gap:4px;margin-right:12px;' +
                'font-size:13px;color:#333;cursor:pointer">' +
                '<input type="radio" name="adaptive-rating" value="' + o.value + '"> ' +
                o.value + ' · ' + escapeHtml(o.label) + '</label>';
        }).join('');
        var btnBase = 'padding:6px 12px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:500';
        return '<form id="adaptive-rating-form" style="margin:0 0 12px;padding-top:10px;border-top:1px solid #eee">' +
            '<fieldset style="border:0;margin:0 0 8px;padding:0">' +
                '<legend style="font-size:12px;font-weight:600;color:#333;margin-bottom:6px;padding:0">' +
                    '¿Cómo calificas la nueva interfaz?</legend>' +
                radios +
            '</fieldset>' +
            '<label for="adaptive-rating-comment" style="display:block;font-size:12px;color:' + MUTED_TEXT +
                ';margin-bottom:4px">Comentario (opcional)</label>' +
            '<textarea id="adaptive-rating-comment" rows="2" maxlength="500" style="width:100%;box-sizing:border-box;' +
                'font-size:13px;padding:6px;border:1px solid #8a8a8a;border-radius:6px;resize:vertical"></textarea>' +
            '<div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">' +
                '<button type="submit" id="adaptive-rating-send" style="' + btnBase + ';border:none;background:' +
                    color + ';color:' + Contrast.bestTextOn(color) + '">Enviar</button>' +
                '<button type="button" id="adaptive-rating-revert" style="' + btnBase +
                    ';border:1px solid #8a8a8a;background:#fff;color:#333">Revertir adaptación</button>' +
            '</div>' +
            '<p id="adaptive-rating-status" role="status" style="margin:6px 0 0;font-size:12px;color:#333;min-height:1em"></p>' +
        '</form>';
    }

    /**
     * Envía valoración/comentario/reversión a record_feedback (Adaptation_Feedback /
     * Adaptation_Event en local_emotions_send).
     * @param {number} eventId Adaptation_Event.
     * @param {boolean} reverted true si el estudiante revirtió la adaptación.
     * @return {Promise}
     */
    function sendRating(eventId, reverted) {
        var checked = document.querySelector('input[name="adaptive-rating"]:checked');
        var comment = document.getElementById('adaptive-rating-comment');
        return Ajax.call([{
            methodname: 'block_adaptive_ui_record_feedback',
            args: {
                event_id: eventId, // eslint-disable-line camelcase
                rating: checked ? parseInt(checked.value, 10) : 0,
                comment: comment ? comment.value : '',
                reverted: reverted,
            },
        }])[0];
    }

    /**
     * Conecta Enviar (guarda la valoración y cierra) y Revertir adaptación (restaura la
     * interfaz original, lo registra junto con la valoración/comentario si los hay, y cierra).
     * @param {number} eventId Adaptation_Event de esta adaptación.
     */
    function attachRatingHandlers(eventId) {
        var form = document.getElementById('adaptive-rating-form');
        var status = document.getElementById('adaptive-rating-status');
        var sendBtn = document.getElementById('adaptive-rating-send');
        var revertBtn = document.getElementById('adaptive-rating-revert');
        var setBusy = function(busy) {
            sendBtn.disabled = busy;
            revertBtn.disabled = busy;
        };
        var finish = function(message) {
            status.textContent = message;
            setTimeout(removeFeedbackBox, 2000);
        };

        form.addEventListener('submit', function(ev) {
            ev.preventDefault();
            var checked = document.querySelector('input[name="adaptive-rating"]:checked');
            var comment = document.getElementById('adaptive-rating-comment').value.trim();
            if (!checked && comment === '') {
                status.textContent = 'Elige una calificación o escribe un comentario.';
                return;
            }
            setBusy(true);
            sendRating(eventId, false).then(function(res) {
                finish(res && res.success ? '¡Gracias! Tu valoración quedó registrada.'
                    : 'No se pudo registrar la valoración.');
                return null;
            }).catch(function(err) {
                Log.debug('adaptive_monitor: record_feedback failed', err);
                setBusy(false);
                status.textContent = 'No se pudo registrar la valoración. Intenta de nuevo.';
            });
        });

        revertBtn.addEventListener('click', function() {
            setBusy(true);
            DomReverter.revertDOMChanges();
            sendRating(eventId, true).then(function() {
                finish('Se restauró la interfaz original.');
                return null;
            }).catch(function(err) {
                Log.debug('adaptive_monitor: record_feedback (revert) failed', err);
                finish('Se restauró la interfaz original.');
            });
        });
    }

    /**
     * Muestra el panel de recomendación.
     * Al aceptar: aplica cambios DOM y lanza el cuadro de retroalimentación LLM.
     * @param {Object} data {emotion, trend} — sin css_params todavía (eso llega recién al
     * aceptar, vía apply_adaptation, ver botón "Activar cambios" más abajo).
     */
    function showRecommendationPanel(data) {
        if (document.getElementById(Config.PANEL_ID)) {
            return;
        }

        // Si el cuadro de retroalimentación sigue en pantalla, esperar a que cierre (máx 10s)
        if (document.getElementById(Config.FEEDBACK_ID)) {
            Log.debug('adaptive_monitor: panel en espera — feedback activo para ' + data.emotion);
            var waitTries = 0;
            var waitFeedback = setInterval(function() {
                waitTries++;
                if (!document.getElementById(Config.FEEDBACK_ID)) {
                    clearInterval(waitFeedback);
                    showRecommendationPanel(data);
                } else if (waitTries >= 25) {
                    clearInterval(waitFeedback);
                    Log.debug('adaptive_monitor: panel abortado tras espera máxima (10s)');
                }
            }, 400);
            return;
        }

        var emotion = data.emotion || '';
        var trend = data.trend || 'estable';
        var borderColor = Config.PANEL_COLORS[emotion] || '#0f6cbf';
        var genericText = Config.GENERIC_MESSAGES[emotion]
            || 'Se detectó un cambio emocional. Te recomendamos adaptar la interfaz visual.';

        // El motor LLM (Plan) todavía NO se ha llamado en este punto — solo se invoca al
        // aceptar (ver botón "Activar cambios" más abajo), así que no hay dom_actions
        // reales para inspeccionar todavía. La sugerencia de recurso (foro/glosario/tutor
        // virtual) es genuinamente decidida por el LLM vía "suggested_resource" y solo se
        // conoce (y se muestra) después de aceptar — ver showFeedbackBox().
        var hasActiveChanges = TransitionManager.getHiddenElements().length > 0 || document.body.hasAttribute('data-emotion');
        var closeBtnLabel = hasActiveChanges ? 'Revertir cambios' : 'Cerrar';

        var panel = document.createElement('div');
        panel.id = Config.PANEL_ID;
        // Esquina inferior IZQUIERDA — el widget de captura de emociones (local_emotions_send)
        // vive fijo en la inferior derecha con z-index:99999; usamos el lado opuesto para no
        // competir por el mismo espacio, más un z-index más alto por seguridad.
        panel.style.cssText = [
            'position:fixed', 'bottom:24px', 'left:24px', 'width:320px',
            'background:white', 'border-radius:12px', 'padding:16px 20px',
            'box-shadow:0 4px 20px rgba(0,0,0,0.12)', 'z-index:100000',
            'font-family:inherit', 'border-right:4px solid ' + borderColor,
            'transition:all 0.3s ease',
        ].join(';');

        var btnBase = 'padding:8px 14px;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:500';
        // Mismo criterio que el botón "Entendido": el fondo conserva el color de la emoción y el
        // texto se calcula. Este panel se pinta ANTES de llamar al LLM, así que no existe todavía
        // ningún --emotion-primary derivado en PHP que reutilizar — de ahí el cálculo en cliente.
        var btnActivate = '<button id="adaptive-btn-activate" style="' +
            btnBase + ';background:' + borderColor + ';color:' + Contrast.bestTextOn(borderColor) +
            '">Activar cambios</button>';
        var btnClose = '<button id="adaptive-btn-close" style="' +
            btnBase + ';background:#f0f0f0;color:#555">' + escapeHtml(closeBtnLabel) + '</button>';

        panel.innerHTML =
            '<p style="margin:0 0 12px;font-size:14px;line-height:1.5;color:#333">' +
                escapeHtml(genericText) +
            '</p>' +
            '<div style="display:flex;gap:8px">' + btnActivate + btnClose + '</div>';

        document.body.appendChild(panel);

        var activateBtn = document.getElementById('adaptive-btn-activate');
        var closeBtn = document.getElementById('adaptive-btn-close');

        activateBtn.addEventListener('click', function() {
            activateBtn.disabled = true;
            activateBtn.textContent = 'Aplicando…';
            closeBtn.disabled = true;

            Log.debug('adaptive_monitor: apply_adaptation — llamando WS para ' + emotion);

            Ajax.call([{
                methodname: 'block_adaptive_ui_apply_adaptation',
                args: {
                    emotion: emotion,
                    trend: trend,
                    // eslint-disable-next-line camelcase
                    page_context: JSON.stringify(PageContextDetector.detectPageContext()),
                    // Fase E — mismo courseId ya poblado por setCourseId() que recordAction()
                    // ya usa (línea ~104) — $COURSE/$PAGE->course NO reflejan el curso real en
                    // el contexto de este web service (confirmado: se inicializan como SITE),
                    // así que el curso real solo puede llegar como parámetro explícito.
                    courseid: courseId || 0,
                    // v3.0 — misma ventana del sessionStorage que usó el último poll (≤5s).
                    emotion_log: EmotionLogReader.getWindowJson(), // eslint-disable-line camelcase
                },
            }])[0].then(function(result) {
                if (!result || result.action !== 'recommend') {
                    Log.debug('adaptive_monitor: apply_adaptation sin recomendación (llm_error?) — ' + emotion);
                    activateBtn.disabled = false;
                    activateBtn.textContent = 'Reintentar';
                    closeBtn.disabled = false;
                    return null;
                }

                var cssParams = {};
                try {
                    cssParams = JSON.parse(result.css_params || '{}');
                } catch (e) {
                    cssParams = {};
                }
                var normanLvl = result.norman_level || '';
                var empathicMessage = result.empathic_message || '';

                DomReverter.revertDOMChanges();
                DomApplier.applyDOMChanges(cssParams, emotion, normanLvl);
                confirmApplied(result.event_id || 0, cssParams);
                recordAction(emotion, 'activated');
                removePanel();
                // v3.2: event_id permite calificar/comentar/revertir ESTA adaptación desde la caja.
                showFeedbackBox(emotion, borderColor, cssParams, empathicMessage, result.event_id || 0);
                return null;

            }).catch(function(err) {
                Log.error('adaptive_monitor: apply_adaptation ERROR: ' + err);
                activateBtn.disabled = false;
                activateBtn.textContent = 'Reintentar';
                closeBtn.disabled = false;
                // Fallo de red completo (no llegó respuesta) -- no hay css_params que aplicar,
                // pero igual le avisamos al estudiante en vez de dejarlo sin ningún mensaje.
                showFeedbackBox(emotion, borderColor, {}, Config.FEEDBACK_FALLBACK);
            });
        });

        closeBtn.addEventListener('click', function() {
            recordAction(emotion, 'closed');
            if (hasActiveChanges) {
                DomReverter.revertDOMChanges();
            }
            removePanel();
        });

        Log.debug('adaptive_monitor: panel shown for ' + emotion);
    }

    /**
     * Adjunta UN solo listener de clic delegado (en document) para el toggle de párrafos
     * truncados (Confundido, .adaptive-truncate → "Ver más ▾"/"Ver menos ▴"). Un único
     * listener, adjuntado una vez en init(), resuelve el clic sobre CUALQUIER
     * .adaptive-truncate presente en cualquier momento — sin importar cuántas adaptaciones se
     * apliquen o reviertan después.
     */
    function attachTruncateDelegate() {
        if (truncateDelegateAttached) {
            return;
        }
        document.addEventListener('click', function(evt) {
            var target = evt.target.closest && evt.target.closest('.adaptive-truncate');
            if (target) {
                target.classList.toggle('expanded');
            }
        });
        truncateDelegateAttached = true;
    }

    /** @param {number} id courseid del curso donde vive el bloque (0 si no aplica). */
    function setCourseId(id) {
        courseId = id;
    }

    return {
        showRecommendationPanel: showRecommendationPanel,
        setCourseId: setCourseId,
        attachTruncateDelegate: attachTruncateDelegate,
        removePanel: removePanel,
        removeFeedbackBox: removeFeedbackBox,
    };
});
