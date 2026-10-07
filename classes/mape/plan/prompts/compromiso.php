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
 * adaptation_service/prompts/compromiso.py. Ver base.php para el contrato que debe cumplir
 * este archivo.
 *
 * Mapeo de nombre (ver también prompts/builder.php): "Compromiso" es el término de Tabla 4/
 * tesis; "Concentrado" sigue siendo el valor real del enum de emoción en todo el resto del
 * sistema (DB, JS, sanitizer.php, ethics_check.php, NORMAN) — no se renombra el enum, solo
 * el nombre de este módulo de prompt.
 *
 * "adaptive-hope-indicator" NO se usa aquí a propósito — esa clase está reservada
 * exclusivamente para Esperanza pre-evaluación en emociones NEGATIVAS en contexto de quiz
 * (ver base.php::REGLAS_OCC_ADICIONALES). Compromiso reutiliza "adaptive-engagement-glow".
 */
class compromiso {

    public const EMOTION_BLOCK = <<<'EOT'
## GUÍA DE MECANISMOS — COMPROMISO (ENGAGEMENT)
Esta guía explica CÓMO implementar las reglas de la emoción con los mecanismos que el sistema ejecuta; QUÉ aplicar (colores, fuentes, layout, magnitud) lo deciden las REGLAS DE ADAPTACIÓN v6, los REQUISITOS TRANSVERSALES DE ACCESIBILIDAD y la TAREA, que prevalecen si algo aquí pareciera contradecirlos.
- Recuerda la compuerta (TAREA, Paso 1 c): para Engagement el estado es "mantener" — solo agrega lo que las reglas prescriben para sostener el compromiso y que no exista ya, sin reorganizar el layout.
- COLOR: "--emotion-primary" para el acento; cambios mínimos y siempre dentro de los mínimos de contraste.
- TIPOGRAFÍA: jerarquía con "set_style" "fontSize"/"fontWeight" sobre elementos "heading"; familia con "--emotion-font-family" + "set_style" "fontFamily" = "var(--emotion-font-family)" ("Inter, sans-serif"/"system-ui, sans-serif" para sans-serif, "Georgia, serif" para serif de cuerpo en "paragraph"). La fuente script "Caveat" solo existe vía "add_class": "adaptive-script-heading" sobre "body" (afecta solo a encabezados grandes; nunca cuerpo, botones ni formularios).
- LAYOUT / refuerzo: "add_class": "adaptive-engagement-glow" sobre "body" (resplandor sutil en acciones principales). Solo en la vista de curso y si existe un elemento "activity": "adaptive-engaged-pulse-soft" sobre su "selector" exacto. Progreso visible, iconos por sección, bordes 3D, texturas y mensajes de logro no tienen mecanismo propio: van a "reglas_no_aplicadas".
- Nunca ocultes ni trunques contenido para esta emoción (ver REGLAS DURAS).
- Ritmo de la transición (ver RITMO DE LA TRANSICIÓN): usa "transition": "inmediata" — el estudiante está concentrado; un cambio que se despliega lentamente le roba atención. "suave" y "escalonada" están vetadas para esta emoción.
EOT;

    public const EMPATHIC_EXTRA = '';

    // Regla dura propia — Compromiso solo refuerza, jamás oculta nada. Ya existe una red de
    // seguridad server-side idéntica en ethics_check.php (rule_concentrado_never_hides) —
    // esta cláusula es la mitad "instrucción al LLM" de esa misma garantía, no un reemplazo.
    public const HARD_RULE_EXTRA = <<<'EOT'
- Para esta emoción, la acción "hide" está PROHIBIDA sin excepción, sin importar el selector ni la confianza reportada — cualquier "hide" que propongas será rechazado. Compromiso solo refuerza positivamente, nunca oculta contenido.
EOT;
}
