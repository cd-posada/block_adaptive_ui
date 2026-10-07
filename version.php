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

$plugin->component = 'block_adaptive_ui';
$plugin->version   = 2026100100;   // v4.0: listo para produccion (bloque en todas las paginas del curso, hook de respaldo, siembra de reglas v6)
$plugin->requires  = 2024042200;   // Moodle 4.4+ (probado en 4.5)
$plugin->supported = [404, 405];
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = 'v4.0';
$plugin->dependencies = [
    // Emociones detectadas (log de sesion del navegador), modelo de usuario v5, plan_context y
    // registro de cada adaptacion (adaptation_logger).
    'local_emotions_send' => 2026100100,
];
