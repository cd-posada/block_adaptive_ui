<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @copyright  2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
//             Politécnico Colombiano Jaime Isaza Cadavid
//             Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
// @author     Miguel Ángel Salazar Rincón

namespace block_adaptive_ui\mape\analyze;

defined('MOODLE_INTERNAL') || die();

/**
 * Fase MAPE-K: ANALYZE — recalculo de confianza para una emoción ya conocida.
 *
 * Extraído de apply_adaptation.php en la Fase B.2 (2026-07-22): fuente única del promedio de
 * confianza de una emoción puntual dentro de la ventana de analyze_config::WINDOW_SECONDS,
 * usada por apply_adaptation.php y effectiveness_repository.php.
 *
 * Distinto de la moda ponderada de get_adaptation.php (que agrega TODAS las emociones de la
 * ventana para encontrar cuál domina) — aquí la emoción ya se conoce de antemano (el
 * estudiante ya aceptó/está viendo el panel de esa emoción específica), solo se recalcula su
 * confianza porque pueden haber pasado varios segundos desde el poll que la detectó.
 */
class confidence_calculator {

    /**
     * Confianza promedio de una emoción específica dentro de la ventana ya recolectada.
     *
     * v3.0 (2026-09-25): recibe los registros de emotion_window_collector::collect() (ventana
     * enviada por el navegador) en vez de consultar mdl_local_emotions_log, que en
     * local_emotions_send v5 ya no tiene userid/emotion.
     *
     * @param array  $records Registros {emotion, confidence, ...} de la ventana.
     * @param string $emotion Emoción específica a promediar (no la dominante de la ventana).
     * @return float Confianza promedio, o 0.75 si no hay detecciones recientes de esa emoción.
     */
    public static function confidence_for_window(array $records, string $emotion): float {
        $vals = [];
        foreach ($records as $r) {
            if ($r->emotion === $emotion) {
                $vals[] = (float) $r->confidence;
            }
        }

        return empty($vals) ? 0.75 : array_sum($vals) / count($vals);
    }
}
