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
 * AMD module: mape/execute/transition_manager
 *
 * Adaptation Transitioner (extraído de adaptive_monitor.js en la Fase B.3, 2026-07-22) —
 * decide CÓMO se aplica visualmente un cambio que Plan ya decidió (no QUÉ cambiar; eso lo
 * resuelve el LLM). Módulo con nombre propio porque el diagrama de arquitectura lo nombra
 * como componente independiente ("Adaptation Transitioner"), no como comportamiento disperso.
 *
 * hiddenElements es estado de módulo: lo escribe applyTransition() (caso 'hide') y lo leen/
 * resetean mape/execute/dom_reverter.js (revertDOMChanges), mape/monitor/poll.js (poll) y
 * mape/execute/panels.js (showRecommendationPanel) — de ahí los getters/reset exportados en
 * vez de una variable de módulo privada sin acceso externo.
 *
 * styledElements (2026-08-08) es el registro hermano para 'set_style'/'show', con el mismo
 * patrón de getters/reset y el mismo único consumidor (dom_reverter.js). Sin él, ninguna de esas
 * dos acciones era reversible — ver el comentario de la declaración más abajo.
 *
 * MODOS DE TRANSICIÓN — etapa 5 ("Transition") del modelo ISATINE (López-Jaquero,
 * Vanderdonckt, Montero y González, EHCI/EIS 2007), que separa CÓMO se lleva al usuario de la
 * interfaz vieja a la nueva del QUÉ se cambia (eso lo decide Plan). Hasta esta fase el
 * parámetro `mode` existía en la firma pero ningún caller lo pasaba, así que siempre caía en
 * "gradual"; ahora lo elige el LLM por emoción y lo valida sanitizer::sanitize_transition().
 *
 * Duraciones BASE (las efectivas son estas × SPEED, ver la constante más abajo):
 *   - "inmediata"  (0)   — sin transición, el cambio aparece de golpe. Concentrado.
 *   - "rapida"     (200) — cambio ágil y perceptible. Aburrido.
 *   - "gradual"    (400) — el comportamiento histórico con SPEED=1. Default y respaldo.
 *   - "suave"      (900) — cambio calmado, no sobresalta. Frustrado.
 *   - "escalonada" (400 + 120 entre elementos) — los elementos entran por tandas en vez de
 *     todos a la vez, para que el cambio sea SEGUIBLE. Confundido.
 *
 * "overview" (preview de varios cambios antes de aplicarlos) sigue FUERA DE ALCANCE: cambiaría
 * el contrato de interacción (el estudiante confirmaría dos veces) y colisiona con el panel de
 * recomendación, que ya cumple ese rol. Decisión de alcance, no limitación técnica.
 *
 * pendingTimers (esta fase) es el tercer registro del módulo, y existe SOLO por "escalonada":
 * cada tanda diferida es una mutación pendiente que revertDOMChanges() debe poder cancelar. Es
 * la misma lección que dejó el bug de la carrera de los 400ms del 2026-08-08 — el registro es
 * síncrono, nunca dentro del callback — pero con una ventana mucho más ancha: de 400ms a más
 * de un segundo.
 *
 * @module block_adaptive_ui/mape/execute/transition_manager
 */
// Sin dependencia de 'core/log': el único uso que tenía era avisar de un modo no implementado,
// y desde que los cinco modos existen ese aviso no puede ocurrir. El modo efectivamente
// aplicado se registra una sola vez por adaptación en dom_applier, junto con la emoción.
define([], function() {
    'use strict';

    // Tabla de modos — FUENTE ÚNICA de los milisegundos de toda la fase Execute. Antes de esta
    // fase el 0.4s vivía duplicado como dos literales independientes (el valor de la propiedad
    // CSS 'transition' y el setTimeout de 'hide'); si divergían, el elemento pasaba a
    // display:none antes de terminar el fundido, o se quedaba invisible ocupando espacio.
    // Los nombres deben coincidir EXACTAMENTE con sanitizer::TRANSITION_WHITELIST (PHP).
    // Factor de calibración del ritmo — EL ÚNICO NÚMERO A TOCAR para probar transiciones más
    // lentas o más rápidas. Escala la tabla entera y el presupuesto del escalonado, así que
    // ningún otro valor de este módulo hay que ajustar a mano.
    //
    // Los valores base de abajo se dejan INTACTOS a propósito: separar "diseño original" de
    // "factor experimental" hace que en cualquier momento se pueda decir qué ritmo se estaba
    // probando y volver al de partida con SPEED = 1.
    //
    // Al cambiarlo hay que: (1) regenerar el build AMD (npx grunt amd --root=blocks/adaptive_ui)
    // porque Moodle sirve el .min.js, y (2) ajustar los tres valores por defecto que viven
    // fuera de este archivo y que SPEED no puede alcanzar — ver el comentario de
    // --adaptive-transition-duration en mape/config.js y en styles.css.
    var SPEED = 2;

    var MODES = {
        'inmediata':  {duration: 0   * SPEED, stagger: 0},
        'rapida':     {duration: 200 * SPEED, stagger: 0},
        'gradual':    {duration: 400 * SPEED, stagger: 0},
        'suave':      {duration: 900 * SPEED, stagger: 0},
        'escalonada': {duration: 400 * SPEED, stagger: 120 * SPEED},
    };

    // Debe coincidir con sanitizer::TRANSITION_DEFAULT (PHP).
    var DEFAULT_MODE = 'gradual';

    // Tope duro del escalonado completo. Medido sobre las 107 adaptaciones reales del log:
    // mediana de 7 elementos distintos por adaptación, p90 de 11, máximo de 20. Con el paso
    // base de 120ms eso da 840ms en el caso típico, pero 2,4s en el peor — demasiado. El paso
    // real se comprime para que el total nunca supere este presupuesto (ver staggerStep()).
    // Escala con SPEED: si no lo hiciera, subir el paso no serviría de nada porque el
    // presupuesto lo volvería a comprimir al valor anterior.
    var STAGGER_BUDGET_MS = 1200 * SPEED;

    // Registro de elementos ocultados para revert
    var hiddenElements = [];

    // Temporizadores de las tandas del modo "escalonada" que aún no han disparado. Se registran
    // de forma SÍNCRONA al programarlos (nunca dentro del callback) para que revertDOMChanges()
    // pueda cancelarlos aunque el revert ocurra a mitad de la secuencia — panels.js ejecuta
    // revert y apply consecutivos y sin espera, así que esa ventana es perfectamente alcanzable.
    var pendingTimers = [];

    // Registro de estilos inline sobrescritos, para devolver cada propiedad a su valor previo
    // en revertDOMChanges() (2026-08-08). Antes de esto, 'set_style' era una mutación de una
    // sola dirección: escribía element.style[prop] sin leer nada antes y sin empujar a ningún
    // registro, así que NADA podía deshacerla — ni "Revertir cambios", ni el toggle OFF, ni el
    // revert previo a aplicar la siguiente adaptación. Era además la acción MÁS usada del motor
    // (118 de 234 dom_actions reales en plan_engine_log.jsonl), y la causa del degradado naranja
    // de Aburrido (set_style body backgroundImage) que sobrevivía a la reversión.
    // 'show' comparte el mismo registro: también destruía el display/opacity previos.
    var styledElements = [];

    /**
     * Convierte la clave camelCase del objeto style del DOM al nombre real de la propiedad CSS.
     * Necesario porque `element.style.backgroundImage` se escribe así en JS, pero dentro del
     * VALOR de `transition` hay que decir `background-image`.
     *
     * @param {string} property Clave camelCase (ej. "backgroundImage").
     * @return {string} Nombre CSS en kebab-case (ej. "background-image").
     */
    function cssPropertyName(property) {
        return String(property).replace(/[A-Z]/g, function(letter) {
            return '-' + letter.toLowerCase();
        });
    }

    /**
     * Guarda el valor inline actual de una propiedad ANTES de pisarla. Todas las ramas de
     * applyTransition() pasan por aquí, de modo que styledElements es el registro ÚNICO y
     * cronológico de estilos inline: el revert lo recorre en orden inverso estricto y deshace
     * todo correctamente aunque varias acciones toquen el mismo elemento y la misma propiedad.
     *
     * Tener dos registros gestionando 'transition' (uno para hide, otro para set_style) fue un
     * bug real: al restaurar hide primero y set_style después, el segundo reponía el
     * 'transition' que el primero acababa de escribir, y quedaba pegado.
     *
     * @param {Element} element  Elemento a fotografiar.
     * @param {string} property  Propiedad de style que está a punto de sobrescribirse.
     */
    function snapshotStyle(element, property) {
        styledElements.push({
            el: element,
            property: property,
            prevValue: element.style[property] || '',
        });
    }

    /**
     * Resuelve el modo EFECTIVO a partir del que pidió el LLM. Único punto donde se decide qué
     * modo se usa realmente — el resto del código llama aquí y confía en el resultado.
     *
     * Dos anulaciones, en este orden:
     * 1. Modo desconocido/ausente → DEFAULT_MODE. El sanitizador de PHP ya degrada a "gradual",
     *    así que esto es defensa en profundidad: cubre el caso de un JSON.parse fallido, que en
     *    panels.js devuelve {} y nunca pasa por el sanitizador.
     * 2. prefers-reduced-motion: reduce → 'inmediata', POR ENCIMA de lo que pidiera el LLM. Es
     *    una preferencia de accesibilidad declarada por la persona en su sistema operativo; el
     *    modelo no tiene autoridad para sobreescribirla. Mismo criterio que ya se aplicó con el
     *    contraste WCAG: el LLM decide la intención, el sistema impone el piso de accesibilidad.
     *
     * matchMedia se consulta en cada llamada, no una sola vez al cargar el módulo: la
     * preferencia puede cambiar durante la sesión, y una adaptación se aplica cada varios
     * minutos, así que el coste es irrelevante frente a quedarse con un valor obsoleto.
     *
     * @param {string} requested Modo pedido por el motor de Plan (puede venir undefined).
     * @return {string} Una clave garantizada de MODES.
     */
    function resolveMode(requested) {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return 'inmediata';
        }
        return Object.prototype.hasOwnProperty.call(MODES, requested) ? requested : DEFAULT_MODE;
    }

    /**
     * @param {string} mode Modo ya resuelto por resolveMode().
     * @return {number} Duración de la transición en milisegundos.
     */
    function durationMs(mode) {
        return (MODES[mode] || MODES[DEFAULT_MODE]).duration;
    }

    /**
     * Paso real entre tandas del modo "escalonada", comprimido para que la secuencia completa
     * nunca exceda STAGGER_BUDGET_MS. Con los 7 grupos de la mediana real devuelve el paso
     * nominal completo; con los 20 del peor caso observado lo comprime hasta agotar justo el
     * presupuesto. Ambos escalan con SPEED, así que la relación entre ellos no cambia.
     *
     * @param {string} mode       Modo ya resuelto.
     * @param {number} groupCount Número de tandas a programar.
     * @return {number} Milisegundos entre tandas. 0 si el modo no escalona.
     */
    function staggerStep(mode, groupCount) {
        var step = (MODES[mode] || MODES[DEFAULT_MODE]).stagger;
        if (!step || groupCount <= 1) {
            return 0;
        }
        return Math.min(step, Math.floor(STAGGER_BUDGET_MS / groupCount));
    }

    /**
     * Programa trabajo diferido registrando el temporizador de forma SÍNCRONA, para que
     * revertDOMChanges() pueda cancelarlo aunque el revert ocurra antes de que dispare.
     *
     * El snapshot y la mutación viven DENTRO del callback a propósito: si se cancela antes de
     * disparar, no hubo mutación y por tanto no hay nada que restaurar; si ya disparó, el
     * snapshot existe en styledElements y el revert lo deshace por la vía normal. Los dos casos
     * quedan consistentes sin ninguna lógica de compensación.
     *
     * @param {Function} fn    Trabajo a ejecutar.
     * @param {number}   delay Milisegundos de espera. 0 ejecuta de forma síncrona, sin timer.
     */
    function schedule(fn, delay) {
        if (!delay) {
            fn();
            return;
        }
        pendingTimers.push(setTimeout(fn, delay));
    }

    /**
     * @param {Element} element   Elemento DOM a transicionar.
     * @param {string} changeType 'hide' | 'show' | 'set_style'.
     * @param {Object} opts       {property, value} — solo se usa con 'set_style'.
     * @param {string} mode       Modo de transición ya resuelto por resolveMode(). Si se omite
     *                            o no se reconoce, se usa DEFAULT_MODE.
     */
    function applyTransition(element, changeType, opts, mode) {
        opts = opts || {};
        if (!Object.prototype.hasOwnProperty.call(MODES, mode)) {
            mode = DEFAULT_MODE;
        }
        var ms = durationMs(mode);

        switch (changeType) {
            case 'hide':
                (function(elem) {
                    // El push es SÍNCRONO a propósito (antes vivía dentro del setTimeout, es
                    // decir 400ms tarde): revertDOMChanges() corre justo antes de
                    // applyDOMChanges() en panels.js, así que era muy alcanzable revertir
                    // dentro de esa ventana — el elemento no estaba todavía en el registro, el
                    // revert no lo restauraba, y el timeout pendiente lo ocultaba DESPUÉS,
                    // dejándolo invisible sin ninguna adaptación activa. Se guarda además el id
                    // del timer para poder cancelarlo desde el revert.
                    // Los estilos van al registro unificado; hiddenElements guarda solo lo
                    // propio de 'hide': el timer a cancelar y el hecho de que hay algo oculto
                    // (esto último lo consultan panels.js y poll.js).
                    snapshotStyle(elem, 'display');
                    snapshotStyle(elem, 'opacity');
                    snapshotStyle(elem, 'transition');
                    var item = {el: elem, timer: null};
                    if (ms === 0) {
                        // Modo 'inmediata': sin fundido ni timer que pueda quedar pendiente.
                        // Se registra igual en hiddenElements porque panels.js y poll.js lo
                        // consultan como la señal "hay algo oculto", no solo para cancelar.
                        elem.style.display = 'none';
                    } else {
                        elem.style.transition = 'opacity ' + ms + 'ms ease';
                        elem.style.opacity = '0';
                        // El retardo sale de la MISMA constante que la duración del fundido.
                        // Mientras fueron dos literales independientes (0.4s y 400), cualquier
                        // cambio en uno sin el otro dejaba el elemento oculto antes de terminar
                        // de desvanecerse, o visible pero ya sin opacidad.
                        item.timer = setTimeout(function() {
                            elem.style.display = 'none';
                        }, ms);
                    }
                    hiddenElements.push(item);
                }(element));
                break;
            case 'show':
                snapshotStyle(element, 'display');
                snapshotStyle(element, 'opacity');
                element.style.display = '';
                element.style.opacity = '1';
                break;
            case 'set_style':
                snapshotStyle(element, opts.property);
                snapshotStyle(element, 'transition');
                // El nombre va en kebab-case: 'transition' es un VALOR CSS y espera nombres de
                // propiedad CSS reales. Antes se concatenaba la clave camelCase del DOM tal cual
                // ("transition: backgroundImage 0.4s ease"), que parsea sin error —
                // transition-property acepta cualquier <custom-ident>— pero nombra una propiedad
                // inexistente, así que la transición NUNCA se aplicaba. Fallo silencioso: no
                // había forma de notarlo salvo midiendo.
                if (ms > 0) {
                    element.style.transition = cssPropertyName(opts.property) + ' ' + ms + 'ms ease';
                }
                element.style[opts.property] = opts.value;
                break;
            default:
                break;
        }
    }

    /** @return {Array<Object>} Elementos ocultados por 'hide', con el id de su timer pendiente. */
    function getHiddenElements() {
        return hiddenElements;
    }

    /** Limpia el registro de elementos ocultos (llamado desde revertDOMChanges). */
    function resetHiddenElements() {
        hiddenElements = [];
    }

    /**
     * @return {Array<Object>} Estilos inline sobrescritos, en orden de aplicación. El revert
     *   debe recorrerlos en orden INVERSO: si dos acciones tocaron la misma propiedad del mismo
     *   elemento, el primer snapshot es el que tiene el valor original verdadero, así que tiene
     *   que ser el último en aplicarse.
     */
    function getStyledElements() {
        return styledElements;
    }

    /** Limpia el registro de estilos inline (llamado desde revertDOMChanges). */
    function resetStyledElements() {
        styledElements = [];
    }

    /**
     * @return {Array<number>} Ids de los setTimeout de tandas escalonadas aún sin disparar.
     *   revertDOMChanges() debe cancelarlos ANTES de restaurar styledElements, por la misma
     *   razón que ya cancela los de 'hide': si disparan después de la restauración, aplican
     *   una mutación sobre una página que ya no tiene ninguna adaptación activa.
     */
    function getPendingTimers() {
        return pendingTimers;
    }

    /** Limpia el registro de temporizadores diferidos (llamado desde revertDOMChanges). */
    function resetPendingTimers() {
        pendingTimers = [];
    }

    /**
     * Parámetros de temporización, de SOLO LECTURA. Existe para que el arnés de verificación
     * (tests/manual/verify_transition_roundtrip.js) calcule sus expectativas y sus esperas a
     * partir de los valores reales del módulo, en vez de repetir milisegundos a mano.
     *
     * Sin esto, cada recalibración de SPEED rompía dos aserciones y obligaba a reajustar siete
     * `sleep()` — es decir, el arnés castigaba justo la operación que más se va a repetir. Con
     * este accesor las comprobaciones pasan a ser proporciones y quedan invariantes a SPEED.
     *
     * Se devuelve una copia superficial de MODES para que nadie pueda mutar la tabla desde
     * fuera: es la fuente única de los milisegundos de toda la fase Execute.
     *
     * @return {Object} {speed, budget, modes}.
     */
    function timing() {
        var modes = {};
        Object.keys(MODES).forEach(function(k) {
            modes[k] = {duration: MODES[k].duration, stagger: MODES[k].stagger};
        });
        return {speed: SPEED, budget: STAGGER_BUDGET_MS, modes: modes};
    }

    return {
        applyTransition: applyTransition,
        resolveMode: resolveMode,
        durationMs: durationMs,
        staggerStep: staggerStep,
        schedule: schedule,
        getHiddenElements: getHiddenElements,
        resetHiddenElements: resetHiddenElements,
        getStyledElements: getStyledElements,
        resetStyledElements: resetStyledElements,
        getPendingTimers: getPendingTimers,
        resetPendingTimers: resetPendingTimers,
        timing: timing,
    };
});
