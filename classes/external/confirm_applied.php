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
 * Execute MAPE-K (v3.3, 2026-09-30): confirmacion de que una adaptacion quedo PINTADA en el
 * navegador, para medir la latencia de adaptacion en user_model_v5_1.
 *
 * panels.js aplica el CSS, espera el repintado (requestAnimationFrame) y llama a este servicio
 * con applied_at_ms: la hora del navegador en ese instante, SIN corregir (dato crudo). Aqui se
 * anota applied_at_server = llegada de la peticion al servidor (REQUEST_TIME_FLOAT, antes del
 * arranque de Moodle), que es la marca que se usa en los calculos porque comparte reloj con las
 * demas del servidor. El trigger de la base deriva latency_total_ms en el mismo UPDATE
 * (adaptation_logger::applied() de local_emotions_send). Solo acepta eventos de una sesion del
 * propio estudiante, y cada evento se confirma una sola vez.
 */
class confirm_applied extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'event_id'      => new external_value(PARAM_INT, 'Adaptation_Event devuelto por apply_adaptation'),
            'applied_at_ms' => new external_value(PARAM_INT, 'Hora del navegador (ms de epoca, sin corregir) tras el repintado'),
        ]);
    }

    public static function execute(int $event_id, int $applied_at_ms): array {
        global $USER;

        $serverms = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (int) round($_SERVER['REQUEST_TIME_FLOAT'] * 1000)
            : (int) round(microtime(true) * 1000);

        $params = self::validate_parameters(self::execute_parameters(), [
            'event_id'      => $event_id,
            'applied_at_ms' => $applied_at_ms,
        ]);
        self::validate_context(context_system::instance());

        $logger = '\local_emotions_send\local\adaptation_logger';
        if ($params['event_id'] <= 0 || !class_exists($logger)) {
            return ['success' => false];
        }
        return ['success' => $logger::applied((int) $USER->id, $params['event_id'], $params['applied_at_ms'], $serverms)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'true si se registro'),
        ]);
    }
}
