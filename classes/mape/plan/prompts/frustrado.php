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
 * adaptation_service/prompts/frustrado.py. Ver base.php para el contrato que debe cumplir
 * este archivo y la nota sobre cómo se transcribió el texto (volcado en tiempo de ejecución,
 * no reconstruido a mano).
 */
class frustrado {

    public const EMOTION_BLOCK = <<<'EOT'
## GUÍA DE MECANISMOS — FRUSTRADO (FRUSTRATION)
Esta guía explica CÓMO implementar las reglas de la emoción con los mecanismos que el sistema ejecuta; QUÉ aplicar (colores, fuentes, layout, magnitud) lo deciden las REGLAS DE ADAPTACIÓN v6, los REQUISITOS TRANSVERSALES DE ACCESIBILIDAD y la TAREA, que prevalecen si algo aquí pareciera contradecirlos.
- COLOR: "--emotion-bg" para fondos (p. ej. gris ultraclaro), "--emotion-primary" para acentos interactivos (un tono lo bastante oscuro para ≥ 3:1 frente al fondo) y "--emotion-accent-warm" para tonos cálidos o tierra cuando la regla activa los pide.
- TIPOGRAFÍA: única familia sans-serif con "--emotion-font-family" = "Inter, sans-serif" + "set_style" "fontFamily" = "var(--emotion-font-family)" sobre "heading"/"paragraph"; cuerpo ≥ 1rem con "--emotion-text-size"; encabezados con "set_style" "fontSize"/"fontWeight".
- LAYOUT: columna única centrada = "add_class": "adaptive-zen-layout" y/o "adaptive-focused-content" sobre "body"; imágenes decorativas = "hide" sobre "image_decorative" o "adaptive-minimal-icons"; colapsar menús secundarios = "hide" sobre selectores estructurales secundarios de la lista (nunca la navegación principal, los controles ni los enlaces de salto). Texto denso: si el "word_count" real de un "paragraph"/"list_item" supera {truncate_threshold}, "add_class": "adaptive-truncate" sobre ESE elemento. Andamiaje/tips = "suggested_resource": "tutor_virtual" (+ "show_resources"). Dashboard de logros, mapa de competencias, resúmenes y espacios entre pares no tienen mecanismo: van a "reglas_no_aplicadas". Sin animaciones en bucle.
- Ritmo de la transición (ver RITMO DE LA TRANSICIÓN): usa "transition": "suave" — el estudiante ya está activado; un cambio brusco añade sobresalto. Es la lentitud con que el cambio aterriza una vez, no animaciones en bucle.
EOT;

    // No definida en frustrado.py (Python usa getattr(module, "EMPATHIC_EXTRA", "") para el
    // default) — acá se declara explícita en '' para el mismo resultado, sin depender de
    // reflection/getattr en PHP.
    public const EMPATHIC_EXTRA = '';

    public const HARD_RULE_EXTRA = <<<'EOT'
- Ocultar controles de quiz (timer, navegación entre preguntas, flags, notas/outcome) solo se acepta si la confianza reportada es alta (>= 0.70) — en cualquier otro caso esa acción será rechazada. No la propongas si la confianza no es alta.
EOT;
}
