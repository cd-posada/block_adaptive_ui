<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @copyright  2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
//             Politécnico Colombiano Jaime Isaza Cadavid
//             Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
// @author     Miguel Ángel Salazar Rincón

$string['pluginname']               = 'Adaptación Visual por Emoción';
$string['adaptive_ui:addinstance']  = 'Añadir bloque de adaptación visual a cursos';
$string['adaptive_ui:myaddinstance']= 'Añadir bloque de adaptación visual al Dashboard';
$string['adaptive_ui:viewreports']  = 'Ver el reporte de emociones y efectividad de adaptaciones de los estudiantes del curso';
$string['privacy:metadata']         = 'El bloque Adaptive UI no almacena datos personales. Solo lee registros de emociones del módulo local_emotions_send para adaptar la interfaz visual sin transmitir datos a terceros.';
$string['blocktitle']               = 'Estado de adaptación visual';
$string['status_active']            = 'Adaptación activa';
$string['status_paused']            = 'Adaptación pausada';
$string['status_inactive']          = 'Sin datos recientes';
$string['emotion_concentrado']      = 'Concentrado';
$string['emotion_confundido']       = 'Confundido';
$string['emotion_frustrado']        = 'Frustrado';
$string['emotion_aburrido']         = 'Aburrido';
$string['error_adaptation_unavailable'] = 'El servicio de adaptación no está disponible. Se mantiene la interfaz estándar.';
$string['error_no_emotion_data']    = 'No hay datos de emoción recientes (últimos 5 minutos).';

// ── Admin settings ────────────────────────────────────────────────────────────
$string['openrouter_api_key']      = 'OpenRouter API key';
$string['openrouter_api_key_desc'] = 'Sent as the Authorization: Bearer header on every call llm_client.php makes to OpenRouter (Plan phase, model deepseek/deepseek-v4-flash). Leave empty and every LLM call will fail with 401.';

// ── Scheduled tasks ───────────────────────────────────────────────────────────
$string['task_generate_llm']        = 'Generate LLM text for UI adaptation';

// ── Teacher report (Fase 7 — Knowledge) ────────────────────────────────────────
// Valores en español a propósito: el sitio corre con idioma inglés, así que Moodle sirve este
// archivo y nunca lang/es. Misma convención que ya usan pluginname/blocktitle/status_* arriba.
$string['report_title']   = 'Reporte de adaptaciones';
$string['report_heading'] = 'Emociones y efectividad de las adaptaciones';
