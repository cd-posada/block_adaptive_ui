<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @copyright  2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
//             Politécnico Colombiano Jaime Isaza Cadavid
//             Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
// @author     Miguel Ángel Salazar Rincón
//
// report.php — Fase 7 (Knowledge): reporte docente de emociones + efectividad de las
// adaptaciones. Sigue el mismo patrón de acceso/consulta/presentación que
// local/emotions_send/report.php (curso obligatorio, filtros por estudiante/periodo,
// agrupación por estudiante), con dos diferencias deliberadas:
//   1. Sin Chart.js — este proyecto no tiene esa librería ya disponible sin depender de un
//      CDN externo; una tabla HTML simple es suficiente (decisión explícita de alcance).
//   2. mdl_block_adaptive_ui_prefs NO tiene columna courseid (verificado contra
//      db/install.xml) — el historial de adaptaciones se construye desde
//      mdl_block_adaptive_ui_effectiveness, que sí tiene courseid (añadida en esta misma
//      fase) y ya trae el cruce antes/después en la misma fila.
//
// Desde la Fase B.2 (2026-07-22, cierre de fase): este archivo es solo el shell de página
// (login, permisos, $PAGE, filtros GET, HTML/renderizado). Toda la lectura y agregación de
// datos vive en \block_adaptive_ui\mape\knowledge\report_data_provider::get_report_data() —
// $by_user llega ya con los 5 agregados por estudiante precalculados; el bucle de
// renderizado de abajo solo lee, no calcula nada.

require_once(__DIR__ . '/../../config.php');
require_login();

global $OUTPUT;

$courseid = required_param('courseid', PARAM_INT);

// ── Permisos: capability propia del bloque, evaluada en contexto de CURSO — un docente
// solo ve esto para cursos donde de verdad tiene el rol, no toda la plataforma. ──────────
$context = context_course::instance($courseid);
require_capability('block/adaptive_ui:viewreports', $context);

// ── Borrado de registros ("Eliminar registros", mismo patrón que local/emotions_send) ────
// Va ANTES de leer los datos y antes de $OUTPUT->header(), para que el redirect() sea válido.
// Capability adicional a propósito: block/adaptive_ui:viewreports es de lectura
// (db/access.php, captype 'read'), así que no debe autorizar por sí sola una acción
// destructiva — se exige moodle/course:manageactivities, la misma capability núcleo que ya
// usa el gate de local/emotions_send/report.php, en vez de inventar una capability nueva.
// $courseid es required_param arriba, así que no existe el caso "sin curso → borrar la tabla
// entera" que sí es alcanzable en el reporte de local_emotions_send.
$do_clear = optional_param('clear', 0, PARAM_BOOL);
if ($do_clear) {
    require_sesskey();
    require_capability('moodle/course:manageactivities', $context);
    \block_adaptive_ui\mape\knowledge\report_data_provider::delete_course_data($courseid);
    // delay = null a propósito: como aquí las cabeceras aún no se han enviado, redirect()
    // encola el mensaje con \core\notification::add() y fuerza delay = 0 (redirección por
    // cabecera). Pasar un delay > 0 sería un parámetro muerto. El tipo sí importa: por
    // defecto sería NOTIFY_INFO (azul) y una confirmación de borrado corresponde a verde.
    redirect(
        new moodle_url('/blocks/adaptive_ui/report.php', ['courseid' => $courseid]),
        'Registros de adaptación eliminados.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->set_context($context);
$PAGE->set_url('/blocks/adaptive_ui/report.php', ['courseid' => $courseid]);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('report_title', 'block_adaptive_ui'));
$PAGE->set_heading(get_string('report_heading', 'block_adaptive_ui'));

// ── Filtros ───────────────────────────────────────────────────────────────────────────
$filter_userid = optional_param('userid', 0, PARAM_INT);

// Fuente única del <select> de periodo y de su validación — no dos listas paralelas.
// 0 = "Todo" (sin límite de fecha, ver report_data_provider::get_report_data). La validación
// evita que un ?days=<arbitrario> ensanche la ventana de consulta con un valor que la UI nunca
// ofreció (y que además dejaba el <select> sin ninguna opción marcada).
$period_options = [0 => 'Todo', 1 => 'Hoy', 3 => '3 días', 7 => '7 días',
                   14 => '14 días', 30 => '30 días'];
$filter_days    = optional_param('days', 7, PARAM_INT);
if (!array_key_exists($filter_days, $period_options)) {
    $filter_days = 7;
}

$data      = \block_adaptive_ui\mape\knowledge\report_data_provider::get_report_data($courseid, $filter_userid, $filter_days);
$course    = $data['course'];
$by_user   = $data['by_user'];
$all_users = $data['all_users'];

$emo_colors = [
    'Aburrido' => '#4e79a7', 'Concentrado' => '#59a14f',
    'Confundido' => '#f28e2b', 'Frustrado' => '#e15759',
];

$back_url = new moodle_url('/course/view.php', ['id' => $courseid]);

echo $OUTPUT->header();
?>
<style>
.aui-card    { background:#fff; border:1px solid #e0e0e0; border-radius:10px;
               padding:16px 20px; margin-bottom:16px; box-shadow:0 1px 5px rgba(0,0,0,.05); }
.aui-pill    { background:#f8f9fa; border-radius:8px; padding:10px 14px;
               text-align:center; flex:1; min-width:110px; }
.aui-pill b  { display:block; font-size:1.3em; color:#0d6efd; }
.aui-pill span { display:block; color:#6c757d; font-size:0.76em; margin-top:2px; }
.aui-badge   { display:inline-block; padding:2px 8px; border-radius:4px; color:#fff;
               font-size:0.75em; font-weight:600; white-space:nowrap; }
.aui-table   { width:100%; border-collapse:collapse; font-size:0.82em; margin-top:6px; }
.aui-table th{ background:#f8f9fa; padding:6px 8px; text-align:left;
               border-bottom:2px solid #dee2e6; color:#495057; font-weight:600; }
.aui-table td{ padding:5px 8px; border-bottom:1px solid #f1f3f5; color:#333; }
.aui-avatar  { width:38px; height:38px; border-radius:50%; background:#dee2e6;
               display:flex; align-items:center; justify-content:center;
               font-weight:700; font-size:0.9em; color:#495057; flex-shrink:0; }
.aui-improve-yes { color:#2e7d32; font-weight:600; }
.aui-improve-no  { color:#c62828; font-weight:600; }
.aui-improve-pending { color:#8a8a8a; font-style:italic; }
</style>

<div style="max-width:1100px;margin:0 auto;padding:20px 16px;">

  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
    <div>
      <a href="<?php echo $back_url->out(false); ?>"
         style="font-size:0.83em;color:#6c757d;text-decoration:none;display:inline-block;margin-bottom:6px;">
        &larr; Volver al curso
      </a>
      <h2 style="margin:0 0 3px;font-size:1.3em;"><?php echo get_string('report_heading', 'block_adaptive_ui'); ?></h2>
      <p style="color:#6c757d;margin:0;font-size:0.87em;">
        <?php echo htmlspecialchars($course->fullname); ?>
        &nbsp;&middot;&nbsp;
        <?php echo $filter_days > 0 ? 'Últimos ' . (int) $filter_days . ' días' : 'Todo el periodo'; ?>
      </p>
    </div>
    <?php if (has_capability('moodle/course:manageactivities', $context)): ?>
    <a href="?courseid=<?php echo $courseid; ?>&amp;clear=1&amp;sesskey=<?php echo sesskey(); ?>"
       onclick="return confirm('¿Eliminar todos los registros de adaptaciones de este curso? Esta acción no se puede deshacer.');"
       style="text-decoration:none;background:#dc3545;color:#fff;padding:6px 14px;
              border-radius:6px;font-size:0.83em;display:inline-block;align-self:flex-start;">
      Eliminar registros
    </a>
    <?php endif; ?>
  </div>

  <div style="background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:12px 16px;margin-bottom:20px;">
    <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
      <div>
        <label style="font-size:0.83em;font-weight:600;display:block;margin-bottom:2px;">Estudiante</label>
        <select name="userid" style="border:1px solid #ced4da;border-radius:5px;padding:4px 8px;font-size:0.83em;">
          <option value="0">Todos</option>
          <?php foreach ($all_users as $u): ?>
          <option value="<?php echo (int) $u->userid; ?>" <?php echo ($filter_userid == $u->userid) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($u->lastname . ', ' . $u->firstname); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-size:0.83em;font-weight:600;display:block;margin-bottom:2px;">Periodo</label>
        <select name="days" style="border:1px solid #ced4da;border-radius:5px;padding:4px 8px;font-size:0.83em;">
          <?php foreach ($period_options as $d => $lbl): ?>
          <option value="<?php echo $d; ?>" <?php echo ($filter_days == $d) ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <button type="submit" style="background:#0d6efd;color:#fff;border:none;border-radius:6px;padding:6px 16px;cursor:pointer;font-size:0.83em;">
          Aplicar
        </button>
      </div>
    </form>
  </div>

  <?php if (empty($by_user)): ?>
  <div style="background:#e8f4fd;border-left:4px solid #0d6efd;padding:12px 16px;border-radius:6px;color:#31708f;">
    <?php echo $filter_days > 0
        ? 'No se encontraron registros para el periodo seleccionado.'
        : 'No se encontraron registros en este curso.'; ?>
  </div>
  <?php else: ?>

  <h3 style="font-size:0.93em;font-weight:700;color:#333;margin:0 0 12px;">Detalle por estudiante</h3>

  <?php foreach ($by_user as $uid => $udata):
      $info     = $udata['info'];
      $initials = strtoupper(mb_substr($info->firstname, 0, 1) . mb_substr($info->lastname ?: '?', 0, 1));

      // Los 5 agregados ya llegan precalculados desde report_data_provider::get_report_data().
      $total_adapt  = $udata['total_adapt'];
      $activated_n  = $udata['activated_n'];
      $resolved_n   = $udata['resolved_n'];
      $pct_improved = $udata['pct_improved'];
  ?>
  <div class="aui-card">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
      <div class="aui-avatar"><?php echo htmlspecialchars($initials); ?></div>
      <div style="flex:1;">
        <div style="font-weight:700;font-size:0.98em;color:#212529;">
          <?php echo htmlspecialchars(($info->lastname ?: '') . ', ' . ($info->firstname ?: '')); ?>
        </div>
        <div style="color:#6c757d;font-size:0.78em;"><?php echo htmlspecialchars($info->email ?? ''); ?></div>
      </div>
    </div>

    <!-- Métricas agregadas del estudiante -->
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
      <div class="aui-pill"><b><?php echo array_sum($udata['emocounts']); ?></b><span>Detecciones de emoción</span></div>
      <div class="aui-pill"><b><?php echo $total_adapt; ?></b><span>Adaptaciones (activadas + cerradas)</span></div>
      <div class="aui-pill"><b><?php echo $activated_n; ?> / <?php echo $total_adapt; ?></b><span>Activadas por el estudiante</span></div>
      <div class="aui-pill">
        <b><?php echo $pct_improved !== null ? $pct_improved . '%' : '—'; ?></b>
        <span>Mejoró tras la adaptación<?php echo $resolved_n < $total_adapt ? ' (' . ($total_adapt - $resolved_n) . ' pendiente(s))' : ''; ?></span>
      </div>
    </div>

    <?php if (!empty($udata['adaptations'])): ?>
    <div style="font-size:0.78em;font-weight:600;color:#6c757d;margin-bottom:5px;">
      Historial de adaptaciones (antes → después)
    </div>
    <div style="overflow-x:auto;margin-bottom:14px;">
      <table class="aui-table">
        <thead>
          <tr>
            <th>Fecha y hora</th><th>Decisión</th><th>Antes</th><th>Después</th><th>¿Mejoró?</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($udata['adaptations'] as $a):
            // 'improved' ya viene resuelto por report_data_provider — solo se traduce a
            // etiqueta/clase CSS de presentación, no se recalcula nada de negocio aquí.
            $imp = $a->improved;
            $imp_label = $imp === null ? 'Pendiente' : ($imp ? 'Sí' : 'No');
            $imp_class = $imp === null ? 'aui-improve-pending' : ($imp ? 'aui-improve-yes' : 'aui-improve-no');
            $before_col = $emo_colors[$a->emotion_before] ?? '#aaa';
            $after_col  = $a->emotion_after ? ($emo_colors[$a->emotion_after] ?? '#aaa') : '#ccc';
        ?>
          <tr>
            <td><?php echo date('d/m/Y H:i:s', $a->timecreated); ?></td>
            <td><?php echo ((int) $a->adapted === 1) ? 'Activó cambios' : 'Cerró / rechazó'; ?></td>
            <td>
              <span class="aui-badge" style="background:<?php echo $before_col; ?>;">
                <?php echo htmlspecialchars($a->emotion_before); ?>
              </span>
              (<?php echo round($a->confidence_before * 100); ?>%)
            </td>
            <td>
              <?php if ($a->emotion_after): ?>
              <span class="aui-badge" style="background:<?php echo $after_col; ?>;">
                <?php echo htmlspecialchars($a->emotion_after); ?>
              </span>
              (<?php echo round($a->confidence_after * 100); ?>%)
              <?php else: ?>
              <span style="color:#8a8a8a;font-style:italic;">pendiente</span>
              <?php endif; ?>
            </td>
            <td class="<?php echo $imp_class; ?>"><?php echo $imp_label; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($udata['detections'])): ?>
    <div style="font-size:0.78em;font-weight:600;color:#6c757d;margin-bottom:5px;">
      Historial de emociones detectadas
    </div>
    <div style="overflow-x:auto;">
      <table class="aui-table">
        <thead><tr><th>Fecha y hora</th><th>Contenido</th><th>Emoción</th><th>Confianza</th></tr></thead>
        <tbody>
        <?php
        $shown = 0;
        foreach ($udata['detections'] as $det):
            if ($shown >= 20) {
                break;
            }
            $col = $emo_colors[$det['emotion']] ?? '#aaa';
            $shown++;
        ?>
          <tr>
            <td><?php echo date('d/m/Y H:i:s', $det['ts']); ?></td>
            <td><?php echo htmlspecialchars($det['content']); ?></td>
            <td><span class="aui-badge" style="background:<?php echo $col; ?>;"><?php echo htmlspecialchars($det['emotion']); ?></span></td>
            <td><?php echo round($det['confidence'] * 100); ?>%</td>
          </tr>
        <?php endforeach; ?>
        <?php if (count($udata['detections']) > 20): ?>
          <tr><td colspan="4" style="color:#6c757d;font-style:italic;">Mostrando 20 de <?php echo count($udata['detections']); ?> detecciones.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <?php endif; ?>

  <div style="margin-top:20px;">
    <a href="<?php echo $back_url->out(false); ?>"
       style="display:inline-block;background:#6c757d;color:#fff;text-decoration:none;padding:7px 18px;border-radius:6px;font-size:0.85em;">
      &larr; Volver al curso
    </a>
  </div>

</div>
<?php
echo $OUTPUT->footer();
