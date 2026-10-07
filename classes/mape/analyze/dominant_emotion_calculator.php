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
 * Fase MAPE-K: ANALYZE — emoción dominante, tendencia y gate de confianza sobre una ventana
 * ya recolectada.
 *
 * Extraído de get_adaptation.php en la Fase B.2 (2026-07-22). Distinto de
 * confidence_calculator.php (que recalcula la confianza de una emoción YA conocida, para el
 * flujo de Plan) — aquí la emoción todavía no se conoce, se agregan TODAS las detecciones de
 * la ventana (ver emotion_window_collector) para encontrar cuál domina.
 *
 * No consulta local_emotions_log directamente (eso ya lo hizo emotion_window_collector) — solo
 * la consulta de boost contra mdl_block_adaptive_ui_prefs, necesaria para el umbral adaptativo.
 *
 * Umbral base: 0.45. Valor único del sistema — no admite override. Entre 2026-07-22 y
 * 2026-08-08 get_adaptation.php pudo pasar un valor de prueba (llegó a correr con 0.25); ese
 * parámetro se eliminó porque un umbral que se puede bajar "solo para esta sesión" invalida
 * silenciosamente cualquier medición hecha con él. Si una prueba necesita otro umbral, se
 * cambia esta constante y se documenta el valor usado.
 */
class dominant_emotion_calculator {

    /** Emoción dominante debe representar al menos este porcentaje del total de detecciones. */
    # private const DOMINANCE_RATIO = 0.50;
    private const DOMINANCE_RATIO = 0.30;

    /** Diferencia de confianza (segunda mitad - primera mitad) para considerar la tendencia. */
    private const TREND_DELTA = 0.08;

    /** Umbral base de confianza antes de sumar el boost acumulado de prefs. */
    # private const BASE_THRESHOLD = 0.45;
    // Publico desde v3.1: plan_engine lo envia al LLM como umbral_confianza_minima (prompt v6).
    public const BASE_THRESHOLD = 0.35;

    /** Umbral efectivo nunca supera este tope, sin importar cuánto boost se acumule. */
    private const MAX_THRESHOLD = 0.85;

    /** Ventana de boost acumulado: últimas 24h de mdl_block_adaptive_ui_prefs. */
    private const BOOST_WINDOW_SECONDS = 86400;

    /**
     * Calcula la emoción dominante de una ventana ya recolectada, su confianza promedio, la
     * tendencia, y si supera los gates de dominancia + umbral adaptativo.
     *
     * IMPORTANTE: 'dominant' y 'avg_confidence' se retornan siempre que $records no esté
     * vacío, incluso si 'sufficient' es false — el caller (get_adaptation::execute()) los usa
     * para completar el snapshot de Knowledge (effectiveness_repository) independientemente de
     * si el gate de dominancia/umbral decide mostrar el panel. Mismo criterio ya documentado en
     * get_adaptation.php: ese snapshot es válido aunque el sistema no recomiende nada.
     *
     * @param array $records Detecciones de la ventana
     *                       (ver emotion_window_collector::collect()), no vacío.
     * @param int   $userid  Usuario, para la consulta de boost en prefs.
     * @return array {dominant: string, avg_confidence: float, trend: string, sufficient: bool}.
     */
    public static function analyze(array $records, int $userid): array {
        global $DB;

        // Moda ponderada: gana la emoción con mayor suma de confidencias.
        $totals = [];
        $counts = [];
        foreach ($records as $r) {
            $e          = $r->emotion;
            $totals[$e] = ($totals[$e] ?? 0.0) + (float) $r->confidence;
            $counts[$e] = ($counts[$e] ?? 0) + 1;
        }
        arsort($totals);
        $dominant   = array_key_first($totals);
        $total_n    = count($records);
        $dominant_n = $counts[$dominant];

        // Detecciones de la emoción dominante (orden temporal ASC, heredado de la ventana).
        $dom_records    = array_values(array_filter(
            (array) $records,
            fn($r) => $r->emotion === $dominant
        ));
        $avg_confidence = array_sum(array_column($dom_records, 'confidence')) / $dominant_n;

        // Dominancia < 50% del total → señal insuficiente, no se calcula tendencia/umbral.
        if (($dominant_n / $total_n) < self::DOMINANCE_RATIO) {
            return [
                'dominant'       => $dominant,
                'avg_confidence' => $avg_confidence,
                'trend'          => 'estable',
                'sufficient'     => false,
            ];
        }

        // ── TENDENCIA (bisección temporal) ───────────────────────────────────
        $trend = 'estable';
        if ($dominant_n >= 6) {
            $half      = (int) floor($dominant_n / 2);
            $avg_first = array_sum(array_column(array_slice($dom_records, 0, $half), 'confidence')) / $half;
            $sec_half  = array_slice($dom_records, $half);
            $avg_sec   = array_sum(array_column($sec_half, 'confidence')) / count($sec_half);
            $diff      = $avg_sec - $avg_first;
            if ($diff > self::TREND_DELTA) {
                $trend = 'empeorando';
            } elseif ($diff < -self::TREND_DELTA) {
                $trend = 'mejorando';
            }
        }

        // ── Umbral adaptativo ─────────────────────────────────────────────────
        $boost = (float) $DB->get_field_sql(
            "SELECT COALESCE(SUM(threshold_boost), 0)
               FROM {block_adaptive_ui_prefs}
              WHERE userid = :u AND emotion = :e
                AND timecreated >= :since",
            ['u' => $userid, 'e' => $dominant, 'since' => time() - self::BOOST_WINDOW_SECONDS]
        );
        $threshold = min(self::MAX_THRESHOLD, self::BASE_THRESHOLD + $boost);

        return [
            'dominant'       => $dominant,
            'avg_confidence' => $avg_confidence,
            'trend'          => $trend,
            'sufficient'     => $avg_confidence >= $threshold,
        ];
    }
}
