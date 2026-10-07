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
 * Fase MAPE-K: MONITOR — validación de la ventana de emociones enviada por el navegador.
 *
 * v3.0 (2026-09-25): local_emotions_send v5 ya no guarda userid/emotion en
 * mdl_local_emotions_log; en cambio escribe cada detección en el sessionStorage del
 * navegador ('emotions_log', ver local/emotions_send/js/session_log.js). El módulo AMD
 * mape/monitor/emotion_log_reader la lee, le asigna hora absoluta y la manda en cada llamada
 * a los web services como JSON [{emotion, confidence, t}, ...].
 *
 * Como esos datos vienen del cliente, aquí se validan antes de que Analyze los use:
 * lista blanca de emociones, confianza acotada a [0,1], hora dentro de la ventana y tope de
 * registros. Lo que no pasa se descarta en silencio (no invalida el resto). El peor caso de
 * un cliente manipulado es alterar la adaptación de su propia interfaz.
 */
class client_emotion_log {

    /** Etiquetas que el resto del ciclo MAPE-K entiende (prompts, FALLBACKS, reporte). */
    public const VALID_EMOTIONS = ['Aburrido', 'Concentrado', 'Confundido', 'Frustrado'];

    /** Tope de registros aceptados: 90s a ~1 detección/s, con holgura. */
    public const MAX_RECORDS = 150;

    /** Tolerancia (s) para desfases de reloj entre navegador y servidor. */
    private const CLOCK_SKEW_SECONDS = 10;

    /**
     * Parsea y valida la ventana enviada por el cliente.
     *
     * @param string   $json JSON [{emotion, confidence, t}], t en epoch segundos.
     * @param int|null $now  Hora de referencia (inyectable en tests).
     * @return array Registros {id, emotion, confidence, timecreated} ASC por timecreated —
     *               el mismo shape que devolvía la consulta a mdl_local_emotions_log.
     */
    public static function parse(string $json, ?int $now = null): array {
        $now = $now ?? time();
        $data = json_decode($json ?: '[]', true);
        if (!is_array($data)) {
            return [];
        }

        $min_t = $now - analyze_config::WINDOW_SECONDS - self::CLOCK_SKEW_SECONDS;
        $max_t = $now + self::CLOCK_SKEW_SECONDS;

        $records = [];
        foreach (array_slice($data, -self::MAX_RECORDS) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $emotion    = $entry['emotion'] ?? null;
            $confidence = $entry['confidence'] ?? null;
            $t          = $entry['t'] ?? null;

            if (!is_string($emotion) || !in_array($emotion, self::VALID_EMOTIONS, true)) {
                continue;
            }
            if (!is_numeric($confidence) || !is_numeric($t)) {
                continue;
            }
            $t = (int) $t;
            if ($t < $min_t || $t > $max_t) {
                continue;
            }

            $records[] = (object) [
                'emotion'     => $emotion,
                'confidence'  => max(0.0, min(1.0, (float) $confidence)),
                'timecreated' => $t,
            ];
        }

        usort($records, fn($a, $b) => $a->timecreated <=> $b->timecreated);
        foreach ($records as $i => $r) {
            $r->id = $i + 1;
        }
        return $records;
    }
}
