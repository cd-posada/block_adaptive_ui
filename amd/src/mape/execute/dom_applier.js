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
 * AMD module: mape/execute/dom_applier
 *
 * Aplica css_vars + dom_actions decididos por Plan (extraído de adaptive_monitor.js en la
 * Fase B.3, 2026-07-22).
 *
 * appliedClasses y lastAppliedActions son estado de módulo: appliedClasses lo escribe
 * applyDOMChanges() (caso 'add_class') y lo lee/resetea mape/execute/dom_reverter.js
 * (revertDOMChanges); lastAppliedActions lo escribe applyDOMChanges() y lo lee el shell
 * delgado (adaptiveDebug(), vía getLastAppliedActions()) — de ahí los getters/reset
 * exportados.
 *
 * @module block_adaptive_ui/mape/execute/dom_applier
 */
define([
    'core/log',
    'block_adaptive_ui/mape/config',
    'block_adaptive_ui/mape/execute/transition_manager',
    'block_adaptive_ui/mape/execute/explainer',
], function(Log, Config, TransitionManager, Explainer) {
    'use strict';

    // Clases añadidas vía add_class, para poder quitarlas en revertDOMChanges() (2026-07-06 —
    // antes de este fix nunca se limpiaban: una vez que Aburrido añadía adaptive-card-layout/
    // adaptive-aburrido-icons a body, quedaban ahí para siempre, incluso en adaptaciones
    // posteriores de otra emoción, causando que el naranja de Aburrido persistiera sin
    // importar qué emoción nueva se confirmara).
    var appliedClasses = [];

    // Clases QUITADAS vía remove_class, para poder devolverlas en revertDOMChanges()
    // (2026-08-08). Solo se registra si la clase estaba realmente presente antes de quitarla —
    // classList.remove() sobre una clase ausente es un no-op y no habría nada que restaurar.
    // Con esa comprobación no hace falta el "snapshot completo del estado de clases previas"
    // que antes se citaba como motivo para dejar remove_class sin revertir.
    var removedClasses = [];

    // Claves de css_vars realmente inyectadas en :root. El revert resetea las de DEFAULT_CSS,
    // pero cualquier variable fuera de esa lista quedaría pegada en el style inline de <html>
    // para siempre. Hoy la whitelist del sanitizador no las produce, pero registrarlas evita
    // que una variable nueva se convierta en residuo silencioso en el futuro.
    var appliedCssVars = [];

    // Estado para window.__adaptiveDebug() — la última adaptación aplicada, para poder
    // verificar en vivo en DevTools qué se aplicó en cualquier tipo de contenido.
    var lastAppliedActions = [];

    /**
     * Ejecuta UNA dom_action sobre todos los elementos que casen con su selector.
     *
     * Extraída del bucle de applyDOMChanges() al introducir el modo "escalonada": el cuerpo es
     * idéntico al de antes, pero ahora puede invocarse de forma diferida (ver schedule() en
     * transition_manager). La consulta al DOM ocurre en el momento de ejecutar, no al
     * programar, que es lo correcto si una tanda anterior ya modificó la página.
     *
     * @param {Object} act  dom_action ya sanitizada.
     * @param {string} mode Modo de transición efectivo, ya resuelto por resolveMode().
     */
    function applyOneAction(act, mode) {
        var selector = act.selector;
        var action = act.action;
        var value = act.value || '';

        var elements = document.querySelectorAll(selector);
        elements.forEach(function(el) {
            switch (action) {
                case 'hide':
                    TransitionManager.applyTransition(el, 'hide', null, mode);
                    break;
                case 'show':
                    TransitionManager.applyTransition(el, 'show', null, mode);
                    break;
                case 'set_style':
                    TransitionManager.applyTransition(
                        el, 'set_style', {property: act.property, value: value}, mode
                    );
                    break;
                case 'add_class':
                    el.classList.add(value);
                    appliedClasses.push({el: el, value: value});
                    break;
                case 'remove_class':
                    if (el.classList.contains(value)) {
                        removedClasses.push({el: el, value: value});
                    }
                    el.classList.remove(value);
                    break;
                default:
                    break;
            }
            // Adaptation Explainer por elemento — ver attachExplainerIndicator().
            Explainer.attachExplainerIndicator(el, act);
        });
    }

    /**
     * Agrupa las dom_actions por selector, conservando el orden de llegada del primer uso de
     * cada uno.
     *
     * Agrupar por ELEMENTO y no por acción es la decisión central del modo "escalonada": el
     * LLM emite varias acciones sobre el mismo elemento (fuente, luego color, luego una clase),
     * y escalonarlas por separado haría que el mismo párrafo mutase tres veces en secuencia —
     * se vería roto, no narrado. Lo que el estudiante debe poder seguir es qué ELEMENTO cambia,
     * no cuántas propiedades se le tocaron.
     *
     * Los datos reales lo respaldan: sobre las 107 adaptaciones del log, la mediana es de 11
     * acciones pero solo 7 elementos distintos.
     *
     * @param {Array<Object>} domActions Acciones ya sanitizadas.
     * @return {Array<Array<Object>>} Tandas, en orden de aparición.
     */
    function groupBySelector(domActions) {
        var groups = [];
        // Object.create(null): sin prototipo, así un selector que se llamara igual que una
        // propiedad heredada ("constructor", "__proto__") no puede colisionar con ella.
        var indexOf = Object.create(null);

        domActions.forEach(function(act) {
            // show_resources nunca fue una mutación del DOM (el recurso lo pinta
            // showFeedbackBox a partir de css_params.suggested_resource) — se descarta antes de
            // agrupar para que no genere una tanda vacía que consuma un paso del escalonado.
            if (act.action === 'show_resources') {
                return;
            }
            var key = act.selector;
            if (indexOf[key] === undefined) {
                indexOf[key] = groups.length;
                groups.push([]);
            }
            groups[indexOf[key]].push(act);
        });
        return groups;
    }

    /**
     * @param {Object} cssParams css_vars y dom_actions del motor de adaptación (Plan).
     * @param {string} emotion  Emoción activa.
     * @param {string} normanLevel Nivel del modelo de Norman.
     */
    function applyDOMChanges(cssParams, emotion, normanLevel) {
        var root = document.documentElement;

        // 1. Reset a valores por defecto
        Object.keys(Config.DEFAULT_CSS).forEach(function(prop) {
            root.style.setProperty(prop, Config.DEFAULT_CSS[prop]);
        });
        // --emotion-accent-warm es opcional/específica de Aburrido, no un valor "siempre
        // presente" como las de DEFAULT_CSS — se limpia con removeProperty en vez de
        // fijarle un valor por defecto, para que no quede un naranja residual si una
        // adaptación anterior la fijó y la nueva emoción no la usa.
        root.style.removeProperty('--emotion-accent-warm');

        // 2. Aplicar CSS custom properties del motor de adaptación (Plan)
        var cssVars = cssParams.css_vars || {};
        Object.keys(cssVars).forEach(function(prop) {
            root.style.setProperty(prop, cssVars[prop]);
            if (appliedCssVars.indexOf(prop) === -1) {
                appliedCssVars.push(prop);
            }
        });

        // 2b. Ritmo de la transición — etapa 5 ("Transition") del modelo ISATINE. El LLM lo
        // eligió según la emoción; resolveMode() aplica el default y la anulación por
        // prefers-reduced-motion. Se fija --adaptive-transition-duration porque el grueso del
        // fundido NO lo escribe el transitioner: lo escribe la regla comodín
        // body[data-emotion] * de styles.css, que lee esta variable. Sin esto, "suave" solo
        // afectaría a las pocas propiedades que se tocan vía estilo inline.
        // La variable está en DEFAULT_CSS, así que el paso 1 ya la reseteó y revertDOMChanges()
        // la elimina — no hace falta registrarla en appliedCssVars.
        var mode = TransitionManager.resolveMode(cssParams.transition);
        root.style.setProperty('--adaptive-transition-duration', TransitionManager.durationMs(mode) + 'ms');

        // 3. Ejecutar acciones DOM, por tandas de un elemento cada una
        // 2c. Marcar emoción y nivel Norman en body ANTES de ejecutar las acciones (2026-09-16).
        // El orden importa y no es cosmético: TODAS las reglas de transición de styles.css están
        // acotadas por `body[data-emotion]`. Mientras este atributo se ponía al final, cualquier
        // propiedad que cambiara una acción del paso 3 lo hacía con las transiciones aún
        // inactivas, y por tanto saltaba de golpe.
        // Medido en Chrome real: el estrechamiento de la columna de contenido que aplica
        // "adaptive-zen-layout" (max-width) seguía siendo instantáneo incluso tras darle su
        // propia regla de transición, porque la regla todavía no aplicaba cuando la clase se
        // añadía. Poniendo el atributo primero, la regla ya está activa y el ancho funde.
        // Es seguro adelantarlo: las reglas de color/tipografía gateadas por data-emotion leen
        // las css_vars que el paso 2 ya escribió, así que su cambio ocurre en el mismo recálculo
        // en que la transición se activa — que es justo cuando el navegador la dispara.
        document.body.setAttribute('data-emotion', emotion);
        document.body.setAttribute('data-norman', normanLevel);

        // Consolida el estado base ANTES de que las acciones lo cambien. Leer offsetWidth obliga
        // al navegador a recalcular estilo y layout en este punto; sin esa lectura, el atributo
        // de arriba y las clases que añade el paso 3 caerían en el MISMO recálculo y el
        // navegador nunca vería el valor de partida — compararía el estado previo a la
        // adaptación (donde las reglas gateadas por data-emotion ni siquiera aplican) contra el
        // final, y las propiedades cuyo valor inicial es una palabra clave saltarían de golpe.
        // Caso concreto medido en Chrome real: #region-main-box pasa de "max-width: none" a
        // "100%" al activarse data-emotion, y de ahí a 740px cuando "adaptive-zen-layout" entra;
        // solo con este flush intermedio el segundo tramo interpola en vez de saltar.
        // Cuesta un layout forzado por adaptación (una vez cada varios minutos), no por acción.
        void document.body.offsetWidth;

        var domActions = cssParams.dom_actions || [];
        lastAppliedActions = domActions; // ver window.__adaptiveDebug()

        var groups = groupBySelector(domActions);
        var step = TransitionManager.staggerStep(mode, groups.length);
        groups.forEach(function(group, i) {
            // step === 0 en los cuatro modos no escalonados, y schedule() con retardo 0 ejecuta
            // de forma síncrona sin crear ningún timer — así esos modos conservan exactamente
            // el comportamiento anterior, sin temporizadores nuevos que puedan quedar colgados.
            TransitionManager.schedule(function() {
                group.forEach(function(act) {
                    applyOneAction(act, mode);
                });
            }, step * i);
        });

        // 3b. El toggle de párrafos truncados (Confundido) ya no requiere registrar nada aquí
        // — un único listener delegado (ver attachTruncateDelegate(), adjuntado una vez en
        // init()) resuelve el clic sobre cualquier .adaptive-truncate presente ahora o en el
        // futuro, sin importar cuántas adaptaciones se apliquen/reviertan.

        Log.debug(
            'adaptive_monitor: DOM changes applied for ' + emotion + ' (' + normanLevel + ')' +
            ' — transición: ' + mode + ' (' + TransitionManager.durationMs(mode) + 'ms' +
            (step ? ', ' + groups.length + ' tandas cada ' + step + 'ms' : '') + ')'
        );
    }

    /** @return {Array<Object>} Clases añadidas vía add_class, con su elemento de origen. */
    function getAppliedClasses() {
        return appliedClasses;
    }

    /** Limpia el registro de clases añadidas (llamado desde revertDOMChanges). */
    function resetAppliedClasses() {
        appliedClasses = [];
    }

    /** @return {Array<Object>} Clases quitadas vía remove_class que sí estaban presentes. */
    function getRemovedClasses() {
        return removedClasses;
    }

    /** Limpia el registro de clases quitadas (llamado desde revertDOMChanges). */
    function resetRemovedClasses() {
        removedClasses = [];
    }

    /** @return {Array<string>} Nombres de las CSS custom properties inyectadas en :root. */
    function getAppliedCssVars() {
        return appliedCssVars;
    }

    /** Limpia el registro de CSS custom properties (llamado desde revertDOMChanges). */
    function resetAppliedCssVars() {
        appliedCssVars = [];
    }

    /** @return {Array<Object>} dom_actions de la última adaptación aplicada. */
    function getLastAppliedActions() {
        return lastAppliedActions;
    }

    return {
        applyDOMChanges: applyDOMChanges,
        getAppliedClasses: getAppliedClasses,
        resetAppliedClasses: resetAppliedClasses,
        getRemovedClasses: getRemovedClasses,
        resetRemovedClasses: resetRemovedClasses,
        getAppliedCssVars: getAppliedCssVars,
        resetAppliedCssVars: resetAppliedCssVars,
        getLastAppliedActions: getLastAppliedActions,
    };
});
