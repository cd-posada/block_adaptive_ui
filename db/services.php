<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @copyright  2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
//             Politécnico Colombiano Jaime Isaza Cadavid
//             Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
// @author     Miguel Ángel Salazar Rincón

defined('MOODLE_INTERNAL') || die();

$functions = [
    'block_adaptive_ui_get_adaptation' => [
        'classname'       => 'block_adaptive_ui\external\get_adaptation',
        'methodname'      => 'execute',
        'description'     => 'Monitor + Analyze del ciclo MAPE-K: lee emoción dominante y calcula trend desde la BD. NO llama al motor LLM (ver apply_adaptation) — solo indica si corresponde mostrar el panel.',
        'type'            => 'read',
        'ajax'            => true,         // Accesible desde JS vía core/ajax
        'loginrequired'   => true,         // Solo usuarios autenticados
        'readonlysession' => true,         // Evita write-lock de sesión PHP en cada poll
    ],
    'block_adaptive_ui_apply_adaptation' => [
        'classname'       => 'block_adaptive_ui\external\apply_adaptation',
        'methodname'      => 'execute',
        'description'     => 'Plan del ciclo MAPE-K: única llamada al motor LLM (adaptation_service/adapt) para decidir css_vars/dom_actions. Se invoca solo cuando el estudiante hace clic en "Activar cambios", no en cada poll.',
        'type'            => 'read',
        'ajax'            => true,
        'loginrequired'   => true,
        'readonlysession' => true,
    ],
    'block_adaptive_ui_record_feedback' => [
        'classname'     => 'block_adaptive_ui\external\record_feedback',
        'methodname'    => 'execute',
        'description'   => 'Knowledge MAPE-K: calificación (1 OK / 2 Buena / 3 Mala), comentario y/o reversión de una adaptación ya aplicada, desde la caja de explicación. Se guarda en Adaptation_Feedback / Adaptation_Event (local_emotions_send).',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
    ],
    'block_adaptive_ui_confirm_applied' => [
        'classname'     => 'block_adaptive_ui\external\confirm_applied',
        'methodname'    => 'execute',
        'description'   => 'Execute MAPE-K: confirma que la adaptación quedó pintada (applied_at del navegador, sin corregir) y registra applied_at_server; el trigger de user_model_v5_1 deriva latency_total_ms (local_emotions_send).',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
    ],
    'block_adaptive_ui_record_action' => [
        'classname'     => 'block_adaptive_ui\external\record_action',
        'methodname'    => 'execute',
        'description'   => 'Knowledge MAPE-K: registra la decisión del estudiante ante el panel de recomendación (activated | closed). Ajusta el umbral adaptativo y abre una fila pendiente en block_adaptive_ui_effectiveness (Fase 7 — reporte docente) que get_adaptation completa en el siguiente poll.',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
    ],
];
