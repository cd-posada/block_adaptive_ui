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

use block_adaptive_ui\mape\analyze\confidence_calculator;

defined('MOODLE_INTERNAL') || die();

/**
 * Fase MAPE-K: KNOWLEDGE — efectividad de la adaptación (mdl_block_adaptive_ui_effectiveness).
 *
 * Fuente única para el ciclo de vida completo de una fila de esta tabla:
 * - insert_pending_effectiveness() (extraído de record_action.php en la Fase B.2, 2026-07-22)
 *   ABRE la fila al registrar 'activated'/'closed' — emotion_before/confidence_before ya
 *   conocidos, emotion_after/confidence_after quedan NULL ("pendiente").
 * - update_pending_effectiveness() (extraído de get_adaptation.php en la misma Fase B.2)
 *   CIERRA la fila pendiente más reciente del usuario en el siguiente poll.
 * Ambos métodos codifican el mismo conocimiento de esquema/convención ("pendiente" =
 * emotion_after IS NULL) sobre la misma tabla — mantenerlos juntos evita que ese criterio se
 * duplique entre dos clases distintas.
 */
class effectiveness_repository {

    /**
     * Abre una fila "pendiente" de efectividad: emotion_before/confidence_before ya se conocen
     * (la emoción que disparó el panel, con su confianza recalculada al momento de la
     * decisión), emotion_after/confidence_after quedan NULL hasta que
     * update_pending_effectiveness() los complete en un poll posterior.
     *
     * Recalcula confidence_before vía confidence_calculator::confidence_for_window() en vez de
     * repetir la consulta inline — extraído de record_action.php en la Fase B.2 (2026-07-22),
     * cierra la segunda de las tres copias documentadas en
     * pending_cleanup_duplicated_confidence_window.md.
     *
     * @param int    $userid   Usuario actual.
     * @param int    $courseid Curso donde ocurrió (0 si no aplica).
     * @param string $emotion  Emoción que disparó el panel ('emotion_before').
     * @param bool   $adapted  true si el estudiante activó los cambios, false si cerró/rechazó.
     * @param array  $records  Ventana de detecciones (emotion_window_collector::collect()) —
     *                         v3.0: la confianza ya no se relee de mdl_local_emotions_log.
     */
    public static function insert_pending_effectiveness(
        int $userid, int $courseid, string $emotion, bool $adapted, array $records
    ): void {
        global $DB;

        $confidence_before = confidence_calculator::confidence_for_window($records, $emotion);

        $DB->insert_record('block_adaptive_ui_effectiveness', (object) [
            'userid'            => $userid,
            'courseid'          => $courseid,
            'emotion_before'    => $emotion,
            'confidence_before' => round($confidence_before, 4),
            'emotion_after'     => null,
            'confidence_after'  => null,
            'adapted'           => $adapted ? 1 : 0,
            'timecreated'       => time(),
        ]);
    }

    /**
     * Completa la fila de efectividad pendiente más reciente de este usuario (emotion_after IS
     * NULL), si existe, con el snapshot de emoción dominante ya calculado por
     * dominant_emotion_calculator::analyze(). No hace nada si no hay ninguna fila pendiente —
     * el caso normal en la inmensa mayoría de los polls, ya que solo se abre una fila
     * pendiente por cada 'activated'/'closed' real.
     *
     * Debe llamarse independientemente de si el gate de dominancia/umbral decide recomendar
     * o no — este snapshot de "emoción dominante actual" es válido de todas formas; el gate
     * solo controla si se muestra el panel, no si el dato sirve como "after".
     *
     * @param int    $userid         Usuario actual.
     * @param string $dominant       Emoción dominante recién calculada.
     * @param float  $avg_confidence Confianza promedio de esa emoción dominante.
     */
    public static function update_pending_effectiveness(int $userid, string $dominant, float $avg_confidence): void {
        global $DB;

        $pending = $DB->get_records_sql(
            "SELECT id
               FROM {block_adaptive_ui_effectiveness}
              WHERE userid = :uid AND emotion_after IS NULL
              ORDER BY timecreated DESC",
            ['uid' => $userid],
            0, 1
        );

        if (!$pending) {
            return;
        }

        $pendingid = array_key_first($pending);
        $DB->update_record('block_adaptive_ui_effectiveness', (object) [
            'id'               => $pendingid,
            'emotion_after'    => $dominant,
            'confidence_after' => round($avg_confidence, 4),
        ]);
    }
}
