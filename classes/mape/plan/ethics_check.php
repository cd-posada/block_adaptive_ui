<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @copyright  2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
//             Politécnico Colombiano Jaime Isaza Cadavid
//             Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
// @author     Miguel Ángel Salazar Rincón

namespace block_adaptive_ui\mape\plan;

defined('MOODLE_INTERNAL') || die();

/**
 * Fase D (migración a PHP, 2026-08-01) — puerto literal de adaptation_service/ethics_check.py.
 *
 * Checklist declarativo de reglas éticas/de negocio sobre la salida CRUDA del LLM (antes de
 * sanitizer.php). sanitizer.php valida QUÉ es sintácticamente permitido; esta capa valida SI
 * ese conjunto de acciones es éticamente aceptable dado (emotion, confidence) — una capa
 * adicional, no un reemplazo del sanitizador técnico.
 *
 * Diferencia de comportamiento importante frente a sanitizer.php (confirmado releyendo
 * ethics_check.py antes de portar, no asumido): sanitizer.php descarta acción por acción —
 * una acción inválida no tumba las demás. ethics_check.php es TODO O NADA — si CUALQUIER
 * regla se viola, se rechaza la respuesta COMPLETA (check() devuelve el motivo, string no
 * vacío; el caller, cuando exista plan_engine.php, debe tratar cualquier string no-null como
 * "descartar todo", no como "descartar solo esa acción").
 *
 * Diferencia estructural deliberada frente a Python (no de comportamiento): Python arma
 * RULES como una lista de funciones y las recorre en un loop; acá check() llama a las dos
 * reglas directamente en secuencia, mismo orden (quiz primero, concentrado segundo) — más
 * simple en PHP sin la indirección de un array de callables, mismo resultado observable.
 */
class ethics_check {

    public const HIGH_CONFIDENCE_THRESHOLD = 0.70;

    // Nombres de gate (STATIC_SELECTOR_GATES) que controlan una evaluación activa — puerto
    // literal de _QUIZ_CRITICAL_GATES.
    private const QUIZ_CRITICAL_GATES = [
        'has_quiz_timer', 'has_quiz_nav', 'has_quiz_progress', 'has_quiz_outcome', 'has_question_flag',
    ];

    /**
     * Selectores que controlan una evaluación activa — DERIVADOS de
     * sanitizer::STATIC_SELECTOR_GATES (no re-inventados aquí), igual que QUIZ_CRITICAL_
     * SELECTORS en Python. Método, no const, por la misma razón que
     * sanitizer::css_var_value_patterns(): PHP no permite una llamada a función (aquí,
     * recorrer y filtrar un array) dentro de la expresión de un `const`.
     */
    public static function quiz_critical_selectors(): array {
        $selectors = [];
        foreach (sanitizer::STATIC_SELECTOR_GATES as $selector => $gate) {
            if ($gate !== null && in_array($gate, self::QUIZ_CRITICAL_GATES, true)) {
                $selectors[] = $selector;
            }
        }
        return $selectors;
    }

    /**
     * Puerto literal de _rule_no_hiding_quiz_controls_unless_frustrado_alta(). Ocultar
     * controles críticos de un quiz activo (timer, navegación, progreso, notas/outcome,
     * flags) solo se permite si emotion=='Frustrado' Y confidence >= HIGH_CONFIDENCE_
     * THRESHOLD (0.70) — exactamente lo que autoriza la Tabla 4/24 para ese caso.
     */
    private static function rule_no_hiding_quiz_controls_unless_frustrado_alta(
        array $raw_actions, string $emotion, float $confidence
    ): ?string {
        if ($emotion === 'Frustrado' && $confidence >= self::HIGH_CONFIDENCE_THRESHOLD) {
            return null; // exactamente lo que autoriza la Tabla 4/24 para este caso
        }
        $critical = self::quiz_critical_selectors();
        foreach ($raw_actions as $item) {
            if (!is_array($item)) {
                continue;
            }
            $action = $item['action'] ?? null;
            $selector = $item['selector'] ?? null;
            if ($action === 'hide' && in_array($selector, $critical, true)) {
                return sprintf(
                    "hide_quiz_critical_control (selector='%s', emotion='%s', confidence=%.2f — " .
                        'requiere Frustrado + confianza >= %.2f)',
                    (string) $selector, $emotion, $confidence, self::HIGH_CONFIDENCE_THRESHOLD
                );
            }
        }
        return null;
    }

    /**
     * Puerto literal de _rule_concentrado_never_hides(). Concentrado (Compromiso) solo
     * refuerza positivamente — nunca oculta nada, sin importar el selector (sidebar, timer,
     * o cualquier otro del nivel FIJO o dinámico).
     */
    private static function rule_concentrado_never_hides(
        array $raw_actions, string $emotion, float $confidence
    ): ?string {
        if ($emotion !== 'Concentrado') {
            return null;
        }
        foreach ($raw_actions as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['action'] ?? null) === 'hide') {
                $selector = $item['selector'] ?? null;
                return sprintf("concentrado_never_hides (selector='%s')", (string) $selector);
            }
        }
        return null;
    }

    /**
     * Puerto literal de check(). Devuelve la razón de rechazo (string) si alguna regla se
     * viola, o null si todo pasa. Mismo orden que RULES en Python: quiz crítico primero,
     * concentrado segundo — importa en el caso borde de un "hide" sobre un selector crítico
     * de quiz CON emotion=='Concentrado': la regla de quiz gana y su motivo es el que se
     * devuelve, no "concentrado_never_hides" (ninguna de las dos reglas se salta, pero la
     * primera en el orden es la que reporta).
     */
    public static function check(array $raw_dom_actions, string $emotion, float $confidence): ?string {
        $reason = self::rule_no_hiding_quiz_controls_unless_frustrado_alta($raw_dom_actions, $emotion, $confidence);
        if ($reason !== null) {
            return $reason;
        }
        return self::rule_concentrado_never_hides($raw_dom_actions, $emotion, $confidence);
    }
}
