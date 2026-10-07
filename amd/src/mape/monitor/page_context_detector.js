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
 * AMD module: mape/monitor/page_context_detector
 *
 * Detección de contexto de página y plataforma (extraído de adaptive_monitor.js en la Fase
 * B.3, 2026-07-22).
 *
 * lastScannedElements/lastDiscardStats son estado de módulo: se escriben DENTRO de
 * detectPageContext() (no en content_scanner.js — scanContentElements() solo devuelve el
 * inventario + las estadísticas de descarte, quien las guarda como último escaneo es esta
 * función), y los lee el shell delgado (adaptiveDebug(), vía getLastScannedElements()/
 * getLastDiscardStats()).
 *
 * @module block_adaptive_ui/mape/monitor/page_context_detector
 */
define(['block_adaptive_ui/mape/monitor/content_scanner'], function(ContentScanner) {
    'use strict';

    // Estado para window.__adaptiveDebug() — el último inventario dinámico detectado, para
    // poder verificar en vivo en DevTools qué se detectó en cualquier tipo de contenido.
    var lastScannedElements = [];

    // Estado para window.__adaptiveDebug() (C.2) — cuántos elementos candidatos se podaron por
    // el tope global (MAX_DYNAMIC_TOTAL en content_scanner.js) en el último escaneo, y de qué
    // rol. Antes de C.2 esta pérdida era invisible.
    var lastDiscardStats = {total: 0, byRole: {}};

    // Selectores de los iframes concretos ya confirmados (investigación DOM previa a C.1/C.2,
    // leyendo cada renderer, no por intuición) para los módulos cuyo contenido real vive
    // SIEMPRE o CONDICIONALMENTE dentro de un iframe: h5pactivity (.h5p-player, h5p/templates/
    // h5pembed.mustache), lti (#contentframe, mod/lti/view.php), url/resource en modo embed
    // (#resourceobject, lib/resourcelib.php — mismo id para ambos módulos), scorm una vez
    // cargado un SCO (#scorm_object, inyectado por JS en mod/scorm/module.js — no existe en el
    // HTML servido por PHP, así que este chequeo solo detecta scorm DESPUÉS de que el
    // estudiante entra a un SCO, nunca en la página de tabla de contenidos previa, que sí es
    // escaneable). Deliberadamente NO es "cualquier iframe en la página" — eso daría falsos
    // positivos con video embebido dentro de una page/label normal, que sigue siendo
    // mayormente escaneable.
    var OPAQUE_IFRAME_SELECTORS = '#resourceobject, #contentframe, .h5p-player, #scorm_object';

    /**
     * Platform Model del "Context Model" (User Model / Platform Model / Environment Model del
     * diagrama de arquitectura) — datos de dispositivo/navegador que el cliente YA expone sin
     * permisos adicionales ni servicios externos. Deliberadamente NO incluye nada de
     * iluminación/brillo del frame de video: ese dato viviría en el Environment Model, propio
     * de emotions_backend, que está fuera de alcance de este cambio (no se toca ese servicio).
     * El Environment Model queda como trabajo futuro, no implementado aquí.
     *
     * Todo es opcional/best-effort: si el navegador no soporta alguna API, el campo queda
     * null/'unknown' en vez de romper la recolección de contexto.
     *
     * @return {Object} {device_type, viewport, connection_type}.
     */
    function detectPlatformContext() {
        var deviceType = 'unknown';
        // navigator.userAgentData (Client Hints) es más confiable que parsear el UA a mano,
        // pero solo existe en navegadores basados en Chromium — con fallback a userAgent.
        var uaData = navigator.userAgentData;
        if (uaData && typeof uaData.mobile === 'boolean') {
            deviceType = uaData.mobile ? 'mobile' : 'desktop';
        } else {
            var ua = navigator.userAgent || '';
            if (/iPad|Tablet/i.test(ua)) {
                deviceType = 'tablet';
            } else if (/Mobi|Android/i.test(ua)) {
                deviceType = 'mobile';
            } else {
                deviceType = 'desktop';
            }
        }

        // Network Information API — solo Chromium; Firefox/Safari no la implementan. Se omite
        // (queda null) sin romper nada si no existe.
        var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        var connectionType = (connection && connection.effectiveType) ? connection.effectiveType : null;

        return {
            // eslint-disable-next-line camelcase
            device_type: deviceType,
            viewport: {
                width:  window.innerWidth  || document.documentElement.clientWidth  || 0,
                height: window.innerHeight || document.documentElement.clientHeight || 0,
            },
            // eslint-disable-next-line camelcase
            connection_type: connectionType,
        };
    }

    /** @return {Object} Contexto de la página actual para el motor de adaptación (Plan). */
    function detectPageContext() {
        var body = document.body;
        var bodyClasses = Array.from(body.classList);
        var url = window.location.pathname;

        var activityMap = {
            'path-mod-quiz':    'quiz',
            'path-mod-page':    'page',
            'path-mod-book':    'book',
            'path-mod-forum':   'forum',
            'path-mod-assign':  'assignment',
            'path-mod-lesson':  'lesson',
            'path-mod-scorm':   'scorm',
            'path-course-view': 'course_view',
        };

        var activityType = 'unknown';
        Object.keys(activityMap).forEach(function(cls) {
            if (bodyClasses.indexOf(cls) !== -1 && activityType === 'unknown') {
                activityType = activityMap[cls];
            }
        });

        var q = function(sel) {
            return !!document.querySelector(sel);
        };

        // Escaneo dinámico del contenido real (reemplaza CONTENT_WRAPPER_MAP — ver
        // scanContentElements() en mape/monitor/content_scanner.js). content_density se agrega
        // sobre lo encontrado en vez de sobre un wrapper fijo por activity_type.
        var scanResult = ContentScanner.scanContentElements();
        var elements = scanResult.elements;
        lastScannedElements = elements; // ver window.__adaptiveDebug()
        lastDiscardStats = scanResult.discarded; // ver window.__adaptiveDebug()
        var wordCount  = 0;
        var imageCount = 0;
        elements.forEach(function(el) {
            if (typeof el.word_count === 'number') {
                wordCount += el.word_count;
            }
            if (el.role === 'image_content' || el.role === 'image_decorative') {
                imageCount++;
            }
        });

        return {
            // eslint-disable-next-line camelcase
            activity_type:   activityType,
            // eslint-disable-next-line camelcase
            url_path:        url,
            // eslint-disable-next-line camelcase
            body_classes: bodyClasses.filter(function(c) {
                return c.indexOf('path-') === 0;
            }),
            // Módulo opaco (C.2, Paso 2) — el contenido real de esta actividad vive dentro de
            // un iframe (h5pactivity/lti/scorm-con-SCO-cargado/url-o-resource-en-modo-embed, ver
            // OPAQUE_IFRAME_SELECTORS arriba), así que el escaneo de contenido de arriba no
            // puede haber encontrado nada de valor ahí dentro — el LLM necesita saber esto para
            // no asumir que puede targetear contenido específico de la actividad, solo
            // colores/tipografía globales del documento padre.
            // eslint-disable-next-line camelcase
            is_opaque_activity: q(OPAQUE_IFRAME_SELECTORS),
            /* eslint-disable camelcase */
            selectors: {
                has_sidebar_pre:     q('.drawer'),
                has_sidebar_post:    q('[data-region="blocks-column"]'),
                has_quiz_timer:      q('#quiz-timer, .quiz-timer, [data-region="quiz-timer"]'),
                has_quiz_nav:        q('.mod_quiz-nav-block, [data-region="quiz-navigation"], .qn_buttons'),
                has_question_flag:   q('.questionflag'),
                has_question_form:   q('.que'),
                has_formulation:     q('.formulation'),
                has_answer:          q('.answer'),
                has_mod_content:     q('.mod-content'),
                has_book_content:    q('.book_content'),
                has_lesson_content:  q('.lessonpage'),
                has_forum_posts:     q('.forum-post-container'),
                has_course_sections: q('.course-content .section'),
                has_activity_list:   q('.activity-list'),
                has_grade_display:   q('.grade, .gradingform'),
                has_feedback:        q('.feedback'),
                has_quiz_progress:   q('.mod_quiz-progress'),
                has_quiz_submitbtns: q('.mod_quiz-submitbtns'),
                has_quiz_outcome:    q('.outcome, .comment'),
                has_btn_primary:     q('.btn-primary, .btn.btn-primary'),
                has_course_activities: q('.activity-item, .activity'),
                has_book_navigation: q('#mod_book-chaptersnavigation, .book-navigation'),
                has_general_box:     q('.generalbox'),
            },
            /* eslint-enable camelcase */
            /* eslint-disable camelcase */
            content_density: {
                question_count:    document.querySelectorAll('.que').length,
                section_count:     document.querySelectorAll('.course-content .section').length,
                image_count:       imageCount,
                word_count_approx: wordCount,
            },
            /* eslint-enable camelcase */
            // Inventario dinámico de contenido — ver scanContentElements() en
            // mape/monitor/content_scanner.js. Reemplaza content_wrapper (eliminado): en vez
            // de una clave fija por activity_type, esto es lo que el JS encontró REALMENTE en
            // #region-main en este momento. Sin texto real (gobernanza de datos) — solo
            // selector/rol/word_count.
            elements: elements,
            // Rescate best-effort de URLs reales de recursos del curso (para
            // "suggested_resource" del motor de adaptación — ver adaptation_service/
            // sanitizer.py). Solo están presentes si el enlace correspondiente ya es
            // visible en el DOM de esta página (ej. en el drawer de navegación); no hay
            // API de Moodle consultada aquí. Si no se encuentran, quedan null y el motor
            // no debe sugerir ese recurso (evita ofrecer un enlace que no existe).
            // eslint-disable-next-line camelcase
            resource_urls: {
                forum: (function() {
                    var el = document.querySelector('a[href*="/mod/forum/view.php"]');
                    return el ? el.href : null;
                }()),
                glossary: (function() {
                    var el = document.querySelector('a[href*="/mod/glossary/view.php"]');
                    return el ? el.href : null;
                }()),
            },
            // Context Model — Platform Model (ver detectPlatformContext()). Environment Model
            // (iluminación/cámara) queda fuera de alcance a propósito — ver comentario ahí.
            // eslint-disable-next-line camelcase
            platform_model: detectPlatformContext(),
        };
    }

    /** @return {Array<Object>} Inventario del último escaneo de contenido realizado. */
    function getLastScannedElements() {
        return lastScannedElements;
    }

    /**
     * @return {Object} {total: number, byRole: Object<string, number>} — cuántos elementos
     * candidatos se podaron por el tope global en el último escaneo, y de qué rol (C.2).
     */
    function getLastDiscardStats() {
        return lastDiscardStats;
    }

    return {
        detectPlatformContext: detectPlatformContext,
        detectPageContext: detectPageContext,
        getLastScannedElements: getLastScannedElements,
        getLastDiscardStats: getLastDiscardStats,
    };
});
