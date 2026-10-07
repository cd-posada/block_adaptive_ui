// Adaptive_monitor.js — Monitor y Execute del ciclo MAPE-K
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
 * AMD module: mape/monitor/emotion_log_reader
 *
 * v3.0 (2026-09-25) — fuente de emociones del Monitor. local_emotions_send v5 ya no guarda
 * userid/emotion en mdl_local_emotions_log; escribe cada detección en el sessionStorage del
 * navegador (clave 'emotions_log', ver local/emotions_send/js/session_log.js):
 *
 *   {"type":"second", "timestamp":4.02, "emotion":"Concentrado", "level":0, "probability":0.5674}
 *   {"type":"window", ...}   (resúmenes de 5-10s — aquí se ignoran)
 *
 * Solo se usan las entradas "second": dominant_emotion_calculator cuenta detecciones
 * individuales (dominancia >= 50%, tendencia por bisección), y las "window" son agregados de
 * tamaño variable que cambiarían esa semántica.
 *
 * Hora absoluta: el "timestamp" del plugin es relativo a la carga de CADA página (se reinicia
 * al navegar aunque el arreglo persista), así que no sirve para filtrar "últimos 90s". Este
 * módulo mantiene su propio buffer en sessionStorage ('adaptive_ui_emotion_buffer') con hora
 * absoluta: en cada refresh toma solo las entradas nuevas del log (desde processed), ancla la
 * más reciente a "ahora" y deriva las anteriores de las diferencias de timestamp. Si el
 * timestamp retrocede hay una recarga de página en medio: ese tramo se ancla justo antes del
 * siguiente. Error esperado ~1-5s, despreciable frente a la ventana de 90s.
 *
 * El servidor revalida todo (client_emotion_log.php) — esto solo prepara los datos.
 *
 * @module block_adaptive_ui/mape/monitor/emotion_log_reader
 */
define(['core/log'], function(Log) {
    'use strict';

    var SOURCE_KEY = 'emotions_log';
    var BUFFER_KEY = 'adaptive_ui_emotion_buffer';
    // var WINDOW_SECONDS = 90; // Debe coincidir con analyze_config::WINDOW_SECONDS.
    var WINDOW_SECONDS = 20; 
    var MAX_RECORDS = 150;   // Debe coincidir con client_emotion_log::MAX_RECORDS.

    // Etiquetas del plugin (en español o como código del catálogo local_emotions_emotion) →
    // etiqueta canónica del bloque. Claves sin tildes y en minúsculas, ver normalizeKey().
    var EMOTION_MAP = {
        'aburrido': 'Aburrido',
        'boredom': 'Aburrido',
        'concentrado': 'Concentrado',
        'engagement': 'Concentrado',
        'compromiso': 'Concentrado',
        'confundido': 'Confundido',
        'confusion': 'Confundido',
        'frustrado': 'Frustrado',
        'frustration': 'Frustrado',
    };

    /**
     * @param {string} label
     * @return {string}
     */
    function normalizeKey(label) {
        return String(label).normalize('NFD').replace(/[̀-ͯ]/g, '').trim().toLowerCase();
    }

    /**
     * @param {*} label Etiqueta tal como la escribió el plugin.
     * @return {string|null} Etiqueta canónica, o null si no es una de las 4 conocidas.
     */
    function normalizeEmotion(label) {
        if (typeof label !== 'string') {
            return null;
        }
        return EMOTION_MAP[normalizeKey(label)] || null;
    }

    /** @return {{processed: number, records: Array}|null} null si el buffer aún no existe. */
    function loadState() {
        try {
            var state = JSON.parse(sessionStorage.getItem(BUFFER_KEY) || 'null');
            if (state && typeof state.processed === 'number' && Array.isArray(state.records)) {
                return state;
            }
        } catch (e) {
            // Estado corrupto o storage bloqueado: se trata como inexistente.
        }
        return null;
    }

    /** @param {{processed: number, records: Array}} state */
    function saveState(state) {
        try {
            sessionStorage.setItem(BUFFER_KEY, JSON.stringify(state));
        } catch (e) {
            Log.debug('adaptive_monitor: no se pudo guardar el buffer de emociones: ' + e);
        }
    }

    /** @return {Array} Log crudo del plugin, [] si no existe o no es JSON legible. */
    function readRawLog() {
        try {
            var log = JSON.parse(sessionStorage.getItem(SOURCE_KEY) || '[]');
            return Array.isArray(log) ? log : [];
        } catch (e) {
            return [];
        }
    }

    /**
     * @param {Array} records
     * @param {number} now Epoch en segundos.
     * @return {Array} Registros dentro de la ventana, como máximo MAX_RECORDS.
     */
    function prune(records, now) {
        var cutoff = now - WINDOW_SECONDS;
        return records.filter(function(r) {
            return r.t >= cutoff;
        }).slice(-MAX_RECORDS);
    }

    /**
     * Incorpora al buffer las entradas del log que aún no se habían procesado.
     *
     * @param {Array} log Arreglo completo de 'emotions_log'.
     */
    function syncFromLog(log) {
        if (!Array.isArray(log)) {
            return;
        }
        var state = loadState();
        var now = Date.now() / 1000;

        // Primera lectura de esta pestaña: el log puede traer entradas de hace mucho (páginas
        // anteriores sin el bloque) y no hay forma de saber cuándo ocurrieron. Se marcan como
        // ya vistas y solo cuenta lo que llegue a partir de ahora (se pierde un poll, ~5s).
        if (!state) {
            saveState({processed: log.length, records: []});
            return;
        }

        // El log se reinició (pestaña nueva, limpieza manual): se relee desde el principio.
        if (log.length < state.processed) {
            state.processed = 0;
        }

        var fresh = log.slice(state.processed).filter(function(e) {
            return e && e.type === 'second' && typeof e.timestamp === 'number' &&
                typeof e.probability === 'number';
        });

        // Tramos separados por recargas de página (el timestamp relativo retrocede).
        var segments = [];
        fresh.forEach(function(e) {
            var current = segments[segments.length - 1];
            if (!current || e.timestamp < current[current.length - 1].timestamp) {
                segments.push([e]);
            } else {
                current.push(e);
            }
        });

        // Anclaje de atrás hacia adelante: el último tramo termina "ahora", cada tramo anterior
        // termina 1s antes de que empiece el siguiente.
        var anchored = [];
        var endAt = now;
        for (var s = segments.length - 1; s >= 0; s--) {
            var seg = segments[s];
            var lastTs = seg[seg.length - 1].timestamp;
            var converted = [];
            for (var i = 0; i < seg.length; i++) {
                converted.push({entry: seg[i], t: endAt - (lastTs - seg[i].timestamp)});
            }
            anchored = converted.concat(anchored);
            endAt = converted[0].t - 1;
        }

        anchored.forEach(function(a) {
            var emotion = normalizeEmotion(a.entry.emotion);
            if (!emotion) {
                Log.debug('adaptive_monitor: emoción desconocida descartada: ' + a.entry.emotion);
                return;
            }
            state.records.push({
                emotion: emotion,
                confidence: Math.max(0, Math.min(1, a.entry.probability)),
                t: Math.round(a.t),
            });
        });

        state.processed = log.length;
        state.records = prune(state.records, now);
        saveState(state);
    }

    /**
     * Relee el log del plugin. Usa la API pública del plugin (window.EmotionsSessionLog.readLog,
     * que devuelve Promise y seguiría funcionando si el plugin vuelve a cifrar el log) y, si no
     * está cargada en esta página, lee sessionStorage directamente.
     *
     * @return {Promise}
     */
    function refresh() {
        var api = window.EmotionsSessionLog;
        if (api && typeof api.readLog === 'function') {
            return Promise.resolve(api.readLog()).then(syncFromLog).catch(function(err) {
                Log.debug('adaptive_monitor: EmotionsSessionLog.readLog falló: ' + err);
                syncFromLog(readRawLog());
            });
        }
        syncFromLog(readRawLog());
        return Promise.resolve();
    }

    /**
     * @return {string} JSON [{emotion, confidence, t}] de los últimos 90s, listo para el WS.
     */
    function getWindowJson() {
        var state = loadState();
        return JSON.stringify(state ? prune(state.records, Date.now() / 1000) : []);
    }

    return {
        refresh: refresh,
        getWindowJson: getWindowJson,
        normalizeEmotion: normalizeEmotion,
    };
});
