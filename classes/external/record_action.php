<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @copyright  2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
//             Politécnico Colombiano Jaime Isaza Cadavid
//             Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
// @author     Miguel Ángel Salazar Rincón

namespace block_adaptive_ui\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use block_adaptive_ui\mape\knowledge\effectiveness_repository;
use block_adaptive_ui\mape\knowledge\prefs_repository;
use block_adaptive_ui\mape\monitor\emotion_window_collector;
use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

/**
 * Web service AJAX: record_action
 *
 * Fase MAPE-K: KNOWLEDGE
 *
 * Fachada Moodle de la fase Knowledge del ciclo MAPE-K — registra la decisión del estudiante
 * ante el panel de recomendación adaptativa:
 *   - 'activated': acepta los cambios de UI → threshold_boost = 0.00
 *   - 'closed':    rechaza los cambios → threshold_boost = 0.05 (umbral sube)
 *
 * Desde la Fase B.2 (2026-07-22), la lógica de negocio real vive extraída en dos clases:
 * - prefs_repository::record() (mape/knowledge/) — inserta la fila en
 *   mdl_block_adaptive_ui_prefs con el boost correspondiente. Ese boost acumulado lo consume
 *   dominant_emotion_calculator::analyze() para ajustar el umbral de activación por emoción,
 *   evitando interrupciones repetidas no deseadas.
 * - effectiveness_repository::insert_pending_effectiveness() (mape/knowledge/) — abre una fila
 *   "pendiente" en mdl_block_adaptive_ui_effectiveness (emotion_before/confidence_before ya
 *   conocidos; emotion_after/confidence_after quedan NULL). get_adaptation.php — en su
 *   siguiente poll() para este mismo usuario — la completa vía
 *   effectiveness_repository::update_pending_effectiveness(), reusando la misma agregación de
 *   Monitor+Analyze que ya calcula en cada ciclo. No es un mecanismo de captura nuevo, solo dos
 *   puntos de un mismo flujo, ambos ya centralizados en effectiveness_repository.php.
 * Este archivo solo queda como fachada: valida el web service y los valores permitidos de
 * entrada (emoción/acción), y orquesta las dos llamadas.
 */
class record_action extends external_api {

    // ─────────────────────────────────────────────────────────────────────────
    // PARÁMETROS
    // ─────────────────────────────────────────────────────────────────────────

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'emotion'  => new external_value(PARAM_TEXT, 'Emoción a la que aplica la acción'),
            'action'   => new external_value(PARAM_TEXT, 'activated | closed'),
            'courseid' => new external_value(
                PARAM_INT,
                'Curso donde ocurrió (0 si no aplica) — para block_adaptive_ui_effectiveness.courseid',
                VALUE_DEFAULT,
                0
            ),
            // v3.0 — ventana de emociones leída del sessionStorage de local_emotions_send v5
            // (mape/monitor/emotion_log_reader.js); se valida en client_emotion_log.
            'emotion_log' => new external_value(
                PARAM_RAW,
                'JSON [{emotion, confidence, t}] con las detecciones de los últimos 90s',
                VALUE_DEFAULT,
                '[]'
            ),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EJECUCIÓN
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Registra la acción del estudiante en mdl_block_adaptive_ui_prefs, y abre una fila
     * pendiente de efectividad en mdl_block_adaptive_ui_effectiveness.
     *
     * @param string $emotion  Emoción detectada cuando se mostró el panel.
     * @param string $action   'activated' o 'closed'.
     * @param int    $courseid Curso donde ocurrió (0 si no aplica).
     * @return array {success: bool}
     */
    public static function execute(string $emotion, string $action, int $courseid = 0,
                                   string $emotion_log = '[]'): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'emotion'  => $emotion,
            'action'   => $action,
            'courseid'    => $courseid,
            'emotion_log' => $emotion_log,
        ]);
        $emotion  = $params['emotion'];
        $action   = $params['action'];
        $courseid = $params['courseid'];

        $context = context_system::instance();
        self::validate_context($context);

        // Validar valores permitidos
        $valid_emotions = ['Concentrado', 'Confundido', 'Frustrado', 'Aburrido'];
        $valid_actions  = ['activated', 'closed'];
        if (!in_array($emotion, $valid_emotions) || !in_array($action, $valid_actions)) {
            return ['success' => false];
        }

        prefs_repository::record($USER->id, $emotion, $action);

        // Knowledge compartido (2026-09-29): la decision del estudiante completa el
        // Adaptation_Event pendiente en el modelo de usuario v5 de local_emotions_send
        // (APPLIED/SKIPPED + Adaptation_Feedback accepted 1/0). Ver apply_adaptation.
        $logger = '\local_emotions_send\local\adaptation_logger';
        if (class_exists($logger)) {
            $logger::decision((int) $USER->id, (int) $courseid, $emotion, $action);
        }

        effectiveness_repository::insert_pending_effectiveness(
            $USER->id,
            $courseid,
            $emotion,
            $action === 'activated',
            emotion_window_collector::collect($params['emotion_log'])
        );

        return ['success' => true];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TIPO DE RETORNO
    // ─────────────────────────────────────────────────────────────────────────

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'true si el registro fue exitoso'),
        ]);
    }
}
