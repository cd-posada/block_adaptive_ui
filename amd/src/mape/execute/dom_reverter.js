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
 * AMD module: mape/execute/dom_reverter
 *
 * Revierte todos los cambios DOM aplicados y limpia el estado (extraído de
 * adaptive_monitor.js en la Fase B.3, 2026-07-22). Sin estado propio — lee y resetea el de
 * los módulos que sí lo poseen, vía sus getters/reset exportados:
 *   - transition_manager: hiddenElements, styledElements
 *   - dom_applier:        appliedClasses, removedClasses, appliedCssVars
 *   - explainer:          explainerBadges
 *
 * Los registros styledElements/removedClasses/appliedCssVars se añadieron el 2026-08-08 al
 * corregir el bug de "Revertir cambios deja colores de fondo": set_style era la acción más
 * usada del motor y la única sin ninguna forma de reversión, así que el degradado naranja que
 * Aburrido escribía inline sobre body sobrevivía a la reversión. El principio que rige este
 * módulo es que toda mutación que applyDOMChanges()/applyTransition() realicen debe quedar
 * registrada en el momento de hacerla — si se añade una acción nueva al motor, hay que darle
 * su registro aquí o volverá a ser irreversible.
 *
 * @module block_adaptive_ui/mape/execute/dom_reverter
 */
define([
    'core/log',
    'block_adaptive_ui/mape/config',
    'block_adaptive_ui/mape/execute/transition_manager',
    'block_adaptive_ui/mape/execute/dom_applier',
    'block_adaptive_ui/mape/execute/explainer',
], function(Log, Config, TransitionManager, DomApplier, Explainer) {
    'use strict';

    /** Revierte todos los cambios DOM aplicados y limpia el estado. */
    function revertDOMChanges() {
        // Limpiar clases de truncado antes de revertir — el listener es delegado (ver
        // attachTruncateDelegate(), en mape/execute/panels.js), permanente y único para toda
        // la sesión, así que aquí solo hace falta limpiar el estado visual, no rastrear/quitar
        // handlers por elemento.
        document.querySelectorAll('.adaptive-truncate').forEach(function(el) {
            el.classList.remove('expanded', 'adaptive-truncate');
        });

        document.querySelectorAll('.adaptive-motivational-panel').forEach(function(el) {
            el.remove();
        });

        // Adaptation Explainer por elemento — los badges/tooltips desaparecen junto con el
        // resto de la adaptación, no quedan huérfanos señalando cambios ya revertidos.
        Explainer.getExplainerBadges().forEach(function(el) {
            el.remove();
        });
        Explainer.resetExplainerBadges();

        // Cancelar las tandas del modo "escalonada" que aún no han disparado. Es la MISMA
        // lección que la carrera de los 400ms de 'hide' justo abajo, pero con una ventana
        // mucho más ancha: el escalonado reparte las mutaciones a lo largo de más de un
        // segundo, y panels.js ejecuta revert y apply consecutivos y sin espera, así que
        // revertir a mitad de secuencia es perfectamente alcanzable. Una tanda cancelada nunca
        // llegó a mutar nada ni a fotografiar nada, así que no deja ningún estado que
        // restaurar — cancelar es suficiente.
        TransitionManager.getPendingTimers().forEach(function(timer) {
            clearTimeout(timer);
        });
        TransitionManager.resetPendingTimers();

        // Cancelar los setTimeout de 'hide' que aún no han vencido — si no, se dispararían
        // DESPUÉS de esta restauración y dejarían el elemento oculto sin ninguna adaptación
        // activa (ver el comentario del caso 'hide' en transition_manager.js).
        TransitionManager.getHiddenElements().forEach(function(item) {
            if (item.timer) {
                clearTimeout(item.timer);
            }
        });
        TransitionManager.resetHiddenElements();

        // Estilos inline (hide, show y set_style) — UNA sola pasada, EN ORDEN INVERSO: si
        // varias acciones tocaron la misma propiedad del mismo elemento, el primer snapshot es
        // el que guarda el valor original verdadero, así que debe ser el último en aplicarse.
        // Asignar '' elimina la declaración inline y devuelve el elemento a lo que diga la hoja
        // de estilos, que es justo el estado original.
        TransitionManager.getStyledElements().slice().reverse().forEach(function(item) {
            item.el.style[item.property] = item.prevValue;
        });
        TransitionManager.resetStyledElements();

        // Clases añadidas vía add_class (adaptive-card-layout, adaptive-aburrido-icons,
        // adaptive-engagement-glow, adaptive-engaged-pulse[-soft], adaptive-hope-indicator,
        // etc.) — sin esto quedaban pegadas para siempre y contaminaban visualmente cada
        // adaptación futura de cualquier otra emoción (ver nota en applyDOMChanges()).
        DomApplier.getAppliedClasses().forEach(function(item) {
            item.el.classList.remove(item.value);
        });
        DomApplier.resetAppliedClasses();

        // Clases que la adaptación QUITÓ vía remove_class — se devuelven para que la página
        // recupere su estado original, no solo se limpie lo añadido.
        DomApplier.getRemovedClasses().forEach(function(item) {
            item.el.classList.add(item.value);
        });
        DomApplier.resetRemovedClasses();

        document.body.removeAttribute('data-emotion');
        document.body.removeAttribute('data-norman');

        // CSS custom properties: se ELIMINAN del style inline de <html>, no se reescriben con
        // los valores de DEFAULT_CSS. Reescribirlas dejaba las 9 variables pegadas en <html>
        // aunque la página original no tuviera ninguna — residuo real, y además hacía que el
        // plugin ganara permanentemente sobre cualquier override del tema. Al quitarlas, el
        // bloque :root de styles.css vuelve a proveer los valores por defecto, que es
        // exactamente el estado original. (DEFAULT_CSS se sigue usando en applyDOMChanges()
        // como reset previo entre adaptaciones, ahí sí tiene sentido escribirlas.)
        var root = document.documentElement;
        DomApplier.getAppliedCssVars().forEach(function(prop) {
            root.style.removeProperty(prop);
        });
        DomApplier.resetAppliedCssVars();

        Object.keys(Config.DEFAULT_CSS).forEach(function(prop) {
            root.style.removeProperty(prop);
        });
        root.style.removeProperty('--emotion-accent-warm');

        Log.debug('adaptive_monitor: DOM changes reverted');
    }

    return {
        revertDOMChanges: revertDOMChanges,
    };
});
