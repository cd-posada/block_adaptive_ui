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
 * AMD module: adaptive_monitor v2.5
 *
 * Fase MAPE-K: MONITOR (poll() cada 5s) + EXECUTE (panel, DOM, retroalimentación)
 *
 * Shell delgado (Fase B.3, 2026-07-22) — toda la lógica de negocio vive en
 * block_adaptive_ui/mape/{monitor,execute}/*; este archivo solo conecta el toggle on/off,
 * arranca/detiene el polling, expone window.__adaptiveDebug() y es el punto de entrada
 * (init()) que llama block_adaptive_ui.php vía js_call_amd() — ver ese archivo, línea con
 * "block_adaptive_ui/adaptive_monitor", nombre de módulo que NO cambia en este refactor.
 *
 * Fase Execute del ciclo MAPE-K:
 *   1. Polling cada 5s con DOM Context Scanner (detectPageContext).
 *      get_adaptation solo consulta la BD (Monitor+Analyze) — NO llama al LLM.
 *   2. Si action == "recommend" y cooldown ok: muestra panel genérico (sin css_params
 *      todavía, el LLM aún no se ha llamado).
 *   3. Si cooldown activo: no hace nada (ya no hay css_params precalculados que aplicar
 *      en silencio — el Plan LLM se difiere hasta el clic).
 *   4. Botón "Activar cambios":
 *        a. Llama a apply_adaptation (única llamada al motor LLM del Plan) para obtener
 *           css_vars + dom_actions + empathic_message (Fase B.0.2: mismo llamado de Plan,
 *           ya no hay una segunda llamada LLM a get_llm_feedback).
 *        b. Aplica CSS vars + dom_actions.
 *        c. Registra 'activated'.
 *        d. Muestra cuadro de retroalimentación con el empathic_message recibido, botón
 *           "Entendido".
 *   5. Botón "Cerrar" / "Revertir cambios": registra 'closed' + restaura DOM.
 *   6. Solo un panel activo a la vez. Cooldown de 30s por emoción.
 *
 * @module block_adaptive_ui/adaptive_monitor
 */
define([
    'core/log',
    'block_adaptive_ui/mape/monitor/poll',
    'block_adaptive_ui/mape/monitor/page_context_detector',
    'block_adaptive_ui/mape/execute/dom_applier',
    'block_adaptive_ui/mape/execute/dom_reverter',
    'block_adaptive_ui/mape/execute/panels',
], function(Log, Poll, PageContextDetector, DomApplier, DomReverter, Panels) {
    'use strict';

    var POLL_INTERVAL = 5000; // 5 segundos — detección rápida
    var STORAGE_KEY = 'adaptive_ui_enabled'; // Clave localStorage para el toggle

    // Referencia al intervalo de polling (necesaria para poder detenerlo)
    var pollIntervalId = null;

    // ─────────────────────────────────────────────────────────────────────────
    // TOGGLE ON/OFF
    // ─────────────────────────────────────────────────────────────────────────

    /** @return {boolean} true si la adaptación está activada por el usuario. */
    function isEnabled() {
        return localStorage.getItem(STORAGE_KEY) !== 'false';
    }

    /**
     * @param {boolean} enabled Estado actual del toggle.
     */
    function updateToggleUI(enabled) {
        var toggle = document.getElementById('adaptive-ui-toggle');
        var label = document.getElementById('adaptive-ui-label');
        if (toggle) {
            toggle.checked = enabled;
        }
        if (label) {
            label.textContent = enabled ? 'Adaptación activa' : 'Adaptación pausada';
        }
    }

    /** Inicia el intervalo de polling si no estaba activo. */
    function startPolling() {
        if (pollIntervalId) {
            return;
        }
        Poll.poll();
        pollIntervalId = setInterval(Poll.poll, POLL_INTERVAL);
    }

    /** Detiene el polling y revierte cambios DOM. */
    function stopPolling() {
        if (pollIntervalId) {
            clearInterval(pollIntervalId);
            pollIntervalId = null;
        }
        Panels.removePanel();
        Panels.removeFeedbackBox();
        DomReverter.revertDOMChanges();
    }

    /**
     * @param {boolean} enabled Nuevo estado del toggle.
     */
    function setEnabled(enabled) {
        localStorage.setItem(STORAGE_KEY, enabled ? 'true' : 'false');
        updateToggleUI(enabled);
        if (enabled) {
            startPolling();
            Log.debug('adaptive_monitor: toggle — activado');
        } else {
            stopPolling();
            Log.debug('adaptive_monitor: toggle — pausado, DOM revertido');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DEBUG EN VIVO (2026-07-06)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * window.__adaptiveDebug() — de solo lectura, sin riesgo de seguridad, expuesto siempre.
     * Imprime en consola el último inventario dinámico detectado (scanContentElements()) y,
     * de las acciones de la última adaptación aplicada, cuáles selectores coinciden con 0 vs
     * más de 0 elementos EN ESTE MOMENTO — para verificar en vivo, en cualquier página de
     * contenido, si una adaptación tuvo efecto visible sin depender de que alguien vea la
     * pantalla del estudiante.
     */
    function adaptiveDebug() {
        var lastScannedElements = PageContextDetector.getLastScannedElements();
        var lastDiscardStats = PageContextDetector.getLastDiscardStats();
        var lastAppliedActions = DomApplier.getLastAppliedActions();

        /* eslint-disable no-console */
        console.log('[adaptive_monitor debug] elementos detectados en el último escaneo: ' + lastScannedElements.length);
        if (console.table) {
            console.table(lastScannedElements);
        } else {
            console.log(lastScannedElements);
        }

        // C.2 — antes esta pérdida era invisible: cuántos candidatos válidos no entraron al
        // tope global (MAX_DYNAMIC_TOTAL) y de qué rol.
        if (lastDiscardStats && lastDiscardStats.total > 0) {
            console.log('[adaptive_monitor debug] podados por el tope global: ' + lastDiscardStats.total
                + ' — por rol:', lastDiscardStats.byRole);
        } else {
            console.log('[adaptive_monitor debug] podados por el tope global: 0');
        }

        if (!lastAppliedActions.length) {
            console.log('[adaptive_monitor debug] no hay ninguna adaptación aplicada todavía en esta sesión.');
            return;
        }

        console.log('[adaptive_monitor debug] última adaptación — selectores vs. DOM actual:');
        lastAppliedActions.forEach(function(act) {
            var count;
            try {
                count = document.querySelectorAll(act.selector).length;
            } catch (e) {
                count = -1; // selector inválido (no debería pasar el sanitizador, pero por si acaso)
            }
            var status = count > 0 ? ('OK, ' + count + ' elemento(s)') : 'SIN EFECTO, 0 elementos';
            console.log('  [' + status + '] ' + act.action + ' → ' + act.selector);
        });
        /* eslint-enable no-console */
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INIT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Inicializa el módulo: conecta el toggle y arranca el polling si está habilitado.
     * @param {number} courseId courseid del curso donde vive el bloque (0 si no aplica) —
     * ver block_adaptive_ui.php::get_content(). Se reenvía a mape/execute/panels (recordAction,
     * Fase 7) vía setCourseId().
     */
    function init(courseId) {
        var normalizedCourseId = courseId || 0;
        Log.debug('adaptive_monitor: init — interval=' + POLL_INTERVAL + 'ms, courseid=' + normalizedCourseId);
        Panels.setCourseId(normalizedCourseId);

        // Helper de verificación en vivo (window.__adaptiveDebug()) — ver adaptiveDebug().
        window.__adaptiveDebug = adaptiveDebug;

        // Listener delegado del truncado interactivo (Confundido) — adjuntado una sola vez.
        Panels.attachTruncateDelegate();

        // Escuchar el toggle del bloque
        var toggle = document.getElementById('adaptive-ui-toggle');
        if (toggle) {
            toggle.checked = isEnabled();
            toggle.addEventListener('change', function() {
                setEnabled(this.checked);
            });
        }

        // Arrancar solo si está habilitado
        if (isEnabled()) {
            startPolling();
        } else {
            updateToggleUI(false);
            Log.debug('adaptive_monitor: iniciado en modo pausado');
        }
    }

    return {init: init};
});
