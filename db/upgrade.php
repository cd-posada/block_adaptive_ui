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

/**
 * Migraciones del plugin block_adaptive_ui.
 *
 * @param int $oldversion Versión anterior del plugin.
 * @return bool True si la actualización fue exitosa.
 */
function xmldb_block_adaptive_ui_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    // v2.0 (2026052201): Crear tabla de preferencias de adaptación del estudiante.
    if ($oldversion < 2026052201) {

        $table = new xmldb_table('block_adaptive_ui_prefs');

        $table->add_field('id',              XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid',          XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           null);
        $table->add_field('emotion',         XMLDB_TYPE_CHAR,    '50',   null, XMLDB_NOTNULL, null,           null);
        $table->add_field('action',          XMLDB_TYPE_CHAR,    '20',   null, XMLDB_NOTNULL, null,           null);
        $table->add_field('threshold_boost', XMLDB_TYPE_NUMBER,  '3, 2', null, XMLDB_NOTNULL, null,           '0.00');
        $table->add_field('timecreated',     XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid_emotion', XMLDB_INDEX_NOTUNIQUE, ['userid', 'emotion']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026052201, 'adaptive_ui');
    }

    // v2.7 (2026070501): fis_service se renombró a adaptation_service — la clave de config
    // 'fis_url' pasa a llamarse 'adaptation_url'. Sin esta migración, cualquier valor ya
    // guardado por un administrador bajo 'fis_url' (ej. una URL distinta al default, para un
    // despliegue que no usa Docker) quedaría huérfano: apply_adaptation.php y
    // get_llm_feedback.php empezarían a leer 'adaptation_url' (inexistente) y caerían al
    // default en silencio, sin ningún error visible.
    if ($oldversion < 2026070501) {

        $fisurl = get_config('block_adaptive_ui', 'fis_url');
        if ($fisurl !== false) {
            set_config('adaptation_url', $fisurl, 'block_adaptive_ui');
            unset_config('fis_url', 'block_adaptive_ui');
        }

        upgrade_block_savepoint(true, 2026070501, 'adaptive_ui');
    }

    // v2.9 (2026070601): tabla de efectividad (Knowledge — Fase 7) para el reporte docente.
    // mdl_block_adaptive_ui_prefs NO tiene courseid (confirmado contra este mismo archivo,
    // tabla ya creada arriba) — esta tabla nueva sí lo tiene, para poder filtrar por curso en
    // el reporte sin tener que cruzar por mdl_local_emotions_log en cada consulta.
    if ($oldversion < 2026070601) {

        $table = new xmldb_table('block_adaptive_ui_effectiveness');

        $table->add_field('id',                XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid',             XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           null);
        $table->add_field('courseid',           XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           '0');
        $table->add_field('emotion_before',     XMLDB_TYPE_CHAR,    '50',   null, XMLDB_NOTNULL, null,           null);
        $table->add_field('confidence_before',  XMLDB_TYPE_NUMBER,  '5, 4', null, XMLDB_NOTNULL, null,           null);
        $table->add_field('emotion_after',      XMLDB_TYPE_CHAR,    '50',   null, null,          null,           null);
        $table->add_field('confidence_after',   XMLDB_TYPE_NUMBER,  '5, 4', null, null,          null,           null);
        $table->add_field('adapted',            XMLDB_TYPE_INTEGER, '1',    null, XMLDB_NOTNULL, null,           '0');
        $table->add_field('timecreated',        XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('userid_emotionafter', XMLDB_INDEX_NOTUNIQUE, ['userid', 'emotion_after']);
        $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026070601, 'adaptive_ui');
    }

    // v3.0 (2026-09-25) — la fase Plan corre siempre en PHP (plan_engine). Se eliminaron el
    // flag use_php_plan y la ruta HTTP a adaptation_service, así que sus tres ajustes quedan
    // huérfanos en mdl_config_plugins: se borran para que no confundan a quien revise la
    // configuración. openrouter_api_key se conserva.
    if ($oldversion < 2026092500) {
        unset_config('use_php_plan', 'block_adaptive_ui');
        unset_config('adaptation_url', 'block_adaptive_ui');
        unset_config('adaptation_secret', 'block_adaptive_ui');

        upgrade_block_savepoint(true, 2026092500, 'adaptive_ui');
    }

    return true;
}
