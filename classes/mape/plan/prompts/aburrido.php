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
 * adaptation_service/prompts/aburrido.py. Ver base.php para el contrato que debe cumplir
 * este archivo.
 */
class aburrido {

    public const EMOTION_BLOCK = <<<'EOT'
## GUÍA DE MECANISMOS — ABURRIDO (BORED)
Esta guía explica CÓMO implementar las reglas de la emoción con los mecanismos que el sistema ejecuta; QUÉ aplicar (colores, fuentes, layout, magnitud) lo deciden las REGLAS DE ADAPTACIÓN v6, los REQUISITOS TRANSVERSALES DE ACCESIBILIDAD y la TAREA, que prevalecen si algo aquí pareciera contradecirlos.
- COLOR: "--emotion-accent-warm" tiñe automáticamente ".btn-primary" y el gradiente de fondo estático; "--emotion-primary" y "--emotion-bg" fijan acento y fondo. Los "gradientes sutiles" se implementan con un "set_style" "backgroundImage" = "linear-gradient(...)" sobre "body" (SOLO gradiente, nunca "url(...)"); verifica el contraste del texto en el punto menos favorable del gradiente. Los colores por nivel o módulo no tienen mecanismo propio: regístralo en "reglas_no_aplicadas" si la regla lo exige.
- TIPOGRAFÍA: interlineado y espaciado con "--emotion-line-height" y "--emotion-letter-spacing"; familia con "--emotion-font-family" + "set_style" "fontFamily" = "var(--emotion-font-family)" sobre elementos "heading"/"paragraph" (de las fuentes que citan las reglas, la única del MENÚ DE FUENTES es "Inter, sans-serif"); encabezados más grandes y en negrita con "set_style" "fontSize"/"fontWeight" sobre elementos "heading". La fuente display "Bebas Neue" solo existe vía "add_class": "adaptive-display-heading" sobre "body" (afecta solo a encabezados).
- LAYOUT: tarjetas interactivas = "add_class": "adaptive-card-layout" sobre "body"; iconos con transiciones = "adaptive-aburrido-icons" sobre "body"; mayor presencia de imágenes con contenido = "set_style" "transform" "scale(N)" (1.0–1.3) sobre elementos "image_content"; pulso de atención = "adaptive-engaged-pulse" sobre "body" o sobre un elemento "activity" existente (si no hay ninguno, omítelo). Texto denso: si el "word_count" real de un "paragraph"/"list_item" supera {truncate_threshold}, "add_class": "adaptive-truncate" sobre ESE elemento. Carruseles, pestañas, paginación, confeti e insignias NO existen como mecanismo: van a "reglas_no_aplicadas".
- Comunicación con pares (OCC): considera "suggested_resource": "forum" (ver RECURSOS SUGERIDOS).
- Ritmo de la transición (ver RITMO DE LA TRANSICIÓN): usa "transition": "rapida". Aquí el objetivo es estimular: el cambio debe notarse; uno lento refuerza la pesadez que se busca romper.
EOT;

    public const EMPATHIC_EXTRA = '';
    public const HARD_RULE_EXTRA = '';
}
