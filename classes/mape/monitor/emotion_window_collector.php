<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @copyright  2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
//             Politécnico Colombiano Jaime Isaza Cadavid
//             Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
// @author     Miguel Ángel Salazar Rincón

namespace block_adaptive_ui\mape\monitor;

use block_adaptive_ui\mape\analyze\analyze_config;

defined('MOODLE_INTERNAL') || die();

/**
 * Fase MAPE-K: MONITOR — recolección cruda de la ventana de detecciones de emociones.
 *
 * Extraído de get_adaptation.php en la Fase B.2 (2026-07-22). A diferencia de
 * confidence_calculator.php (que filtra por una emoción ya conocida), aquí se devuelven
 * TODAS las emociones detectadas en la ventana — dominant_emotion_calculator.php necesita el
 * conjunto completo para decidir cuál domina.
 *
 * v3.0 (2026-09-25): la fuente ya no es mdl_local_emotions_log (local_emotions_send v5 ya no
 * guarda userid/emotion ahí) sino la ventana que el navegador lee del sessionStorage del
 * plugin y envía en cada llamada — ver client_emotion_log (validación) y el módulo AMD
 * mape/monitor/emotion_log_reader (lectura). El shape devuelto no cambia, así que
 * dominant_emotion_calculator no se tocó.
 */
class emotion_window_collector {

    /**
     * Todas las detecciones de la ventana analyze_config::WINDOW_SECONDS, sin filtrar por
     * emoción, ordenadas cronológicamente ascendente (requerido por el cálculo de tendencia
     * de dominant_emotion_calculator, que biseca la ventana en primera/segunda mitad).
     *
     * @param string $emotion_log_json Ventana enviada por el cliente, ver client_emotion_log.
     * @return array Registros {id, emotion, confidence, timecreated}, ASC por timecreated.
     */
    public static function collect(string $emotion_log_json): array {
        return client_emotion_log::parse($emotion_log_json);
    }
}
