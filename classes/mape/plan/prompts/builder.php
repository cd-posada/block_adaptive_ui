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
 * adaptation_service/prompts/__init__.py::build_system_prompt(). PHP no tiene equivalente a
 * "código de nivel de módulo que corre una vez al importar" — el ensamblado vive en un
 * método estático de una clase dedicada en vez de la raíz de un paquete.
 *
 * Mapeo de nombre: "Concentrado" (enum real del sistema, DB/JS/sanitizer.php/ethics_check.php)
 * → módulo de prompt compromiso.php (término de Tabla 4/tesis) — única traducción de nombre,
 * las otras 3 emociones coinciden 1:1.
 */
class builder {

    private const EMOTION_MODULES = [
        'Frustrado'   => frustrado::class,
        'Confundido'  => confundido::class,
        'Aburrido'    => aburrido::class,
        'Concentrado' => compromiso::class,
    ];

    /**
     * Ensambla el system prompt v6 (prompt_adaptacion_emocional_moodle_v6, WCAG 2.1 y 2.2):
     * ROL + CONTEXTO + REGLAS DE ADAPTACIÓN de la emoción activa (con sus id_regla) + guía de
     * mecanismos del módulo + REQUISITOS TRANSVERSALES + RESTRICCIONES + TAREA, seguido de las
     * secciones operativas de base.php (recursos, ritmo, selectores, fuentes, plataforma,
     * rationale, mensaje empático, formato híbrido y reglas duras).
     *
     * $rules: reglas de la emoción activa leídas de la tabla Rule (local_emotions_send
     * plan_context), cada una {id, level, dimension, text}. Si viene null o vacío se usan
     * rules_v6::FALLBACK_RULES con identificadores sintéticos (V6-...).
     *
     * Lanza \InvalidArgumentException si $emotion no es una de las 4 reconocidas.
     */
    public static function build_system_prompt(string $emotion, ?array $rules = null): string {
        if (!array_key_exists($emotion, self::EMOTION_MODULES)) {
            throw new \InvalidArgumentException(
                "builder::build_system_prompt — emoción no reconocida: '{$emotion}'"
            );
        }
        $module = self::EMOTION_MODULES[$emotion];
        $threshold = base::TRUNCATE_THRESHOLDS[$emotion] ?? null;

        $emotion_block = $module::EMOTION_BLOCK;
        if (str_contains($emotion_block, '{truncate_threshold}')) {
            $emotion_block = str_replace('{truncate_threshold}', (string) $threshold, $emotion_block);
        }

        $sections = [
            rules_v6::ROL,
            rules_v6::CONTEXTO,
            self::render_rules($emotion, $rules),
            $emotion_block,
            rules_v6::REQUISITOS_TRANSVERSALES,
            rules_v6::RESTRICCIONES,
            rules_v6::TAREA,
            base::REGLAS_OCC_ADICIONALES,
            base::RECURSOS_SUGERIDOS,
            base::RITMO_TRANSICION,
            base::ELEMENTOS_CONTENIDO_Y_SELECTORES,
            base::MENU_FUENTES,
            base::CONTEXTO_PLATAFORMA,
            base::EXPLICACIONES_POR_ACCION,
            base::render_mensaje_empatico($module::EMPATHIC_EXTRA),
            base::FORMATO_RESPUESTA,
            base::render_reglas_duras($module::HARD_RULE_EXTRA),
        ];
        return implode("\n\n", $sections);
    }

    /** Orden de presentación y normalización de niveles/dimensiones (códigos de la tabla Rule o del documento). */
    private const LEVEL_ORDER = ['VISCERAL', 'BEHAVIORAL', 'REFLECTIVE'];
    private const DIMENSION_ORDER = ['COLOR', 'FONT', 'LAYOUT'];

    /**
     * Sección REGLAS DE ADAPTACIÓN: solo las 9 reglas de la emoción activa (el documento pide
     * descartar las de otras emociones — Paso 3a), agrupadas por nivel de Norman, cada una con
     * su [id_regla N] para que el LLM la cite en "reglas_aplicadas"/"reglas_no_aplicadas".
     */
    private static function render_rules(string $emotion, ?array $rules): string {
        $code = rules_v6::EMOTION_CODES[$emotion];
        $grouped = [];
        $source = 'tabla Rule de user_model_v5_1';
        foreach ((array) $rules as $r) {
            $level = strtoupper((string) ($r['level'] ?? ''));
            $dim = strtoupper((string) ($r['dimension'] ?? ''));
            $dim = $dim === 'TYPOGRAPHY' ? 'FONT' : $dim;
            if (in_array($level, self::LEVEL_ORDER, true) && in_array($dim, self::DIMENSION_ORDER, true)
                    && trim((string) ($r['text'] ?? '')) !== '') {
                $grouped[$level][$dim][] = ['id' => (string) $r['id'], 'text' => trim((string) $r['text'])];
            }
        }
        if (!$grouped) {
            $source = 'documento v6 (respaldo: tabla Rule no disponible)';
            foreach (rules_v6::FALLBACK_RULES[$code] as $level => $dims) {
                foreach ($dims as $dim => $text) {
                    $grouped[$level][$dim][] = ['id' => "V6-{$code}-{$level}-{$dim}", 'text' => $text];
                }
            }
        }
        $out = "## REGLAS DE ADAPTACIÓN — emoción detectada: {$code} (fuente: {$source})\n" .
            "Cita el identificador entre corchetes de cada regla en \"reglas_aplicadas\" o \"reglas_no_aplicadas\".";
        foreach (self::LEVEL_ORDER as $level) {
            if (empty($grouped[$level])) {
                continue;
            }
            $out .= "\n### Nivel " . rules_v6::LEVEL_NAMES[$level];
            foreach (self::DIMENSION_ORDER as $dim) {
                foreach ($grouped[$level][$dim] ?? [] as $rule) {
                    $out .= "\n- [id_regla {$rule['id']}] {$dim}: {$rule['text']}";
                }
            }
        }
        return $out;
    }
}
