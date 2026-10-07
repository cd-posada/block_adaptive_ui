<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

namespace block_adaptive_ui;

defined('MOODLE_INTERNAL') || die();

/**
 * Activa la adaptacion en TODAS las paginas de un curso que tenga el bloque, aunque el bloque
 * no se dibuje en ellas. Casos tipicos:
 *  - Intento de cuestionario con "Mostrar bloques durante los intentos" desactivado (el valor
 *    por defecto de Moodle): attempt.php oculta todos los bloques del curso.
 *  - Bloque agregado con "Mostrar en: solo la portada del curso".
 * En esas paginas se registra el mismo modulo adaptive_monitor (sin el interruptor visual del
 * bloque, que adaptive_monitor ya tolera). Si el bloque se dibujo, no se hace nada.
 */
class hook_callbacks {

    /**
     * true cuando adaptive_monitor ya se registro en esta pagina: lo marca get_content() del
     * bloque (block_adaptive_ui.php) cuando el bloque se dibuja, o este hook. Vive aqui (y no
     * en la clase del bloque) porque esta clase se autocarga en cualquier pagina.
     * @var bool
     */
    public static $monitorregistered = false;

    /** Layouts donde no tiene sentido adaptar (ventanas emergentes, impresion, redirecciones). */
    const SKIP_LAYOUTS = ['embedded', 'popup', 'print', 'redirect', 'maintenance', 'frametop'];

    public static function before_footer(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $DB;

        if (self::$monitorregistered || during_initial_install()
                || !isloggedin() || isguestuser()) {
            return;
        }
        $course = $PAGE->course ?? null;
        if (!$course || (int) $course->id === SITEID || in_array($PAGE->pagelayout, self::SKIP_LAYOUTS, true)) {
            return;
        }
        try {
            $coursectx = \context_course::instance($course->id, IGNORE_MISSING);
            if (!$coursectx || !$DB->record_exists('block_instances',
                    ['blockname' => 'adaptive_ui', 'parentcontextid' => $coursectx->id])) {
                return;
            }
            $PAGE->requires->js_call_amd('block_adaptive_ui/adaptive_monitor', 'init', [(int) $course->id]);
            self::$monitorregistered = true;
        } catch (\Throwable $e) {
            debugging('block_adaptive_ui: before_footer: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
