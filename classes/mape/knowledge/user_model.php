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

use core_course_category;

/**
 * Fase MAPE-K: KNOWLEDGE — Modelo de Usuario (Fase E, 2026-07-28).
 *
 * Implementa los 10 atributos del "Modelo de Usuario" del Diseño Metodológico de la tesis
 * como una clase dedicada, en vez de campos sueltos repartidos por el código — al desplegar en
 * un servidor de pruebas con datos reales, todo el trabajo de conexión queda centralizado aquí.
 *
 * Investigación previa (solo lectura, sesión anterior a esta implementación) confirmó:
 * - $COURSE/$PAGE->course NO reflejan el curso real dentro del flujo de apply_adaptation — se
 *   inicializan como clon de SITE (lib/setup.php:826) y nada en esa cadena de ejecución los
 *   reasigna. Por eso $courseid llega aquí como parámetro EXPLÍCITO (ver Paso 1 de esta fase:
 *   panels.js → apply_adaptation.php → strategy_dispatcher::dispatch() → aquí), nunca leído
 *   de un global ambiente.
 * - mdl_user no tiene columnas de género/edad/nacimiento, y esta instalación tiene CERO campos
 *   de perfil custom configurados (mdl_user_info_field vacía) — confirmado por consulta directa,
 *   no supuesto. De ahí que género/edad/nivel de estudio empiecen con su flag en false: no es
 *   una posición ideológica, es que hoy no hay ninguna fuente de datos que conectar.
 *
 * Cada atributo del array devuelto tiene la forma {available: bool, value: mixed|null} — un
 * atributo con available=false SIEMPRE tiene value=null, nunca un placeholder inventado (ej.
 * nunca "Site home" como nombre de curso cuando no hay curso real). Quien serializa este
 * modelo al prompt (plan_engine.py, Paso 4 de esta fase) debe omitir por completo cualquier
 * atributo con available=false — no debe filtrarse ni como línea vacía.
 */
class user_model {

    // ─────────────────────────────────────────────────────────────────────────
    // FEATURE FLAGS — constantes de código (decisión explícita: no config de admin).
    // Los 5 atributos CONECTADOS (emotion, device, course, programa, idioma) no necesitan
    // flag — están siempre "on" porque su fuente de datos siempre existe (current_language()
    // nunca falla, platform_model/emotion siempre llegan). Estos 4 SÍ lo necesitan porque hoy
    // no tienen ninguna fuente de datos confirmada en esta instalación (ver docstring de
    // clase). Cambiar a true requiere primero confirmar una fuente real — no basta con
    // voltear el flag.
    // ─────────────────────────────────────────────────────────────────────────

    /** Sin fuente nativa en Moodle ni campo de perfil custom configurado en esta instalación. */
    public const ENABLE_NIVEL_ESTUDIO = false;

    /** mdl_user no tiene columna de género; requeriría un campo de perfil custom (no existe). */
    public const ENABLE_GENERO = false;

    /** mdl_user no tiene columna de edad/fecha de nacimiento; mismo motivo que género. */
    public const ENABLE_EDAD = false;

    /** Environment Model — fuera de alcance por decisión ya tomada (ver CLAUDE.md:325-328),
     *  no una omisión de esta fase. Viviría en emotions_backend (iluminación/cámara), que no
     *  se toca. */
    public const ENABLE_ENTORNO_FISICO = false;

    /**
     * Construye el Modelo de Usuario completo (10 atributos) para esta petición.
     *
     * @param int    $userid       Usuario actual ($USER->id del caller).
     * @param int    $courseid     Curso real (0 si no aplica/no disponible) — ver docstring de
     *                             clase sobre por qué no se lee de $COURSE/$PAGE.
     * @param array  $page_context Contexto de página ya decodificado (para extraer
     *                             platform_model — el atributo "device" se REFERENCIA desde
     *                             aquí, no se reimplementa).
     * @param string $emotion      Emoción ya detectada para esta petición — el atributo
     *                             "emotion" se REFERENCIA desde aquí, no se recalcula. Puede
     *                             venir vacía si el caller no la tiene (no debería pasar en la
     *                             práctica, pero no debe romper la construcción del modelo).
     * @return array<string, array{available: bool, value: mixed}> Los 10 atributos, siempre
     *         presentes como claves (aunque available=false) — el modelo queda completo
     *         internamente incluso cuando la mayoría de sus campos no se sirven al LLM.
     */
    public static function build_user_model(
        int $userid, int $courseid, array $page_context, string $emotion = ''
    ): array {
        global $DB;

        $model = [];

        // ── EMOTION — CONECTADO (referenciada, no recalculada) ──────────────────────────────
        $model['emotion'] = [
            'available' => $emotion !== '',
            'value'     => $emotion !== '' ? $emotion : null,
        ];

        // ── DEVICE — CONECTADO (referenciada desde page_context.platform_model, ver
        // page_context_detector.js::detectPlatformContext() — NO se reimplementa aquí) ───────
        $platform = $page_context['platform_model'] ?? null;
        $model['device'] = [
            'available' => is_array($platform) && !empty($platform),
            'value'     => (is_array($platform) && !empty($platform)) ? $platform : null,
        ];

        // ── COURSE — CONECTADO, con guarda explícita contra el curso SITE ───────────────────
        // $courseid=0 (o cualquier id inválido/inexistente) → atributo AUSENTE, nunca se
        // inventa un nombre de curso (ej. nunca "Site home" del curso SITE que $COURSE
        // tendría por defecto en este contexto — ver docstring de clase).
        $course = null;
        if ($courseid > 0) {
            $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname, category');
        }
        $model['course'] = [
            'available' => $course !== false && $course !== null,
            'value'     => $course ? ['fullname' => $course->fullname, 'shortname' => $course->shortname] : null,
        ];

        // ── PROGRAMA (categoría de curso) — CONECTADO, depende de COURSE ────────────────────
        // Sin curso real no hay categoría que leer. Un curso SIN categoría asignada
        // (category=0, o una categoría borrada/oculta) tampoco produce un valor inventado —
        // core_course_category::get(..., IGNORE_MISSING) devuelve null en vez de lanzar
        // excepción, y eso se propaga como available=false.
        $categoria = null;
        if ($course && !empty($course->category)) {
            $cat = core_course_category::get((int) $course->category, IGNORE_MISSING);
            if ($cat) {
                $categoria = $cat->name;
            }
        }
        $model['programa'] = [
            'available' => $categoria !== null,
            'value'     => $categoria,
        ];

        // ── IDIOMA — CONECTADO. current_language() (lib/moodlelib.php) en vez de $USER->lang
        // directo — resuelve la cadena real de fallback (forcelang de sesión → idioma de
        // actividad → idioma de curso → idioma de sesión → $USER->lang → $CFG->lang → 'en') y
        // NUNCA devuelve vacío, confirmado leyendo su código antes de usarla. ─────────────────
        $model['idioma'] = [
            'available' => true,
            'value'     => current_language(),
        ];

        // ── NIVEL DE ESTUDIO — FLAG-OFF ──────────────────────────────────────────────────────
        $model['nivel_estudio'] = [
            'available' => self::ENABLE_NIVEL_ESTUDIO,
            'value'     => null,
        ];

        // ── GÉNERO — FLAG-OFF ─────────────────────────────────────────────────────────────────
        $model['genero'] = [
            'available' => self::ENABLE_GENERO,
            'value'     => null,
        ];

        // ── EDAD — FLAG-OFF ───────────────────────────────────────────────────────────────────
        $model['edad'] = [
            'available' => self::ENABLE_EDAD,
            'value'     => null,
        ];

        // ── ENTORNO FÍSICO — FLAG-OFF ─────────────────────────────────────────────────────────
        $model['entorno_fisico'] = [
            'available' => self::ENABLE_ENTORNO_FISICO,
            'value'     => null,
        ];

        // ── PREFERENCIAS — INERTE esta fase (no gateada por flag: no es "sin fuente", es
        // "con fuente pero deliberadamente no conectada al LLM todavía" — mdl_block_adaptive_ui
        // _prefs/prefs_repository.php SÍ existe y SÍ tiene datos reales, pero solo alimenta el
        // umbral adaptativo de Analyze, nunca el prompt de Plan. Decisión explícita de esta
        // fase, no un flag técnico). $userid queda como parámetro de la firma sin usar
        // todavía — documentado aquí para cuando esto se conecte de verdad (leería
        // mdl_block_adaptive_ui_prefs por $userid, mismo patrón que dominant_emotion_
        // calculator::analyze() ya usa para el boost). ─────────────────────────────────────
        $model['preferencias'] = [
            'available' => false,
            'value'     => null,
        ];

        return $model;
    }
}
