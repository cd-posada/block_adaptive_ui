<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @copyright  2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
//             Politécnico Colombiano Jaime Isaza Cadavid
//             Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // API key de OpenRouter para la fase Plan (llm_client.php llama a OpenRouter directo desde
    // PHP). v3.0 (2026-09-25): único ajuste del bloque — adaptation_url/adaptation_secret/
    // use_php_plan se eliminaron junto con la ruta a adaptation_service (Python). Enmascarado,
    // default vacío: sin key configurada la llamada a OpenRouter falla con 401, sin fallback
    // silencioso.
    $settings->add(new admin_setting_configpasswordunmask(
        'block_adaptive_ui/openrouter_api_key',
        get_string('openrouter_api_key', 'block_adaptive_ui'),
        get_string('openrouter_api_key_desc', 'block_adaptive_ui'),
        ''
    ));
}
