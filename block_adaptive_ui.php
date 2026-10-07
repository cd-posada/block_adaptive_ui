<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @copyright  2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
//             Politécnico Colombiano Jaime Isaza Cadavid
//             Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
// @author     Miguel Ángel Salazar Rincón

/**
 * Clase principal del bloque block_adaptive_ui.
 *
 * Implementa la fase Execute del ciclo MAPE-K: inyecta el módulo AMD
 * adaptive_monitor.js en la página, que se encarga de hacer polling al
 * web service y aplicar los CSS custom properties según la emoción detectada.
 */
class block_adaptive_ui extends block_base {

    /**
     * Al agregar el bloque a un curso, queda configurado para mostrarse en TODAS las paginas
     * del curso (actividades incluidas), no solo en la portada: "Mostrar en: cualquier pagina"
     * (pagetypepattern '*') y "en contextos hijos" (showinsubcontexts). El docente puede
     * cambiarlo despues en "Configurar bloque".
     */
    public function instance_create() {
        global $DB;
        $parent = \context::instance_by_id($this->instance->parentcontextid, IGNORE_MISSING);
        if ($parent && $parent->contextlevel == CONTEXT_COURSE) {
            $DB->update_record('block_instances', (object) [
                'id'                => $this->instance->id,
                'showinsubcontexts' => 1,
                'pagetypepattern'   => '*',
            ]);
        }
        return true;
    }

    /**
     * Inicialización del bloque: establece el título desde las cadenas de idioma.
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_adaptive_ui');
    }

    /**
     * El bloque puede aparecer en cualquier contexto de página Moodle.
     */
    public function applicable_formats(): array {
        return ['all' => true];
    }

    /**
     * Solo una instancia del bloque por página es suficiente.
     */
    public function instance_allow_multiple(): bool {
        return false;
    }

    /**
     * Este bloque tiene settings.php (openrouter_api_key) — sin esto, block_base::has_config() por defecto devuelve false y
     * Moodle nunca muestra el enlace "Settings" en Manage blocks ni lee settings.php.
     */
    public function has_config(): bool {
        return true;
    }

    /**
     * Genera el contenido del bloque y registra el módulo AMD.
     *
     * El bloque es intencionalmente discreto: muestra únicamente un pequeño
     * indicador de estado (punto de color) y el label de la emoción actual.
     * El verdadero trabajo ocurre en adaptive_monitor.js (invisible para el usuario).
     *
     * @return stdClass|null Objeto de contenido del bloque, o null si no aplica.
     */
    public function get_content(): ?stdClass {
        global $PAGE, $USER;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->footer = '';

        // Solo inyectar para usuarios logueados que no sean guest.
        if (!isloggedin() || isguestuser()) {
            $this->content->text = '';
            return $this->content;
        }

        // Registrar el módulo AMD — Moodle lo inyectará antes de </body>.
        // adaptive_monitor.js es quien ejecuta el ciclo MAPE-K completo.
        // courseid del curso donde vive el bloque (Fase 7 — Knowledge/reporte docente):
        // se necesita para poblar block_adaptive_ui_effectiveness.courseid, ya que
        // mdl_block_adaptive_ui_prefs no tiene esa columna. 0 si no hay curso real (ej.
        // Dashboard personal, SITEID).
        $courseid = ($this->page->course && $this->page->course->id != SITEID)
            ? (int) $this->page->course->id
            : 0;
        $PAGE->requires->js_call_amd('block_adaptive_ui/adaptive_monitor', 'init', [$courseid]);
        \block_adaptive_ui\hook_callbacks::$monitorregistered = true;

        // Contenido visible: toggle on/off + indicador de estado.
        $toggleInput  = \html_writer::empty_tag('input', [
            'type'    => 'checkbox',
            'id'      => 'adaptive-ui-toggle',
            'checked' => 'checked',
        ]);
        $toggleSlider = \html_writer::span('', 'adaptive-ui-toggle-slider');
        $toggleLabel  = \html_writer::tag('label', $toggleInput . $toggleSlider, [
            'class' => 'adaptive-ui-toggle-label',
            'for'   => 'adaptive-ui-toggle',
            'title' => 'Activar / pausar adaptación visual',
        ]);

        $label = \html_writer::span(
            get_string('status_active', 'block_adaptive_ui'),
            'adaptive-ui-label',
            ['id' => 'adaptive-ui-label']
        );

        $this->content->text = \html_writer::div(
            $toggleLabel . ' ' . $label,
            'adaptive-ui-status'
        );

        // Enlace al reporte docente (Fase 7) — solo visible si el usuario tiene la
        // capability en el CONTEXTO DE CURSO (docente/manager), no en el contexto propio
        // del bloque ($this->context es CONTEXT_BLOCK) — misma capability y mismo contexto
        // que evalúa report.php, para que "se ve el enlace" y "se puede entrar" coincidan.
        if ($courseid > 0) {
            $course_context = \context_course::instance($courseid, IGNORE_MISSING);
            if ($course_context && has_capability('block/adaptive_ui:viewreports', $course_context)) {
                $report_url = new \moodle_url('/blocks/adaptive_ui/report.php', ['courseid' => $courseid]);
                $this->content->footer = \html_writer::link(
                    $report_url,
                    get_string('report_title', 'block_adaptive_ui'),
                    ['style' => 'font-size:0.8em;']
                );
            }
        }

        return $this->content;
    }

    /**
     * Rol ARIA del bloque para accesibilidad.
     */
    public function get_aria_role(): string {
        return 'complementary';
    }
}
