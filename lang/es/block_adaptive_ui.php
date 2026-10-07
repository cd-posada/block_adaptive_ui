<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @copyright  2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
//             Politécnico Colombiano Jaime Isaza Cadavid
//             Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
// @author     Miguel Ángel Salazar Rincón

// ── Nombre del plugin ────────────────────────────────────────────────────────
$string['pluginname'] = 'Adaptación Visual por Emoción';

// ── Capacidades ───────────────────────────────────────────────────────────────
$string['adaptive_ui:addinstance']   = 'Añadir bloque de adaptación visual a cursos';
$string['adaptive_ui:myaddinstance'] = 'Añadir bloque de adaptación visual al Dashboard personal';
$string['adaptive_ui:viewreports']   = 'Ver el reporte de emociones y efectividad de adaptaciones de los estudiantes del curso';

// ── Privacidad (Ley 1581 de 2012 / GDPR) ────────────────────────────────────
$string['privacy:metadata'] = 'El bloque "Adaptación Visual por Emoción" no almacena datos personales propios. '
    . 'Únicamente lee en modo lectura los registros de emociones generados por el módulo local_emotions_send '
    . '(tabla mdl_local_emotions_log) para adaptar dinámicamente la interfaz visual. '
    . 'No almacena ni transmite imágenes faciales ni datos biométricos.';

// ── Indicador de estado ───────────────────────────────────────────────────────
$string['blocktitle']       = 'Estado de adaptación visual';
$string['status_active']    = 'Adaptación activa';
$string['status_paused']    = 'Adaptación pausada';
$string['status_inactive']  = 'Sin datos recientes';

// ── Etiquetas de emociones ────────────────────────────────────────────────────
$string['emotion_concentrado'] = 'Concentrado';
$string['emotion_confundido']  = 'Confundido';
$string['emotion_frustrado']   = 'Frustrado';
$string['emotion_aburrido']    = 'Aburrido';

// ── Mensajes de error ─────────────────────────────────────────────────────────
$string['error_adaptation_unavailable'] = 'El servicio de adaptación no está disponible. Se mantiene la interfaz estándar.';
$string['error_no_emotion_data'] = 'No hay datos de emoción recientes (últimos 5 minutos).';

// ── Tareas programadas ────────────────────────────────────────────────────────
$string['task_generate_llm'] = 'Generar texto LLM para adaptación';

// ── Configuración de administración ──────────────────────────────────────────

// ── Reporte docente (Fase 7 — Knowledge) ─────────────────────────────────────
$string['report_title']   = 'Reporte de adaptaciones';
$string['report_heading'] = 'Emociones y efectividad de las adaptaciones';
