<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @copyright  2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
//             Politécnico Colombiano Jaime Isaza Cadavid
//             Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
// @author     Miguel Ángel Salazar Rincón

namespace block_adaptive_ui\mape\plan\prompts;

defined('MOODLE_INTERNAL') || die();

/**
 * Fase D (migración a PHP, 2026-08-01) — puerto literal de
 * adaptation_service/prompts/confundido.py. Ver base.php para el contrato que debe cumplir
 * este archivo.
 *
 * Decisión de diseño (heredada del original, C.5 2026-07-28): Tabla 4 describe para
 * Confundido tanto colores fríos como un tratamiento de layout más cálido — tensión real
 * entre columnas, resuelta hacia COLORES FRÍOS (baja estimulación visual es el objetivo
 * principal); la calidez del layout se logra por otros medios (adaptive-help-expanded) que
 * no dependen del canal de color.
 */
class confundido {

    public const EMOTION_BLOCK = <<<'EOT'
## GUÍA DE MECANISMOS — CONFUNDIDO (CONFUSION)
Esta guía explica CÓMO implementar las reglas de la emoción con los mecanismos que el sistema ejecuta; QUÉ aplicar (colores, fuentes, layout, magnitud) lo deciden las REGLAS DE ADAPTACIÓN v6, los REQUISITOS TRANSVERSALES DE ACCESIBILIDAD y la TAREA, que prevalecen si algo aquí pareciera contradecirlos.
- COLOR: "--emotion-bg", "--emotion-primary" y "--emotion-accent-warm" fijan fondo, acento y acento cálido; gradientes solo con "set_style" "backgroundImage" = "linear-gradient(...)" sobre "body", verificando el contraste en su punto menos favorable.
- TIPOGRAFÍA: "--emotion-text-size", "--emotion-line-height" y "--emotion-letter-spacing" para tamaño, interlineado y espaciado; familia con "--emotion-font-family" + "set_style" "fontFamily" = "var(--emotion-font-family)" sobre "heading"/"paragraph" ("Inter, sans-serif" es la única del MENÚ DE FUENTES entre las que citan las reglas); encabezados más grandes y en negrita con "set_style" "fontSize"/"fontWeight" sobre "heading".
- LAYOUT: un solo foco principal = "add_class": "adaptive-focused-content" sobre "body"; layout limpio de columna = "adaptive-zen-layout"; ayuda visible = "adaptive-help-expanded" sobre "body"; imágenes decorativas = "hide" sobre "image_decorative" o "adaptive-minimal-icons". Texto denso: si el "word_count" real de un "paragraph"/"list_item" supera {truncate_threshold}, "add_class": "adaptive-truncate" sobre ESE elemento. Glosario = "suggested_resource": "glossary" (+ "show_resources"). Notificaciones proactivas, mapas del curso, ejemplos resueltos e insignias no tienen mecanismo: van a "reglas_no_aplicadas".
- Ritmo de la transición (ver RITMO DE LA TRANSICIÓN): usa "transition": "escalonada" — el problema es de comprensión; que los cambios entren por tandas permite ver QUÉ se movió y DÓNDE.
EOT;

    // Antes gateado por "Fila 2" — eliminado ese condicionamiento en C.5: el tono de
    // superación aplica SIEMPRE para Confundido.
    public const EMPATHIC_EXTRA = <<<'EOT'
- Para esta emoción, enmarca el mensaje como un breve mensaje de superación — transmite, sin sonar condescendiente, que este tipo de dificultad es normal y superable, y que los cambios aplicados están ahí para ayudar justo con eso. Tono suave, siempre, sin excepción.
EOT;

    public const HARD_RULE_EXTRA = '';
}
