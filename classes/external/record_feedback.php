<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

namespace block_adaptive_ui\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

/**
 * Knowledge MAPE-K (v3.2, 2026-09-29): valoración del estudiante sobre una adaptación YA
 * aplicada, enviada desde la caja de explicación (panels.js::showFeedbackBox):
 *  - rating:   1 = OK, 2 = Buena, 3 = Mala (0 = no calificó).
 *  - comment:  comentario libre breve (opcional).
 *  - reverted: el estudiante pulsó "Revertir adaptación".
 *
 * Se guarda en el modelo de usuario v5 de local_emotions_send (adaptation_logger::rate()):
 * Adaptation_Feedback.preceived_usefulness / comment (la fila que ya creó la aceptación) y,
 * si revirtió, Adaptation_Event.reverted_at + decision_state 'REVERTED'. Solo acepta eventos
 * de una sesión del propio estudiante.
 */
class record_feedback extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'event_id' => new external_value(PARAM_INT, 'Adaptation_Event devuelto por apply_adaptation'),
            'rating'   => new external_value(PARAM_INT, '1 = OK, 2 = Buena, 3 = Mala, 0 = sin calificar', VALUE_DEFAULT, 0),
            'comment'  => new external_value(PARAM_TEXT, 'Comentario breve (opcional)', VALUE_DEFAULT, ''),
            'reverted' => new external_value(PARAM_BOOL, 'El estudiante revirtió la adaptación', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(int $event_id, int $rating = 0, string $comment = '', bool $reverted = false): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'event_id' => $event_id,
            'rating'   => $rating,
            'comment'  => $comment,
            'reverted' => $reverted,
        ]);
        self::validate_context(context_system::instance());

        $rating = in_array($params['rating'], [1, 2, 3], true) ? $params['rating'] : 0;
        $comment = \core_text::substr(trim($params['comment']), 0, 500);

        $logger = '\local_emotions_send\local\adaptation_logger';
        if ($params['event_id'] <= 0 || !class_exists($logger)) {
            return ['success' => false];
        }
        return ['success' => $logger::rate((int) $USER->id, $params['event_id'], $rating, $comment, $params['reverted'])];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'true si se guardó'),
        ]);
    }
}
