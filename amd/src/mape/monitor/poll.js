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
 * AMD module: mape/monitor/poll
 *
 * Ciclo de polling (Monitor+Analyze, sin LLM) y decisión de cooldown por emoción (extraído de
 * adaptive_monitor.js en la Fase B.3, 2026-07-22). emotionCooldown se importa de mape/config
 * (mismo objeto compartido por referencia, no una copia local) porque config.js lo declara
 * junto al resto de constantes del bloque original 41-122, aunque el único lector/escritor
 * real sea este módulo.
 *
 * El intervalo (setInterval) NO vive aquí — este módulo solo expone poll() como función pura
 * de "un ciclo"; startPolling()/stopPolling() (que programan/cancelan el setInterval) se
 * quedan en el shell delgado (adaptive_monitor.js), igual que ya se decidió para
 * pollIntervalId (ver Paso 5 del informe de dependencias: pollIntervalId nunca lo usa poll()
 * mismo, solo startPolling/stopPolling). Es la opción más simple: no hay necesidad de que
 * poll.js sepa nada de temporizadores.
 *
 * @module block_adaptive_ui/mape/monitor/poll
 */
define([
    'core/ajax',
    'core/log',
    'block_adaptive_ui/mape/config',
    'block_adaptive_ui/mape/monitor/page_context_detector',
    'block_adaptive_ui/mape/monitor/emotion_log_reader',
    'block_adaptive_ui/mape/execute/panels',
], function(Ajax, Log, Config, PageContextDetector, EmotionLogReader, Panels) {
    'use strict';

    /**
     * @param {string} emotion Emoción a verificar.
     * @return {boolean} true si procede mostrar el panel.
     */
    function shouldShowPanel(emotion) {
        // No reofrecer una emoción que YA está aplicada (2026-08-08). Antes solo se miraba el
        // cooldown, así que al mantenerse la misma emoción dominante el panel reaparecía a los
        // 30s y, al aceptarlo, se revertía y reaplicaba la MISMA adaptación: el estudiante
        // esperaba ~14s para no ver ningún cambio. Reportado como "me recomienda dos
        // adaptaciones seguidas de la misma emoción y la segunda no cambia nada".
        //
        // No es un problema del LLM: medido sobre el log, el 51% de los pares consecutivos de
        // la misma emoción traen una paleta visualmente idéntica (en 9 de ellos la ÚNICA
        // diferencia eran las mayúsculas del hexadecimal), y Aburrido eligió #FF8C00 en 37 de
        // 40 llamadas. Con la misma página y la misma emoción, la entrada del modelo es
        // prácticamente idéntica y su salida también — reofrecer no puede cambiar nada visible.
        //
        // data-emotion es exactamente el rastro de "hay una adaptación activa para X":
        // lo pone applyDOMChanges() y lo quita revertDOMChanges(). Si el estudiante cierra o
        // revierte, desaparece y el panel vuelve a estar disponible tras el cooldown.
        if (document.body.getAttribute('data-emotion') === emotion) {
            return false;
        }
        var last = Config.emotionCooldown[emotion];
        return !last || (Date.now() - last) >= Config.COOLDOWN_MS;
    }

    /**
     * Ejecuta un ciclo de polling: lee las emociones nuevas del sessionStorage de
     * local_emotions_send (v3.0, ver emotion_log_reader), detecta contexto, consulta WS y actúa.
     */
    function poll() {
        EmotionLogReader.refresh().then(function() {
            var pageContext = PageContextDetector.detectPageContext();

            return Ajax.call([{
                methodname: 'block_adaptive_ui_get_adaptation',
                args: {
                    page_context: JSON.stringify(pageContext), // eslint-disable-line camelcase
                    emotion_log: EmotionLogReader.getWindowJson(), // eslint-disable-line camelcase
                },
            }])[0];
        }).then(function(data) {
            Log.debug('adaptive_monitor: poll → ' + (data ? data.action : 'null') + (data ? (' | ' + data.emotion) : ''));

            // C.5 (2026-07-28): Concentrado (Compromiso) ya no es un caso especial de
            // "revertir" — entra por el mismo flujo de panel que las otras 3 emociones. La
            // rama que vivía aquí llamaba a DomReverter.revertDOMChanges() apenas se detectaba
            // Concentrado, ANTES de que el estudiante hiciera nada; ya no hace falta: el botón
            // "Activar cambios" (panels.js::showRecommendationPanel) ya llama a
            // revertDOMChanges() justo antes de applyDOMChanges() para CUALQUIER emoción, así
            // que Concentrado revierte lo anterior en el mismo momento que cualquier otra.
            if (!data || !data.found || data.action !== 'recommend') {
                return null;
            }

            // get_adaptation ya NO llama al motor LLM (eso ocurre solo al aceptar, en
            // apply_adaptation) — aquí solo tenemos emotion/trend, todavía no hay
            // css_params que aplicar en silencio durante el cooldown.
            var payload = {emotion: data.emotion, trend: data.trend};

            if (shouldShowPanel(data.emotion)) {
                Config.emotionCooldown[data.emotion] = Date.now();
                Panels.showRecommendationPanel(payload);
            } else if (document.body.getAttribute('data-emotion') === data.emotion) {
                Log.debug('adaptive_monitor: ' + data.emotion + ' ya está aplicada — no se reofrece');
            } else {
                var remaining = Math.ceil((Config.COOLDOWN_MS - (Date.now() - Config.emotionCooldown[data.emotion])) / 1000);
                Log.debug('adaptive_monitor: cooldown activo para ' + data.emotion + ' — espera ' + remaining + 's');
            }
            return null;

        }).catch(function(err) {
            Log.error('adaptive_monitor: poll ERROR: ' + err);
        });
    }

    return {
        poll: poll,
    };
});
