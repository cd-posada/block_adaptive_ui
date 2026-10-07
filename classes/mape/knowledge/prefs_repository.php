<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @copyright  2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
//             Politécnico Colombiano Jaime Isaza Cadavid
//             Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
// @author     Miguel Ángel Salazar Rincón

namespace block_adaptive_ui\mape\knowledge;

defined('MOODLE_INTERNAL') || die();

/**
 * Fase MAPE-K: KNOWLEDGE — preferencias del umbral adaptativo (mdl_block_adaptive_ui_prefs).
 *
 * Extraído de record_action.php en la Fase B.2 (2026-07-22). El boost acumulado que aquí se
 * inserta lo consume dominant_emotion_calculator::analyze() (mape/analyze/) para subir el
 * umbral efectivo cuando el estudiante rechaza recomendaciones repetidamente.
 */
class prefs_repository {

    /** Boost aplicado cuando el estudiante cierra el panel sin activar los cambios. */
    private const CLOSED_BOOST = 0.05;

    /** Boost aplicado cuando el estudiante activa los cambios (no penaliza el umbral). */
    private const ACTIVATED_BOOST = 0.00;

    /**
     * Registra la decisión del estudiante ('activated' | 'closed') en
     * mdl_block_adaptive_ui_prefs, con el boost de umbral correspondiente.
     *
     * @param int    $userid  Usuario actual.
     * @param string $emotion Emoción a la que aplica la acción.
     * @param string $action  'activated' o 'closed' (ya validado por el caller).
     */
    public static function record(int $userid, string $emotion, string $action): void {
        global $DB;

        $boost = ($action === 'closed') ? self::CLOSED_BOOST : self::ACTIVATED_BOOST;

        $DB->insert_record('block_adaptive_ui_prefs', (object) [
            'userid'          => $userid,
            'emotion'         => $emotion,
            'action'          => $action,
            'threshold_boost' => $boost,
            'timecreated'     => time(),
        ]);
    }
}
