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
 * AMD module: mape/contrast
 *
 * Espejo en JavaScript de classes/mape/plan/color_contrast.php (2026-08-08). Misma aritmética
 * WCAG 2.1, mismo umbral AA (4.5:1) y mismas garantías.
 *
 * POR QUÉ HACE FALTA UNA COPIA EN JS: los paneles (recomendación y retroalimentación) se pintan
 * en el navegador con los colores estáticos de Config.PANEL_COLORS, NO con las css_vars que
 * deriva el sanitizador en PHP. El panel de recomendación además aparece ANTES de llamar al LLM,
 * así que en ese momento no existe ningún --emotion-primary que reutilizar. Sin esta copia, los
 * botones seguirían pintando texto blanco sobre el pastel de Frustrado (#90CAF9 → 1.75:1).
 *
 * Se mantiene deliberadamente mínima: solo lo que los paneles necesitan. Si se amplía, hay que
 * mantenerla en paridad con la versión PHP — son la misma regla expresada dos veces porque se
 * evalúan en dos momentos distintos del ciclo.
 *
 * @module block_adaptive_ui/mape/contrast
 */
define([], function() {
    'use strict';

    /** Umbral WCAG 2.1 nivel AA para texto de tamaño normal. */
    var AA_NORMAL_TEXT = 4.5;

    /** Texto oscuro preferido. */
    var DARK_TEXT = '#1a1a1a';

    /** Texto claro. */
    var LIGHT_TEXT = '#ffffff';

    /**
     * Negro puro, de reserva. Con fondos de tono medio puede ocurrir que ni DARK_TEXT ni el
     * blanco alcancen 4.5:1; el negro cierra ese hueco (el peor fondo posible rinde 4.58:1),
     * así que bestTextOn() nunca devuelve un color ilegible. Ver la versión PHP para el detalle.
     */
    var DARK_TEXT_MAX = '#000000';

    /** Paso de luminosidad HSL al oscurecer. */
    var LIGHTNESS_STEP = 0.02;

    /**
     * @param {string} hex Color #RRGGBB.
     * @return {Array<number>} Canales R, G, B en [0, 255].
     */
    function toRgb(hex) {
        var clean = String(hex).trim().replace(/^#/, '');
        return [
            parseInt(clean.substring(0, 2), 16),
            parseInt(clean.substring(2, 4), 16),
            parseInt(clean.substring(4, 6), 16),
        ];
    }

    /**
     * Luminancia relativa WCAG 2.1, con linealización sRGB.
     *
     * @param {string} hex Color #RRGGBB.
     * @return {number} Luminancia en [0, 1].
     */
    function relativeLuminance(hex) {
        var c = toRgb(hex).map(function(value) {
            var v = value / 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    }

    /**
     * Razón de contraste WCAG entre dos colores. Simétrica.
     *
     * @param {string} hexA Primer color.
     * @param {string} hexB Segundo color.
     * @return {number} Razón en [1, 21].
     */
    function ratio(hexA, hexB) {
        var l1 = relativeLuminance(hexA);
        var l2 = relativeLuminance(hexB);
        if (l1 < l2) {
            var tmp = l1;
            l1 = l2;
            l2 = tmp;
        }
        return (l1 + 0.05) / (l2 + 0.05);
    }

    /**
     * @param {string} hex Color #RRGGBB.
     * @return {Array<number>} Tono [0,360), saturación [0,1], luminosidad [0,1].
     */
    function toHsl(hex) {
        var rgb = toRgb(hex).map(function(c) {
            return c / 255;
        });
        var r = rgb[0], g = rgb[1], b = rgb[2];
        var max = Math.max(r, g, b);
        var min = Math.min(r, g, b);
        var l = (max + min) / 2;
        var delta = max - min;

        if (delta === 0) {
            return [0, 0, l];
        }
        var s = l > 0.5 ? delta / (2 - max - min) : delta / (max + min);
        var h;
        if (max === r) {
            h = 60 * (((g - b) / delta) % 6);
        } else if (max === g) {
            h = 60 * (((b - r) / delta) + 2);
        } else {
            h = 60 * (((r - g) / delta) + 4);
        }
        if (h < 0) {
            h += 360;
        }
        return [h, s, l];
    }

    /**
     * @param {number} h Tono [0, 360).
     * @param {number} s Saturación [0, 1].
     * @param {number} l Luminosidad [0, 1].
     * @return {string} Color #rrggbb.
     */
    function fromHsl(h, s, l) {
        var c = (1 - Math.abs(2 * l - 1)) * s;
        var x = c * (1 - Math.abs(((h / 60) % 2) - 1));
        var m = l - c / 2;
        var rgb;

        if (h < 60) {
            rgb = [c, x, 0];
        } else if (h < 120) {
            rgb = [x, c, 0];
        } else if (h < 180) {
            rgb = [0, c, x];
        } else if (h < 240) {
            rgb = [0, x, c];
        } else if (h < 300) {
            rgb = [x, 0, c];
        } else {
            rgb = [c, 0, x];
        }

        return '#' + rgb.map(function(v) {
            var hex = Math.round((v + m) * 255).toString(16);
            return hex.length === 1 ? '0' + hex : hex;
        }).join('');
    }

    /**
     * Elige el color de texto que se lee encima de un fondo dado, garantizando el umbral.
     * Prefiere el gris oscuro suave mientras cumpla; si no, escala al extremo con más contraste.
     *
     * @param {string} bg Fondo #RRGGBB.
     * @param {number} [target] Contraste mínimo. Por defecto AA.
     * @return {string} Color de texto.
     */
    function bestTextOn(bg, target) {
        var t = target || AA_NORMAL_TEXT;
        if (ratio(DARK_TEXT, bg) >= t) {
            return DARK_TEXT;
        }
        if (ratio(LIGHT_TEXT, bg) >= t) {
            return LIGHT_TEXT;
        }
        return ratio(DARK_TEXT_MAX, bg) >= ratio(LIGHT_TEXT, bg) ? DARK_TEXT_MAX : LIGHT_TEXT;
    }

    /**
     * Oscurece un color conservando tono y saturación hasta alcanzar el contraste objetivo.
     * Si ya lo cumple, lo devuelve INTACTO (no se normaliza lo que no hace falta corregir).
     *
     * @param {string} hex Color a oscurecer.
     * @param {string} bg  Fondo contra el que debe leerse.
     * @param {number} [target] Contraste mínimo. Por defecto AA.
     * @return {string} Color resultante.
     */
    function darkenUntilReadable(hex, bg, target) {
        var t = target || AA_NORMAL_TEXT;
        if (ratio(hex, bg) >= t) {
            return hex;
        }
        var hsl = toHsl(hex);
        var candidate = hex;
        for (var l = hsl[2] - LIGHTNESS_STEP; l >= 0; l -= LIGHTNESS_STEP) {
            candidate = fromHsl(hsl[0], hsl[1], Math.max(0, l));
            if (ratio(candidate, bg) >= t) {
                return candidate;
            }
        }
        return candidate;
    }

    return {
        AA_NORMAL_TEXT: AA_NORMAL_TEXT,
        DARK_TEXT: DARK_TEXT,
        LIGHT_TEXT: LIGHT_TEXT,
        relativeLuminance: relativeLuminance,
        ratio: ratio,
        bestTextOn: bestTextOn,
        darkenUntilReadable: darkenUntilReadable,
    };
});
