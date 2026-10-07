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

use block_adaptive_ui\mape\analyze\dominant_emotion_calculator;
use block_adaptive_ui\mape\knowledge\effectiveness_repository;
use block_adaptive_ui\mape\monitor\emotion_window_collector;
use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

/**
 * Web service AJAX: get_adaptation (v3)
 *
 * Fase MAPE-K: MONITOR + ANALYZE
 *
 * Fachada Moodle de las fases Monitor y Analyze del ciclo MAPE-K — sin llamar al motor LLM.
 * v3.0 (2026-09-25): la ventana de emociones llega como parámetro (emotion_log), leída por el
 * navegador del sessionStorage de local_emotions_send v5, en vez de consultarse en
 * mdl_local_emotions_log. La fase Plan (que sí llama a adaptation_service/OpenRouter) vive en
 * apply_adaptation::execute(), invocada únicamente cuando el estudiante hace clic en "Activar
 * cambios" en el panel. Esto evita que cada poll de 5s dispare una llamada real al LLM (antes
 * se llamaba en cada poll que superaba el umbral, incluso si el estudiante nunca aceptaba la
 * recomendación — con detecciones ruidosas esto generó decenas de llamadas innecesarias a
 * OpenRouter en una sola sesión de prueba).
 *
 * Desde la Fase B.2 (2026-07-22), la lógica de negocio real vive extraída en tres clases:
 * - emotion_window_collector::collect() (mape/monitor/) — valida la ventana enviada por el
 *   cliente (analyze_config::WINDOW_SECONDS, 90s; ~1 detección/s con el plugin v5).
 * - dominant_emotion_calculator::analyze() (mape/analyze/) — emoción dominante, confianza
 *   promedio, tendencia (trend), y si supera los gates de dominancia (≥50%) y umbral
 *   adaptativo (base 0.45 + boost acumulado de prefs).
 * - effectiveness_repository::update_pending_effectiveness() (mape/knowledge/) — completa la
 *   fila pendiente más reciente de mdl_block_adaptive_ui_effectiveness de este usuario
 *   (Fase 7, 2026-07-06; ver record_action.php para el otro lado de este mecanismo). Se llama
 *   con el snapshot de emoción dominante/confianza recién calculado, independientemente de si
 *   dominant_emotion_calculator decide que hay señal suficiente para recomendar — ese gate
 *   solo controla si se muestra el panel, no si el dato sirve como snapshot "after".
 * Este archivo solo queda como fachada: valida el web service, orquesta las tres llamadas en
 * orden, y traduce el resultado a {found, action, emotion, trend} para
 * adaptive_monitor.js, que decide si mostrar el panel genérico. css_params/norman_level llegan
 * después, vía apply_adaptation, solo si el estudiante acepta.
 *
 * Característica conocida (no un bug): como la ventana sigue siendo de
 * analyze_config::WINDOW_SECONDS (90s) y el poll ocurre cada 5s, el primer poll tras aceptar
 * una adaptación agrega sobre una ventana que todavía comparte ~95% de las mismas detecciones
 * que "emotion_before" — el cambio solo se refleja con fuerza si llegan detecciones nuevas
 * suficientes para superar por peso a las antiguas (confirmado con datos reales:
 * ver tests/manual/verify_effectiveness.php). Se mantiene así a propósito, siguiendo la
 * instrucción explícita de reusar la agregación existente en vez de crear un mecanismo de
 * captura nuevo.
 */
class get_adaptation extends external_api {

    // ─────────────────────────────────────────────────────────────────────────
    // PARÁMETROS DE ENTRADA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * page_context ya no se usa aquí (el Plan que lo necesita se movió a
     * apply_adaptation) — se mantiene como parámetro opcional únicamente por
     * compatibilidad con el JS existente, que sigue enviándolo en cada poll.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'page_context' => new external_value(
                PARAM_RAW,
                'JSON con contexto del DOM (no usado en esta fase, ver apply_adaptation)',
                VALUE_DEFAULT,
                '{}'
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
     * Ejecuta el ciclo Monitor → Analyze (+ Knowledge) y retorna si corresponde mostrar el panel.
     *
     * @return array Resultado con found, action, emotion, trend.
     */
    public static function execute(string $page_context = '{}', string $emotion_log = '[]'): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'page_context' => $page_context,
            'emotion_log'  => $emotion_log,
        ]);

        $context = context_system::instance();
        self::validate_context($context);

        // ── MONITOR ───────────────────────────────────────────────────────────
        $records = emotion_window_collector::collect($params['emotion_log']);

        if (empty($records)) {
            return self::empty_response();
        }

        // ── ANALYZE ───────────────────────────────────────────────────────────
        // Umbral de activación: dominant_emotion_calculator::BASE_THRESHOLD (0.45), sin
        // override. El override temporal de 0.25 que vivió aquí entre 2026-07-22 y
        // 2026-08-08 (sesiones de verificación B.0 frente a cámara) fue retirado junto con
        // el parámetro que lo permitía: 0.45 es el valor definido del sistema y el único que
        // se ejecuta, también durante las pruebas documentadas en la fase 6.4.
        $result = dominant_emotion_calculator::analyze($records, $USER->id);

        // ── KNOWLEDGE (Fase 7) ───────────────────────────────────────────────────
        // Se llama ANTES de mirar 'sufficient' — el snapshot de "emoción dominante actual" es
        // válido independientemente de si el sistema decide recomendar o no.
        effectiveness_repository::update_pending_effectiveness(
            $USER->id,
            $result['dominant'],
            $result['avg_confidence']
        );

        if (!$result['sufficient']) {
            return self::empty_response();
        }

        return [
            'found'   => true,
            'action'  => 'recommend',
            'emotion' => $result['dominant'],
            'trend'   => $result['trend'],
        ];
    }

    /**
     * Retorna respuesta vacía (sin datos de emoción suficientes).
     */
    private static function empty_response(): array {
        return [
            'found'   => false,
            'action'  => 'none',
            'emotion' => '',
            'trend'   => '',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TIPO DE RETORNO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Define la estructura de datos retornada por el web service.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'found'   => new external_value(PARAM_BOOL, 'true si hay emoción suficiente en la ventana'),
            'action'  => new external_value(PARAM_TEXT, 'none | recommend'),
            'emotion' => new external_value(PARAM_TEXT, 'Emoción dominante detectada'),
            'trend'   => new external_value(PARAM_TEXT, 'mejorando | estable | empeorando'),
        ]);
    }
}
