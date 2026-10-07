<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @copyright  2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
//             Politécnico Colombiano Jaime Isaza Cadavid
//             Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
// @author     Miguel Ángel Salazar Rincón

namespace block_adaptive_ui\privacy;

use core_privacy\local\metadata\null_provider;

/**
 * Proveedor de privacidad del bloque block_adaptive_ui.
 *
 * Este bloque NO almacena datos personales propios.
 * Únicamente lee en modo lectura los registros de la tabla mdl_local_emotions_log,
 * cuya responsabilidad de privacidad corresponde al plugin local_emotions_send.
 *
 * Cumplimiento normativo:
 *   - GDPR (Reglamento General de Protección de Datos, UE 2016/679)
 *   - Ley 1581 de 2012 — Protección de datos personales, Colombia
 *   - El plugin no almacena ni transmite imágenes faciales ni datos biométricos.
 */
class provider implements null_provider {

    /**
     * Retorna la clave del string de idioma que explica por qué este plugin
     * no almacena datos de usuario.
     *
     * @return string Clave del string de privacidad en lang/es/block_adaptive_ui.php
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
