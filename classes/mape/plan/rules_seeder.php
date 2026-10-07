<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

namespace block_adaptive_ui\mape\plan;

defined('MOODLE_INTERNAL') || die();

use block_adaptive_ui\mape\plan\prompts\rules_v6;

/**
 * Siembra las 36 reglas del documento v6 (rules_v6::FALLBACK_RULES) en la tabla Rule del modelo
 * de usuario de local_emotions_send (base externa user_model_v5_1 o tablas de Moodle), para que
 * en una instalacion nueva el prompt use reglas con id_rule real y cada Adaptation_Change quede
 * ligada a la regla que la justifica.
 *
 * Solo actua si la emocion NO tiene ninguna regla de diseno (las "Adaptacion generada por LLM"
 * no cuentan): nunca reemplaza ni duplica reglas que el administrador haya editado o cargado.
 * A prueba de fallos: si local_emotions_send no esta o su almacen falla, el prompt sigue usando
 * las reglas de respaldo del documento.
 */
class rules_seeder {

    /** Norman_Level.code de cada nivel del documento. */
    const LEVEL_DB = [
        'VISCERAL'   => 'Visceral',
        'BEHAVIORAL' => 'Behavioral',
        'REFLECTIVE' => 'Reflective',
    ];

    /** @var array emociones ya verificadas en esta peticion */
    private static $checked = [];

    public static function ensure(string $emotion): void {
        $helper = '\\local_emotions_send\\local\\data_helper';
        $code = rules_v6::EMOTION_CODES[$emotion] ?? null;
        if (!$code || isset(self::$checked[$emotion]) || !class_exists($helper)) {
            return;
        }
        self::$checked[$emotion] = true;
        try {
            foreach ($helper::get_rules() as $r) {
                if ((string) $r->emotionname === $emotion
                        && strpos((string) $r->rule_statement, 'Adaptacion generada por LLM') !== 0) {
                    return;
                }
            }
            $emotionid = $helper::get_or_create_emotion($emotion);
            foreach (rules_v6::FALLBACK_RULES[$code] as $level => $dims) {
                foreach ($dims as $dim => $text) {
                    $helper::get_or_create_rule(self::LEVEL_DB[$level], $emotionid,
                        rules_v6::DIMENSION_DB[$dim], $text);
                }
            }
        } catch (\Throwable $e) {
            debugging('block_adaptive_ui: rules_seeder: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
