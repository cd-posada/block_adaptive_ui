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

use block_adaptive_ui\mape\analyze\confidence_calculator;
use block_adaptive_ui\mape\monitor\emotion_window_collector;
use block_adaptive_ui\mape\plan\strategy_dispatcher;
use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

/**
 * Web service AJAX: apply_adaptation
 *
 * Fase MAPE-K: PLAN
 *
 * Fachada Moodle de la fase Plan del ciclo MAPE-K — la única que llama al motor LLM
 * (plan_engine en PHP, vía strategy_dispatcher). Se invoca EXCLUSIVAMENTE cuando el estudiante hace clic en
 * "Activar cambios" en el panel de recomendación, nunca durante el polling de 5s
 * de get_adaptation. Esto acota el consumo de la API de OpenRouter a, como máximo,
 * una llamada de Plan por cada adaptación que el estudiante realmente acepta (el texto
 * empático llega en esa misma respuesta).
 *
 * Desde la Fase B.2 (2026-07-22), la lógica de negocio real vive extraída en dos clases:
 * - confidence_calculator::confidence_for_window() (mape/analyze/) — re-lee la confianza
 *   promedio de los últimos analyze_config::WINDOW_SECONDS para la misma emoción (los
 *   segundos transcurridos desde el poll hasta el clic pueden haber cambiado el promedio).
 * - strategy_dispatcher::dispatch() (mape/plan/) — construye el modelo de usuario, llama a
 *   plan_engine e interpreta el resultado (FALLBACKS incluido).
 * Este archivo solo queda como fachada: valida el web service y traduce entre ambas.
 */
class apply_adaptation extends external_api {

    // ─────────────────────────────────────────────────────────────────────────
    // PARÁMETROS DE ENTRADA
    // ─────────────────────────────────────────────────────────────────────────

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'emotion'      => new external_value(PARAM_TEXT, 'Emoción a aplicar'),
            'trend'        => new external_value(PARAM_TEXT, 'Tendencia', VALUE_DEFAULT, 'estable'),
            'page_context' => new external_value(
                PARAM_RAW,
                'JSON con contexto del DOM (detectPageContext(), capturado al momento del clic)',
                VALUE_DEFAULT,
                '{}'
            ),
            // Fase E (2026-07-28) — mismo patrón ya probado en record_action.php:56-65.
            // $COURSE/$PAGE->course NO reflejan el curso real en este contexto de web service
            // (confirmado: lib/setup.php:826 los inicializa como clon de SITE, y este flujo
            // nunca llama require_login($courseid) ni reasigna $COURSE) — el curso real solo
            // puede llegar como parámetro explícito, poblado del lado JS con el mismo courseId
            // que ya usa recordAction() (panels.js).
            'courseid' => new external_value(
                PARAM_INT,
                'Curso donde ocurrió (0 si no aplica) — para user_model::build_user_model()',
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
     * @return array Resultado con action, css_params (JSON), norman_level, empathic_message.
     */
    public static function execute(
        string $emotion,
        string $trend        = 'estable',
        string $page_context = '{}',
        int    $courseid     = 0,
        string $emotion_log  = '[]'
    ): array {
        global $USER;

        self::validate_parameters(self::execute_parameters(), [
            'emotion'      => $emotion,
            'trend'        => $trend,
            'page_context' => $page_context,
            'courseid'     => $courseid,
            'emotion_log'  => $emotion_log,
        ]);

        $context = context_system::instance();
        self::validate_context($context);

        // Re-leer avg_confidence de los últimos WINDOW_SECONDS para esta emoción (puede
        // haber cambiado levemente desde el poll que mostró el panel hasta este clic).
        $records    = emotion_window_collector::collect($emotion_log);
        $confidence = confidence_calculator::confidence_for_window($records, $emotion);

        // Latencia (user_model_v5_1): la deteccion que origina la adaptacion se fija ANTES de
        // llamar al LLM; su window_end es el origen de latency_total_ms. Tomarla despues (el LLM
        // tarda decenas de segundos) la ligaria a una deteccion posterior a la decision.
        $logger = '\local_emotions_send\local\adaptation_logger';
        $timing = [];
        if (class_exists($logger)) {
            $timing['emorecordid'] = $logger::triggering_record((int) $USER->id, (int) $courseid);
        }

        $result = strategy_dispatcher::dispatch(
            $emotion, $trend, $confidence, $page_context, $USER->id, $courseid
        );

        // Knowledge compartido (2026-09-29): cada adaptacion queda tambien en el modelo de
        // usuario v5 de local_emotions_send (Adaptation_Event + una Adaptation_Change por cada
        // token/accion), en su almacen (tablas de Moodle o base externa user_model_v5_1). La
        // decision del estudiante (APPLIED/SKIPPED + Adaptation_Feedback) la completa
        // record_action. adaptation_logger nunca lanza excepciones.
        // decision_requested_at / decision_generated_at: marcas del servidor alrededor de la
        // llamada a OpenRouter (plan_engine).
        $timing += \block_adaptive_ui\mape\plan\plan_engine::last_timing();
        $eventid = 0;
        if (class_exists($logger)) {
            if ($result['action'] === 'recommend') {
                $eventid = $logger::planned((int) $USER->id, (int) $courseid, $emotion, (float) $confidence,
                    (string) $result['norman_level'], (string) $result['css_params'],
                    \block_adaptive_ui\mape\plan\plan_engine::OPENROUTER_MODEL, $timing);
            } else {
                $logger::gated((int) $USER->id, (int) $courseid, $emotion,
                    'El LLM no devolvio una adaptacion valida (error/timeout de OpenRouter o respuesta descartada por los filtros)',
                    $timing);
            }
        }

        return [
            'action'           => $result['action'],
            'css_params'       => $result['css_params'],
            'norman_level'     => $result['norman_level'],
            'empathic_message' => $result['empathic_message'],
            // Adaptation_Event registrado (0 si no se registro): la caja de explicacion lo usa
            // para enviar la calificacion/comentario/reversion (record_feedback).
            'event_id'         => (int) $eventid,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TIPO DE RETORNO
    // ─────────────────────────────────────────────────────────────────────────

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'action'           => new external_value(PARAM_TEXT, 'none | recommend'),
            'css_params'       => new external_value(PARAM_RAW,  'JSON con css_vars y dom_actions'),
            'norman_level'     => new external_value(PARAM_TEXT, 'Nivel del modelo de Norman'),
            'empathic_message' => new external_value(PARAM_TEXT, 'Mensaje empático generado por LLM'),
            'event_id'         => new external_value(PARAM_INT, 'Adaptation_Event registrado (0 si ninguno)', VALUE_DEFAULT, 0),
        ]);
    }
}
