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

defined('MOODLE_INTERNAL') || die();

/**
 * Fase MAPE-K: KNOWLEDGE — datos del reporte docente (report.php).
 *
 * Extraído de report.php en la Fase B.2 (2026-07-22), cierre de la fase. A diferencia de
 * effectiveness_repository.php (ciclo de vida CRUD de una fila por usuario en
 * mdl_block_adaptive_ui_effectiveness), esta clase arma una lectura masiva por curso a través
 * de TRES tablas (local_emotions_log, block_adaptive_ui_effectiveness, course_modules +
 * módulos) para una sola pantalla de reporte — un rol distinto (agregación de reporte, no
 * repositorio de una tabla), de ahí la clase separada en vez de un método más de
 * effectiveness_repository.
 *
 * Devuelve $by_user ya con los 5 agregados por estudiante (total_adapt, activated_n,
 * improved_n, resolved_n, pct_improved) precalculados, y cada fila de 'adaptations' con su
 * propiedad 'improved' (bool|null) ya resuelta — report.php solo lee, no calcula nada dentro
 * del bucle de renderizado (mismo criterio ya aplicado en B.0.2 separando presentación de
 * lógica en showFeedbackBox).
 */
class report_data_provider {

    /** Emociones válidas, para inicializar el conteo por estudiante en cero. */
    private const EMO_KEYS = ['Aburrido', 'Concentrado', 'Confundido', 'Frustrado'];

    /**
     * Arma todos los datos que report.php necesita para renderizar una vista de curso.
     *
     * @param int $courseid      Curso a reportar.
     * @param int $filter_userid Filtrar a un solo estudiante (0 = todos).
     * @param int $filter_days   Ventana de días hacia atrás desde ahora (0 = "Todo", sin límite).
     * @return array {course: stdClass, by_user: array, all_users: array}.
     */
    public static function get_report_data(int $courseid, int $filter_userid, int $filter_days): array {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // $filter_days = 0 → opción "Todo" del filtro de periodo: since = 0 abre las dos
        // consultas de abajo al histórico completo del curso, sin ramificar el WHERE (ambas
        // ya usan timecreated >= :since).
        $since = $filter_days > 0 ? time() - ($filter_days * 86400) : 0;

        $cm_names = self::course_module_names($courseid);

        // ── Historial de emociones (local_emotions_send) ─────────────────────────────────
        // 2026-09-28: antes era una consulta directa a mdl_local_emotions_log con columnas
        // (userid, courseid, cmid, emotion) que local_emotions_send v5 ya no tiene — el reporte
        // fallaba con error SQL. Ahora se pide a la API de ese plugin (data_helper), que sabe
        // dónde viven las detecciones: en sus tablas de Moodle o en la base externa
        // user_model_v5_1 (almacenamiento 'usermodel'), sin que este bloque tenga que saberlo.
        $emotion_records = self::emotion_records($courseid, $since, $filter_userid);

        // ── Historial de adaptaciones + efectividad (mdl_block_adaptive_ui_effectiveness) ──
        // Esta tabla SÍ tiene courseid (a diferencia de prefs) y ya trae el cruce antes/después.
        $eff_where  = 'e.courseid = :courseid AND e.timecreated >= :since';
        $eff_params = ['courseid' => $courseid, 'since' => $since];
        if ($filter_userid > 0) {
            $eff_where           .= ' AND e.userid = :userid';
            $eff_params['userid'] = $filter_userid;
        }
        $effectiveness_records = $DB->get_records_sql(
            "SELECT e.id, e.userid, e.emotion_before, e.confidence_before,
                    e.emotion_after, e.confidence_after, e.adapted, e.timecreated
               FROM {block_adaptive_ui_effectiveness} e
              WHERE {$eff_where}
              ORDER BY e.userid, e.timecreated DESC",
            $eff_params
        );

        $by_user = self::group_by_user($emotion_records, $effectiveness_records, $cm_names);

        // Lista de estudiantes para el filtro (los que tienen algo en este curso, sin filtro de fecha):
        // con detecciones (local_emotions_send) o con adaptaciones registradas por este bloque.
        $userids = array_merge(
            self::emotion_userids($courseid),
            $DB->get_fieldset_select('block_adaptive_ui_effectiveness', 'DISTINCT userid', 'courseid = ?', [$courseid])
        );
        $userids = array_values(array_unique(array_map('intval', $userids)));
        $all_users = $userids
            ? $DB->get_records_list('user', 'id', $userids, 'lastname, firstname', 'id AS userid, firstname, lastname')
            : [];

        return [
            'course'    => $course,
            'by_user'   => $by_user,
            'all_users' => $all_users,
        ];
    }

    /**
     * Detecciones de emoción del curso, en el shape que espera group_by_user()
     * ({id, userid, cmid, emotion, confidence, timecreated, firstname, lastname, email}),
     * leídas a través de la API de local_emotions_send (tablas de Moodle o base externa
     * user_model_v5_1, según su ajuste 'storage'). Sin ese plugin, lista vacía.
     */
    private static function emotion_records(int $courseid, int $since, int $filter_userid): array {
        global $DB;
        $helper = '\local_emotions_send\local\data_helper';
        if (!class_exists($helper)) {
            return [];
        }
        $localcourse = $helper::get_course_by_moodle_id($courseid);
        if (!$localcourse) {
            return [];
        }
        $rows = $helper::get_course_records((int) $localcourse->id, $since, $filter_userid);
        $userids = array_values(array_unique(array_filter(array_map(function ($r) {
            return (int) $r->userid;
        }, $rows))));
        $users = $userids ? $DB->get_records_list('user', 'id', $userids, '', 'id, firstname, lastname, email') : [];
        $records = [];
        foreach ($rows as $r) {
            if (!isset($users[$r->userid])) {
                continue; // detección de un usuario sin cuenta de Moodle (base externa)
            }
            // Activity_context.module = '<modname>_<cmid>' (p. ej. 'quiz_12') o 'general'.
            $cmid = preg_match('/_(\d+)$/', (string) $r->cm_label, $m) ? (int) $m[1] : 0;
            $records[] = (object) [
                'id'          => $r->id,
                'userid'      => (int) $r->userid,
                'cmid'        => $cmid,
                'emotion'     => $r->emotion,
                'confidence'  => (float) $r->confidence,
                'timecreated' => (int) $r->timecreated,
                'firstname'   => $users[$r->userid]->firstname,
                'lastname'    => $users[$r->userid]->lastname,
                'email'       => $users[$r->userid]->email,
            ];
        }
        return $records;
    }

    /** Usuarios de Moodle con detecciones en el curso (vía local_emotions_send). */
    private static function emotion_userids(int $courseid): array {
        $helper = '\local_emotions_send\local\data_helper';
        if (!class_exists($helper)) {
            return [];
        }
        $localcourse = $helper::get_course_by_moodle_id($courseid);
        return $localcourse ? $helper::get_course_userids((int) $localcourse->id) : [];
    }

    /**
     * Borra los datos de adaptación de un curso (botón "Eliminar registros" del reporte).
     *
     * Solo toca las DOS tablas propias de este bloque. mdl_local_emotions_log pertenece a
     * local_emotions_send y se limpia desde el reporte de ESE plugin — cada plugin limpia lo
     * suyo, así que tras esta llamada el reporte sigue mostrando "Historial de emociones
     * detectadas" (correcto, no es un borrado incompleto).
     *
     * Limitación conocida y aceptada: mdl_block_adaptive_ui_prefs NO tiene columna courseid
     * (verificado contra db/install.xml), así que el borrado se acota por los userid que
     * tuvieron actividad de adaptación en ESTE curso. Si uno de esos estudiantes tuviera boosts
     * acumulados en OTRO curso, también se pierden. Aceptable en este entorno de pruebas de un
     * solo curso; documentado aquí para que no se confunda con un bug si el alcance cambia.
     *
     * @param int $courseid Curso a limpiar.
     * @return void
     */
    public static function delete_course_data(int $courseid): void {
        global $DB;

        // Los userid se recogen ANTES de borrar effectiveness — después ya no habría de dónde
        // sacarlos para acotar el borrado de prefs.
        $userids = $DB->get_fieldset_select(
            'block_adaptive_ui_effectiveness',
            'DISTINCT userid',
            'courseid = ?',
            [$courseid]
        );

        $DB->delete_records('block_adaptive_ui_effectiveness', ['courseid' => $courseid]);

        if (!empty($userids)) {
            list($in_sql, $in_params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('block_adaptive_ui_prefs', "userid {$in_sql}", $in_params);
        }
    }

    /**
     * Nombres de course_modules de este curso, para etiquetar el contenido de cada detección
     * ("[QUIZ] Nombre"). Mismo patrón que local_emotions_send.
     *
     * @param int $courseid Curso.
     * @return array Mapa cmid => etiqueta.
     */
    private static function course_module_names(int $courseid): array {
        global $DB;

        $course_cmids = array_keys($DB->get_records('course_modules', ['course' => $courseid], '', 'id'));
        $cm_names = [];
        if (empty($course_cmids)) {
            return $cm_names;
        }

        list($in_sql, $in_params) = $DB->get_in_or_equal($course_cmids, SQL_PARAMS_NAMED);
        $cms = $DB->get_records_sql(
            "SELECT cm.id,
                    COALESCE(p.name, a.name, q.name, r.name, fo.name, bk.name,
                             CAST(cm.id AS CHAR)) AS name,
                    m.name AS modname
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               LEFT JOIN {page}     p  ON (m.name='page'     AND p.id=cm.instance)
               LEFT JOIN {assign}   a  ON (m.name='assign'   AND a.id=cm.instance)
               LEFT JOIN {quiz}     q  ON (m.name='quiz'     AND q.id=cm.instance)
               LEFT JOIN {resource} r  ON (m.name='resource' AND r.id=cm.instance)
               LEFT JOIN {forum}    fo ON (m.name='forum'    AND fo.id=cm.instance)
               LEFT JOIN {book}     bk ON (m.name='book'     AND bk.id=cm.instance)
              WHERE cm.id {$in_sql}",
            $in_params
        );
        foreach ($cms as $cm) {
            $cm_names[$cm->id] = '[' . strtoupper($cm->modname) . '] ' . $cm->name;
        }
        return $cm_names;
    }

    /**
     * Agrupa las dos consultas por estudiante y precalcula, para cada uno, los 5 agregados
     * que antes se calculaban dentro del bucle de renderizado de report.php (total_adapt,
     * activated_n, improved_n, resolved_n, pct_improved) — y la propiedad 'improved' de cada
     * fila de 'adaptations', para que la tabla de historial tampoco tenga que llamar a
     * is_improved() por fila.
     *
     * @param array $emotion_records
     * @param array $effectiveness_records
     * @param array $cm_names
     * @return array Mapa userid => {info, emocounts, detections, adaptations, total_adapt,
     *               activated_n, improved_n, resolved_n, pct_improved}.
     */
    private static function group_by_user(array $emotion_records, array $effectiveness_records, array $cm_names): array {
        global $DB;

        $by_user = [];

        foreach ($emotion_records as $r) {
            $uid = $r->userid;
            if (!isset($by_user[$uid])) {
                $by_user[$uid] = [
                    'info'       => (object) ['firstname' => $r->firstname, 'lastname' => $r->lastname, 'email' => $r->email],
                    'emocounts'  => array_fill_keys(self::EMO_KEYS, 0),
                    'detections' => [],
                    'adaptations'=> [],
                ];
            }
            if (isset($by_user[$uid]['emocounts'][$r->emotion])) {
                $by_user[$uid]['emocounts'][$r->emotion]++;
            }
            $cm_label = '—';
            if ($r->cmid > 0 && isset($cm_names[$r->cmid])) {
                $cm_label = $cm_names[$r->cmid];
            } elseif ($r->cmid == 0) {
                $cm_label = 'Navegación general del curso';
            }
            $by_user[$uid]['detections'][] = [
                'ts' => $r->timecreated, 'emotion' => $r->emotion,
                'confidence' => $r->confidence, 'content' => $cm_label,
            ];
        }

        foreach ($effectiveness_records as $r) {
            $uid = $r->userid;
            if (!isset($by_user[$uid])) {
                // Estudiante con adaptaciones pero sin detecciones en el rango de fechas filtrado
                // (poco común, pero posible en el borde del filtro de días) — traer su nombre.
                $u = $DB->get_record('user', ['id' => $uid], 'firstname, lastname, email');
                $by_user[$uid] = [
                    'info'       => $u ?: (object) ['firstname' => '', 'lastname' => '(usuario ' . $uid . ')', 'email' => ''],
                    'emocounts'  => array_fill_keys(self::EMO_KEYS, 0),
                    'detections' => [],
                    'adaptations'=> [],
                ];
            }
            $r->improved = self::is_improved($r);
            $by_user[$uid]['adaptations'][] = $r;
        }

        foreach ($by_user as $uid => &$udata) {
            $total_adapt = count($udata['adaptations']);
            $activated_n = 0;
            $improved_n  = 0;
            $resolved_n  = 0; // no pendientes (emotion_after != null)
            foreach ($udata['adaptations'] as $a) {
                if ((int) $a->adapted === 1) {
                    $activated_n++;
                }
                if ($a->improved !== null) {
                    $resolved_n++;
                    if ($a->improved) {
                        $improved_n++;
                    }
                }
            }
            $udata['total_adapt']  = $total_adapt;
            $udata['activated_n']  = $activated_n;
            $udata['improved_n']   = $improved_n;
            $udata['resolved_n']   = $resolved_n;
            $udata['pct_improved'] = $resolved_n > 0 ? round($improved_n / $resolved_n * 100) : null;
        }
        unset($udata);

        return $by_user;
    }

    /**
     * Criterio de "mejora" (Fase 7, tarea 3): emotion_after es Concentrado, o la misma emoción
     * negativa con confianza más baja. Cualquier otro caso (incluida una emoción negativa
     * distinta) se cuenta como "sin mejora" — criterio explícito, no ambiguo, tal como se pidió.
     * Retorna null si todavía está pendiente (emotion_after IS NULL).
     *
     * Convertida de función global (report.php) a método privado en la Fase B.2 (2026-07-22).
     */
    private static function is_improved(object $row): ?bool {
        if ($row->emotion_after === null) {
            return null;
        }
        if ($row->emotion_after === 'Concentrado') {
            return true;
        }
        if ($row->emotion_after === $row->emotion_before
            && (float) $row->confidence_after < (float) $row->confidence_before) {
            return true;
        }
        return false;
    }
}
