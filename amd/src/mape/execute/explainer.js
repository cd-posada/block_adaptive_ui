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
 * AMD module: mape/execute/explainer
 *
 * Adaptation Explainer por elemento (extraído de adaptive_monitor.js en la Fase B.3,
 * 2026-07-22) — añade un indicador discreto "ⓘ" junto a un elemento recién modificado, con el
 * "rationale" que el LLM dio para ESA acción puntual (ver adaptation_service/plan_engine.py,
 * campo opcional "rationale" por dom_action). NO reemplaza al Explainer general de sesión
 * (showFeedbackBox, en mape/execute/panels.js) — son complementarios: este explica el cambio
 * puntual, ese explica la sesión completa.
 *
 * explainerBadges es estado de módulo: lo escribe attachExplainerIndicator() y lo lee/resetea
 * mape/execute/dom_reverter.js (revertDOMChanges) — de ahí los getters/reset exportados.
 *
 * @module block_adaptive_ui/mape/execute/explainer
 */
define(['block_adaptive_ui/mape/config'], function(Config) {
    'use strict';

    // Adaptation Explainer (por elemento) — badges/tooltips creados, para poder quitarlos al
    // revertir junto con el resto de la adaptación (ver revertDOMChanges()).
    var explainerBadges = [];

    /**
     * No se agrega indicador para 'hide' (el elemento desaparece, no hay nada que señalar) ni
     * para selectores globales como "body" (no hay un punto de anclaje visual sensato — esos
     * cambios de página completa ya los explica el cuadro de retroalimentación general).
     *
     * @param {Element} element Elemento ya modificado.
     * @param {Object} act      dom_action original (incluye selector/action/rationale).
     */
    function attachExplainerIndicator(element, act) {
        if (!element || act.action === 'hide' || act.selector === 'body') {
            return;
        }

        var rationale = act.rationale || Config.GENERIC_RATIONALE_FALLBACK;

        var badge = document.createElement('span');
        badge.className = 'adaptive-explainer-badge';
        badge.setAttribute('title', rationale); // tooltip nativo — no bloqueante
        badge.setAttribute('aria-label', 'Por qué cambió esto: ' + rationale);
        badge.setAttribute('tabindex', '0');
        badge.textContent = 'ⓘ';
        badge.style.cssText = [
            'display:inline-block', 'margin-left:4px', 'font-size:12px', 'line-height:1',
            'color:#607d8b', 'cursor:help', 'user-select:none', 'vertical-align:super',
        ].join(';');

        // Click/tap (además del hover nativo, para cubrir móvil): un popover de texto simple,
        // sin librerías — toggle, discreto, nunca modal.
        badge.addEventListener('click', function(evt) {
            evt.stopPropagation();
            var next = badge.nextElementSibling;
            if (next && next.classList.contains('adaptive-explainer-tip')) {
                next.remove();
                return;
            }
            var tip = document.createElement('span');
            tip.className = 'adaptive-explainer-tip';
            tip.textContent = rationale;
            tip.style.cssText = [
                'display:inline-block', 'margin-left:4px', 'padding:2px 6px',
                'font-size:11px', 'color:#455a64', 'background:#eceff1',
                'border-radius:4px',
            ].join(';');
            badge.insertAdjacentElement('afterend', tip);
            explainerBadges.push(tip);
        });

        element.insertAdjacentElement('afterend', badge);
        explainerBadges.push(badge);
    }

    /** @return {Array<Element>} Badges/tooltips actualmente en el DOM. */
    function getExplainerBadges() {
        return explainerBadges;
    }

    /** Limpia el registro de badges/tooltips (llamado desde revertDOMChanges). */
    function resetExplainerBadges() {
        explainerBadges = [];
    }

    return {
        attachExplainerIndicator: attachExplainerIndicator,
        getExplainerBadges: getExplainerBadges,
        resetExplainerBadges: resetExplainerBadges,
    };
});
