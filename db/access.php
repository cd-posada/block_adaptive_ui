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

$capabilities = [

    // Permite a profesores y managers añadir el bloque a cursos.
    'block/adaptive_ui:addinstance' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype'     => 'write',
        'contextlevel'=> CONTEXT_BLOCK,
        'archetypes'  => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
        'clonedfrom'  => 'moodle/site:manageblocks',
    ],

    // Permite a estudiantes añadir el bloque a su Dashboard personal.
    'block/adaptive_ui:myaddinstance' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype'     => 'write',
        'contextlevel'=> CONTEXT_SYSTEM,
        'archetypes'  => [
            'user' => CAP_ALLOW,
        ],
    ],

    // Fase 7 (Knowledge — reporte docente): ver el historial de emociones/adaptaciones de
    // los estudiantes de un curso (report.php). RISK_PERSONAL porque expone datos de
    // emociones detectadas por estudiante — igual de sensible que el reporte análogo de
    // local_emotions_send. CONTEXT_COURSE para que se evalúe por curso (un docente solo ve
    // los cursos donde tiene el rol), no a nivel de plataforma completa.
    'block/adaptive_ui:viewreports' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype'     => 'read',
        'contextlevel'=> CONTEXT_COURSE,
        'archetypes'  => [
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

];
