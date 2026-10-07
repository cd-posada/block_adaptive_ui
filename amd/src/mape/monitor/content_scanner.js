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
 * AMD module: mape/monitor/content_scanner
 *
 * DOM Context Scanner (extraído de adaptive_monitor.js en la Fase B.3, 2026-07-22).
 *
 * Desde C.2 (2026-07-28): 9 roles agrupados en "buckets", cada uno con su propio tope
 * ANTI-MONOPOLIO (evita que un solo rol se coma el pool), y un tope GLOBAL aparte
 * (MAX_DYNAMIC_TOTAL) que es el que realmente coincide con MAX_DYNAMIC_ELEMENTS del lado
 * servidor (adaptation_service/sanitizer.py). La suma de los topes por rol (69) EXCEDE a
 * propósito el tope global (50): se recolecta primero por rol respetando su propio tope, y
 * DESPUÉS se aplica round-robin entre buckets hasta el tope global — el orden de recolección
 * ya no determina qué sobrevive cuando una página es más densa que el pool total.
 *
 * @module block_adaptive_ui/mape/monitor/content_scanner
 */
define([], function() {
    'use strict';

    // Topes por rol (cliente) — son guardas ANTI-MONOPOLIO: evitan que un solo rol se coma el
    // pool completo, NO son el contrato con el servidor. Su suma (25+10+5+10+5+4+4+3+3=69)
    // EXCEDE a propósito el tope global de abajo. El contrato real con MAX_DYNAMIC_ELEMENTS en
    // adaptation_service/sanitizer.py es MAX_DYNAMIC_TOTAL (<=50) — ver roundRobinMerge(), que
    // recorta el conjunto final a ese número después de recolectar hasta el tope de cada rol
    // por separado. Si se cambia MAX_DYNAMIC_TOTAL, cambiar también MAX_DYNAMIC_ELEMENTS en
    // sanitizer.py y este comentario.
    var MAX_DYNAMIC_TEXT       = 25; // heading + paragraph + list_item combinados
    var MAX_DYNAMIC_IMAGES     = 10;
    var MAX_DYNAMIC_BUTTONS    = 5;
    var MAX_DYNAMIC_ACTIVITIES = 10;
    var MAX_DYNAMIC_CODE       = 5;
    var MAX_DYNAMIC_TABLE      = 4;
    var MAX_DYNAMIC_MEDIA      = 4;
    var MAX_DYNAMIC_CALLOUT    = 3;
    var MAX_DYNAMIC_MATH       = 3;
    var MAX_DYNAMIC_TOTAL      = 50; // tope real que ve el servidor — este es el que manda
    var MIN_TEXT_LENGTH        = 15; // caracteres — solo para filtrar, nunca se envía el texto
    var MIN_IMAGE_SIZE         = 24; // px — excluye iconos decorativos pequeños

    // Contenedores que #region-main puede incluir pero que NO son contenido de la actividad:
    // el widget de captura de emociones (local_emotions_send, plugin externo — vive dentro de
    // #region-main, se confirmó que no tiene ninguna exclusión propia hacia este escáner) y el
    // propio bloque block_adaptive_ui (panel/no debería auto-targetearse). El panel de
    // recomendación y el cuadro de feedback (mape/execute/panels.js) ya se insertan sobre
    // document.body, fuera de #region-main por construcción — se incluyen aquí igual como
    // cinturón-y-tirantes, sin costo, por si eso cambia en el futuro. Local a este archivo
    // (único consumidor) — no vive en mape/config.js. Aplica a los 9 buckets (C.1 + C.2).
    var EXCLUDED_ANCESTOR_SELECTORS = [
        '#emotions_widget',
        '[data-block="adaptive_ui"]',
        '#adaptive-recommendation-panel',
        '#adaptive-feedback-box',
    ];

    /**
     * @param {Element} el Elemento a comprobar.
     * @return {boolean} true si el elemento ocupa espacio visible en la página ahora mismo.
     */
    function isVisible(el) {
        return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
    }

    /**
     * @param {Element} el Elemento a comprobar.
     * @return {boolean} true si el elemento vive dentro de alguno de los contenedores excluidos.
     */
    function isExcluded(el) {
        return !!(el.closest && el.closest(EXCLUDED_ANCESTOR_SELECTORS.join(',')));
    }

    /**
     * Recolecta candidatos de un NodeList respetando visibilidad, exclusión y el tope propio
     * del rol — SIN etiquetar todavía en el DOM (el etiquetado real ocurre después del
     * round-robin, en scanContentElements(), para que el orden de recolección no determine qué
     * sobrevive al tope global).
     *
     * @param {NodeList} nodeList Elementos candidatos sin filtrar.
     * @param {number} cap Tope propio de este rol/bucket.
     * @param {Function} classify (el) => {role, extra} | null. Devuelve null para descartar el
     * elemento (ej. texto demasiado corto, imagen decorativa demasiado pequeña).
     * @return {Array<Object>} [{el, role, extra}], en el orden en que aparecen en el documento.
     */
    function collectCandidates(nodeList, cap, classify) {
        var candidates = [];
        nodeList.forEach(function(el) {
            if (candidates.length >= cap || !isVisible(el) || isExcluded(el)) {
                return;
            }
            var result = classify(el);
            if (!result) {
                return;
            }
            candidates.push({el: el, role: result.role, extra: result.extra});
        });
        return candidates;
    }

    /**
     * Combina varios buckets en un único array, tomando `weights[i]` elementos del bucket i en
     * cada ronda (round-robin PONDERADO), hasta agotar todos los buckets o alcanzar totalCap —
     * lo que ocurra primero. Así, si el pool total de candidatos supera totalCap, el recorte se
     * reparte entre roles en vez de que los primeros buckets recorridos se coman todo el
     * presupuesto — pero no en partes iguales: el peso decide cuánto pesa cada rol en el
     * reparto (ver BUCKET_WEIGHTS más abajo para la justificación de por qué texto pesa más).
     *
     * @param {Array<Array<Object>>} buckets Lista de arrays de candidatos, uno por rol.
     * @param {Array<number>} weights Cuántos elementos tomar del bucket i por ronda — paralelo
     * a `buckets`, nunca hardcodeado por índice dentro de la función.
     * @param {number} totalCap Tope global (MAX_DYNAMIC_TOTAL).
     * @return {Object} {survivors: Array<Object>, consumedPerBucket: Array<number>}.
     */
    function roundRobinMerge(buckets, weights, totalCap) {
        var survivors = [];
        var consumedPerBucket = buckets.map(function() {
            return 0;
        });
        var progress = true;
        while (survivors.length < totalCap && progress) {
            progress = false;
            for (var i = 0; i < buckets.length && survivors.length < totalCap; i++) {
                for (var w = 0; w < weights[i] && survivors.length < totalCap; w++) {
                    var idx = consumedPerBucket[i];
                    if (idx >= buckets[i].length) {
                        break; // este bucket ya se agotó, no rellena el resto de su peso
                    }
                    survivors.push(buckets[i][idx]);
                    consumedPerBucket[i] = idx + 1;
                    progress = true;
                }
            }
        }
        return {survivors: survivors, consumedPerBucket: consumedPerBucket};
    }

    /**
     * Escanea el DOM real dentro de #region-main y etiqueta cada elemento sobreviviente (tras
     * el tope por rol + round-robin al tope global) con data-adaptive-id="N" para poder
     * targetearlo luego por selector CSS normal (funciona sin cambios con
     * applyDOMChanges()/applyTransition()/attachExplainerIndicator(), que ya operan de forma
     * agnóstica al selector). Limpia las etiquetas de un escaneo anterior primero — mismo
     * espíritu que hiddenElements/appliedClasses: limpiar antes de reconstruir.
     *
     * @return {Object} {elements: Array<{selector, role, word_count?, level?}>, discarded:
     * {total: number, byRole: Object<string, number>}} — sin texto real en ningún elemento.
     */
    function scanContentElements() {
        document.querySelectorAll('[data-adaptive-id]').forEach(function(el) {
            el.removeAttribute('data-adaptive-id');
        });

        var root = document.getElementById('region-main') || document.body;
        var elements = [];
        var counter = 0;

        /**
         * Etiqueta un elemento con data-adaptive-id y lo agrega al inventario reportado.
         * @param {Element} el Elemento encontrado.
         * @param {string} role Rol semántico (heading, paragraph, list_item, image_content,
         * image_decorative, button, activity, code, table, media, callout, math).
         * @param {Object} [extra] Campos adicionales (word_count, level).
         */
        function tag(el, role, extra) {
            el.setAttribute('data-adaptive-id', String(counter));
            var entry = {selector: '[data-adaptive-id="' + counter + '"]', role: role};
            if (extra) {
                Object.keys(extra).forEach(function(k) {
                    entry[k] = extra[k];
                });
            }
            elements.push(entry);
            counter++;
        }

        // Texto — heading (con nivel)/paragraph/list_item combinados, un solo tope, en el
        // orden en que aparecen en el documento (Tabla 24 distingue el tratamiento de
        // encabezados del cuerpo de texto, por eso el nivel se reporta aparte).
        var headingLevels = {H1: 1, H2: 2, H3: 3, H4: 4};
        var textCandidates = collectCandidates(
            root.querySelectorAll('h1, h2, h3, h4, p, li'), MAX_DYNAMIC_TEXT,
            function(el) {
                var text = (el.textContent || '').trim();
                if (text.length <= MIN_TEXT_LENGTH) {
                    return null;
                }
                var level = headingLevels[el.tagName];
                /* eslint-disable camelcase */
                var extra = {word_count: text.split(/\s+/).length};
                /* eslint-enable camelcase */
                var role;
                if (level) {
                    role = 'heading';
                    extra.level = level;
                } else {
                    role = (el.tagName === 'LI') ? 'list_item' : 'paragraph';
                }
                return {role: role, extra: extra};
            }
        );

        // Imágenes de contenido (excluye iconos pequeños — probablemente decorativos de UI)
        var imageCandidates = collectCandidates(
            root.querySelectorAll('img'), MAX_DYNAMIC_IMAGES,
            function(el) {
                if (el.offsetWidth < MIN_IMAGE_SIZE || el.offsetHeight < MIN_IMAGE_SIZE) {
                    return null;
                }
                var alt = el.getAttribute('alt');
                return {role: (!alt || !alt.trim()) ? 'image_decorative' : 'image_content'};
            }
        );

        // Botones de acción — cada uno con su propio data-adaptive-id (a diferencia del
        // selector fijo ".btn-primary" del nivel estructural, que sigue existiendo y afecta a
        // TODOS los .btn-primary a la vez; esto permite targetear uno específico si el LLM lo
        // prefiere).
        var buttonCandidates = collectCandidates(
            root.querySelectorAll('.btn-primary, button[type="submit"], input[type="submit"]'),
            MAX_DYNAMIC_BUTTONS,
            function() {
                return {role: 'button'};
            }
        );

        // Ítems de actividad — normalmente solo existen en la vista de curso; si no hay
        // ninguno, simplemente no aparecen en el inventario, sin necesidad de gatear por
        // activity_type.
        var activityCandidates = collectCandidates(
            root.querySelectorAll('.activity-item, .activity'), MAX_DYNAMIC_ACTIVITIES,
            function() {
                return {role: 'activity'};
            }
        );

        // Bloques de código (C.2, ajustado tras verificación real en mod/book/view.php?id=9):
        // "code" es solo código de BLOQUE — <pre>/.prettyprint quedan como elemento
        // representativo del bloque; un <code> se descarta si (a) ya está cubierto por un
        // ancestro pre/.prettyprint (evita doble etiquetado del mismo bloque), (b) es inline
        // dentro de texto normal (ancestro p/li/h1-h4 — "return", "throw" sueltos en un
        // párrafo no son targets útiles), o (c) ni siquiera parece un bloque real (sin salto de
        // línea y menos de 20 caracteres). Selectores del core del editor/filtro de resaltado,
        // no del tema ni de mod_book específicamente.
        var codeCandidates = collectCandidates(
            root.querySelectorAll('pre, .prettyprint, code'), MAX_DYNAMIC_CODE,
            function(el) {
                if (el.tagName === 'CODE') {
                    if (el.closest('pre, .prettyprint')) {
                        return null;
                    }
                    if (el.closest('p, li, h1, h2, h3, h4')) {
                        return null;
                    }
                    var text = el.textContent || '';
                    if (text.indexOf('\n') === -1 && text.length < 20) {
                        return null;
                    }
                }
                return {role: 'code'};
            }
        );

        // Tablas (C.2) — generaltable es la clase que usa el core para tablas "reales"
        // (reportes, calificaciones); .table-responsive table cubre tablas insertadas por el
        // editor de texto envueltas en el wrapper responsive de Bootstrap.
        var tableCandidates = collectCandidates(
            root.querySelectorAll('table.generaltable, .table-responsive table'),
            MAX_DYNAMIC_TABLE,
            function() {
                return {role: 'table'};
            }
        );

        // Media embebido (C.2) — SOLO se etiqueta el elemento contenedor (video/audio/iframe)
        // para permitir adaptar colores/tipografía del documento PADRE a su alrededor. Nunca se
        // escanea ni se etiqueta nada dentro de un <iframe>: querySelectorAll del documento
        // padre no puede atravesar el boundary de documento de un iframe de todas formas
        // (confirmado en la investigación DOM previa a C.1), así que esta limitación es
        // estructural, no solo una decisión de diseño — pero se declara aquí explícitamente
        // porque es una limitación reconocida en la tesis, no un descuido.
        var mediaCandidates = collectCandidates(
            root.querySelectorAll('video, audio, iframe'), MAX_DYNAMIC_MEDIA,
            function() {
                return {role: 'media'};
            }
        );

        // Callouts/notificaciones (C.2) — clases reales de core/notification (ver
        // lib/templates/notification_*.mustache), no del tema.
        var calloutCandidates = collectCandidates(
            root.querySelectorAll('.alert, .alert-info, .alert-warning'), MAX_DYNAMIC_CALLOUT,
            function() {
                return {role: 'callout'};
            }
        );

        // Fórmulas matemáticas (C.2) — filter_mathjaxloader y filter_tex, ambos filtros core.
        var mathCandidates = collectCandidates(
            root.querySelectorAll('.filter_mathjaxloader_equation, .MathJax, .texrender'),
            MAX_DYNAMIC_MATH,
            function() {
                return {role: 'math'};
            }
        );

        // Orden de los buckets para el round-robin — junto con BUCKET_WEIGHTS (paralelo, mismo
        // orden), determina cuánto pesa cada rol en el reparto final.
        var buckets = [
            textCandidates, imageCandidates, buttonCandidates, activityCandidates,
            codeCandidates, tableCandidates, mediaCandidates, calloutCandidates, mathCandidates,
        ];

        // Pesos del round-robin (C.2, ajustado tras evidencia real de mod/book/view.php?id=9) —
        // el reparto NO es equitativo a propósito: texto pesa 3, el resto pesa 1. Justificación
        // para la tesis: el texto (heading/paragraph/list_item) es el SUSTRATO real de las
        // adaptaciones de Confusión y Aburrimiento (truncado de párrafos densos, interlineado,
        // tamaño de cuerpo, tipografía por rol — ver Tabla 24, secciones CONFUNDIDO/ABURRIDO en
        // _SYSTEM_PROMPT) — es el rol con más superficie de acción real del sistema. Roles como
        // math o callout saturan su propio tope con solo 3-4 elementos en casi cualquier
        // página real, así que un peso igualitario los sobrerrepresentaría frente a texto en el
        // reparto final, desplazando precisamente el contenido que más usan las reglas de la
        // tesis. Paralelo a `buckets` (mismo orden) — nunca hardcodeado por índice dentro de
        // roundRobinMerge().
        var BUCKET_WEIGHTS = [3, 1, 1, 1, 1, 1, 1, 1, 1];

        var merged = roundRobinMerge(buckets, BUCKET_WEIGHTS, MAX_DYNAMIC_TOTAL);

        merged.survivors.forEach(function(candidate) {
            tag(candidate.el, candidate.role, candidate.extra);
        });

        // Estadística de descarte (C.2) — antes invisible: cuántos candidatos válidos (ya
        // filtrados por visibilidad/exclusión/tope por rol) no entraron al tope global, y de
        // qué rol específico eran. Se calcula sobre el remanente de cada bucket tras el
        // round-robin (candidatos más allá del índice que sí se consumió).
        var totalCandidates = buckets.reduce(function(sum, bucket) {
            return sum + bucket.length;
        }, 0);
        var byRole = {};
        buckets.forEach(function(bucket, i) {
            bucket.slice(merged.consumedPerBucket[i]).forEach(function(candidate) {
                byRole[candidate.role] = (byRole[candidate.role] || 0) + 1;
            });
        });

        return {
            elements: elements,
            discarded: {
                total: totalCandidates - merged.survivors.length,
                byRole: byRole,
            },
        };
    }

    return {
        scanContentElements: scanContentElements,
    };
});
