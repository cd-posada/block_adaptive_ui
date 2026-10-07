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
 * Fase MAPE-K: ANALYZE — configuración compartida.
 *
 * Fuente única de verdad para constantes usadas por las tres consultas de la fase
 * Monitor/Analyze/Plan que leen mdl_local_emotions_log (get_adaptation.php,
 * apply_adaptation.php, get_llm_feedback.php). Antes del 2026-07-05 cada uno de esos tres
 * archivos declaraba su propio "private const WINDOW_SECONDS = 90;" con un comentario pidiendo
 * mantenerlos sincronizados a mano — precisamente el tipo de deriva que causó el bug de la
 * ventana de 60s vs. 90s documentado en la auditoría de la Fase 0. Centralizarla aquí elimina
 * ese riesgo: solo hay un lugar donde cambiar el valor.
 */
class analyze_config {

    /**
     * Ventana de persistencia emocional (segundos). 90s sigue el criterio de Ekman citado en
     * la tesis: duración mínima para diferenciar un "estado emocional" sostenido de una
     * expresión facial puntual.
     */
    #public const WINDOW_SECONDS = 90;
    public const WINDOW_SECONDS = 20;
}
