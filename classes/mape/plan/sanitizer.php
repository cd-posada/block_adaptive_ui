<?php
// This file is part of the Adaptive UI block for Moodle.
//
// @package    block_adaptive_ui
// @copyright  2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
//             Politécnico Colombiano Jaime Isaza Cadavid
//             Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
// @author     Miguel Ángel Salazar Rincón

namespace block_adaptive_ui\mape\plan;

defined('MOODLE_INTERNAL') || die();

/**
 * Fase D (migración a PHP, 2026-08-01) — puerto literal de adaptation_service/sanitizer.py.
 * Whitelist de seguridad y sanitización de la salida del LLM: valida QUÉ es sintácticamente
 * permitido (selectores/acciones/propiedades reales de Moodle 4.4 Boost) antes de ejecutar
 * nada de lo que devuelve el LLM. BLOQUE A (PASO 2): solo constantes/whitelists, sin lógica de
 * validación todavía — ver PASO 0 (inventario) y PASO 1 (infraestructura PHPUnit) para el
 * contexto completo de esta migración.
 *
 * Tres hallazgos de sintaxis PCRE vs. Python `re` (PASO 0, punto 8) que rigen TODO este
 * archivo, documentados aquí una sola vez en vez de repetidos en cada patrón:
 * 1. preg_match() devuelve int|false, nunca bool — cada validación debe comparar
 *    `=== 1` explícito, nunca truthy/falsy (un `false` de error de compilación del regex
 *    se confundiría silenciosamente con "no matcheó"). Aplica desde BLOQUE B en adelante.
 * 2. preg_match() con `null` como sujeto está deprecado en PHP 8.1+ y será TypeError en
 *    PHP 9 — cada validación necesita `is_string($value)` antes de llamar preg_match(),
 *    igual que Python guarda con `isinstance(value, str)`. Aplica desde BLOQUE B.
 * 3. \d/\s/\w SIN el modificador `u` — decisión deliberada, documentada aquí igual que un
 *    override de umbral: Python 3 `re` sobre `str` es Unicode-aware por defecto para
 *    \d/\s/\w (nunca se pasa re.ASCII en sanitizer.py), PCRE sin `u` es ASCII-only por
 *    defecto. Portar SIN `u` no rompe ningún caso de uso real (los valores esperados del
 *    LLM son siempre ASCII: dígitos, "rem"/"em"/"px", espacios normales dentro de un
 *    linear-gradient) y de hecho ENDURECE la validación (rechaza dígitos Unicode "exóticos"
 *    que Python hoy aceptaría) — dirección seguridad-primero, correcta para un sanitizador.
 *    Si algún día se necesita paridad exacta con Python (no solo "al menos igual de
 *    estricto"), revisar esta decisión explícitamente, no agregar `u` sin pensarlo.
 */
class sanitizer {

    // ─────────────────────────────────────────────────────────────────────────
    // WHITELIST DE SEGURIDAD — selectores reales de Moodle 4.4 Boost
    // ─────────────────────────────────────────────────────────────────────────
    // Puerto literal de STATIC_SELECTOR_GATES (sanitizer.py) — 15 entradas, cada selector
    // estático asociado a la clave de page_context.selectors que debe ser true para que el
    // LLM pueda usarlo (null = siempre disponible).
    public const STATIC_SELECTOR_GATES = [
        'body' => null,
        '.drawer' => 'has_sidebar_pre',
        "[data-region='blocks-column'] .block:not(.block_adaptive_ui)" => 'has_sidebar_post',
        '.course-content .section:not(.current)' => 'has_course_sections',
        "#quiz-timer, .quiz-timer, [data-region='quiz-timer']" => 'has_quiz_timer',
        '.mod_quiz-nav-block, [data-region=\'quiz-navigation\'], .qn_buttons' => 'has_quiz_nav',
        '.mod_quiz-progress' => 'has_quiz_progress',
        '.outcome, .comment' => 'has_quiz_outcome',
        '.questionflag' => 'has_question_flag',
        '.grade' => 'has_grade_display',
        '.gradingform' => 'has_grade_display',
        '.helptooltip' => null,
        '.btn-primary' => 'has_btn_primary',
        '.activity-item' => 'has_course_activities',
        '.activity-item:first-child' => 'has_course_activities',
    ];

    // Selectores dinámicos (data-adaptive-id="N") — patrón exacto, ver hallazgo 3 (\d sin `u`).
    public const DYNAMIC_SELECTOR_PATTERN = '/^\[data-adaptive-id="\d+"\]$/';

    // Tope defensivo del lado servidor — debe coincidir con MAX_DYNAMIC_TOTAL en
    // content_scanner.js (el tope GLOBAL post-round-robin, no la suma de topes por rol).
    public const MAX_DYNAMIC_ELEMENTS = 50;

    public const MAX_DOM_ACTIONS = 30;

    // 11 originales + 2 de C.3 (adaptive-script-heading/adaptive-display-heading) = 13.
    public const ADAPTIVE_CLASS_WHITELIST = [
        'adaptive-truncate', 'adaptive-engagement-glow', 'adaptive-help-expanded',
        'adaptive-minimal-icons', 'adaptive-zen-layout', 'adaptive-card-layout',
        'adaptive-aburrido-icons', 'adaptive-engaged-pulse', 'adaptive-engaged-pulse-soft',
        'adaptive-hope-indicator', 'adaptive-focused-content',
        'adaptive-script-heading', 'adaptive-display-heading',
    ];

    /**
     * Clases del whitelist que NUNCA deben aplicarse sobre "body" (2026-08-08).
     *
     * Su regla CSS actúa sobre el elemento MISMO, no sobre descendientes, así que en "body"
     * afectan a la página entera. `adaptive-truncate` la recortaría a 3 líneas con
     * `max-height: 4.8em; overflow: hidden; -webkit-line-clamp: 3` — está pensada para UN párrafo
     * concreto del inventario dinámico, nunca para el documento.
     *
     * No es hipotético: la clase hermana `adaptive-help-expanded` SÍ acabó en "body" en una
     * adaptación real de Confundido y dejó un recuadro de 1px alrededor de la página (la "línea
     * negra" reportada), la colapsó con `display: inline-flex` y encogió todo el texto un 15%.
     * Esa se corrigió acotando su CSS a descendientes; `adaptive-truncate` no puede acotarse así
     * porque su destino legítimo es un elemento individual, de modo que la garantía va aquí.
     *
     * El resto del whitelist ya está acotado con `body.<clase> <descendiente>` y es seguro:
     * auditadas las 13 una por una.
     */
    public const BODY_FORBIDDEN_CLASSES = [
        'adaptive-truncate',
    ];

    // 11 originales + fontWeight/transform de C.4 = 13.
    public const STYLE_PROPERTY_WHITELIST = [
        'fontFamily', 'fontSize', 'lineHeight', 'letterSpacing', 'backgroundColor',
        'borderColor', 'transition', 'maxWidth', 'margin', 'padding', 'backgroundImage',
        'fontWeight', 'transform',
    ];

    // Whitelist CERRADA (no un patrón libre como colores/tamaños) — 4 valores exactos.
    public const FONT_FAMILY_WHITELIST = [
        'Inter, sans-serif', 'Poppins, sans-serif', 'Georgia, serif', 'system-ui, sans-serif',
    ];

    // Único valor de "value" aceptado cuando property == "fontFamily" en un set_style.
    public const FONT_FAMILY_VAR_REFERENCE = 'var(--emotion-font-family)';

    // 6 de las 7 claves de CSS_VAR_VALUE_PATTERNS (Python) — literales, sin construcción en
    // tiempo de ejecución. La 7ª (--emotion-font-family) NO puede vivir en un const de PHP
    // porque depende de preg_quote() sobre FONT_FAMILY_WHITELIST — ver css_var_value_patterns()
    // más abajo, que la agrega. Ver hallazgo 3 arriba para el \d sin `u` en las 3 últimas.
    public const CSS_VAR_VALUE_PATTERNS_STATIC = [
        '--emotion-bg'             => '/^#[0-9A-Fa-f]{6}$/',
        '--emotion-primary'        => '/^#[0-9A-Fa-f]{6}$/',
        '--emotion-accent-warm'    => '/^#[0-9A-Fa-f]{6}$/',
        '--emotion-text-size'      => '/^\d+(\.\d+)?(rem|em|px)$/',
        '--emotion-line-height'    => '/^\d+(\.\d+)?$/',
        '--emotion-letter-spacing' => '/^(normal|\d+(\.\d+)?(rem|em|px))$/',
    ];

    /**
     * Las 7 claves de CSS_VAR_VALUE_PATTERNS (Python), reconstruidas en cada llamada porque
     * --emotion-font-family depende de preg_quote() sobre FONT_FAMILY_WHITELIST — PHP no
     * permite llamadas a función dentro de un `const`. Delimitador '/' explícito en
     * preg_quote() (2º argumento) por si alguna vez una fuente de FONT_FAMILY_WHITELIST
     * incluyera ese carácter — ninguna lo hace hoy, pero no depender de eso en silencio.
     */
    public static function css_var_value_patterns(): array {
        $escaped = array_map(
            fn(string $font): string => preg_quote($font, '/'),
            self::FONT_FAMILY_WHITELIST
        );
        return self::CSS_VAR_VALUE_PATTERNS_STATIC + [
            '--emotion-font-family' => '/^(' . implode('|', $escaped) . ')$/',
        ];
    }

    // C.4 — patrón de FORMA cerrado por propiedad (backgroundImage/fontWeight/transform),
    // además del denylist genérico (STYLE_VALUE_DENYLIST), nunca en su lugar. Ver hallazgo 3
    // para el \s (backgroundImage) y \d (transform) sin `u`.
    public const STYLE_VALUE_PATTERNS = [
        // Un solo linear-gradient(), sin url() en ningún lado — charset excluye "(" y ")"
        // a propósito, no se puede cerrar el gradiente antes de tiempo y colar una segunda
        // capa "url(...)" separada por coma.
        'backgroundImage' => '/^linear-gradient\([a-zA-Z0-9#%,.\s-]*\)$/',
        // Escala estándar de peso de fuente web — 900 excluido a propósito (fuera de
        // alcance de C.4), "bold"/"bolder" también quedan fuera.
        'fontWeight' => '/^(400|500|600|700|800)$/',
        // SOLO scale(), rango [1.0, 1.3] inclusive — patrón EXACTO de sanitizer.py, sin
        // modificar un carácter. Rechaza scaleX()/translate()/rotate()/matrix(), scale(x,y),
        // y cualquier valor fuera de rango, solo por la FORMA del string (sin comparar
        // números): el primer decimal 0-2 admite cualquier dígito extra (1.29999...
        // sigue <1.3); el decimal 3 solo admite ceros de más detrás (1.3, 1.30, 1.300).
        'transform' => '/^scale\(1(\.(?:[0-2]\d*|3(?:0*)))?\)$/',
    ];

    // Denylist genérico anti-XSS/anti-CSS-expression — /i porque Python usa re.IGNORECASE.
    public const STYLE_VALUE_DENYLIST = '/javascript:|expression\(|<|>/i';
    public const MAX_STYLE_VALUE_LEN = 120;

    // rationale — sin HTML, con tope de longitud. También /i (mismo re.IGNORECASE de Python).
    public const RATIONALE_DENYLIST = '/<|>/i';
    public const MAX_RATIONALE_LEN = 140;

    // empathic_message — corte duro, se trunca en vez de descartar (ver sanitize() en
    // BLOQUE C, no se toca en este bloque).
    public const MAX_EMPATHIC_MESSAGE_LEN = 250;

    // Recurso sugerido junto con "show_resources" — "forum"/"glossary"/"tutor_virtual".
    public const SUGGESTED_RESOURCE_WHITELIST = ['forum', 'glossary', 'tutor_virtual'];

    // Ritmo de la transición — etapa 5 ("Transition") del modelo ISATINE. A diferencia de
    // los otros dos escalares (suggested_resource, empathic_message), este campo NUNCA se
    // descarta a null: un valor no reconocido degrada a TRANSITION_DEFAULT, de modo que una
    // respuesta mala del LLM produce exactamente el comportamiento histórico del plugin en vez
    // de un estado sin definir. Las duraciones reales viven en amd/src/mape/execute/
    // transition_manager.js (MODES) — acá solo se valida el enum, nunca milisegundos.
    public const TRANSITION_WHITELIST = ['inmediata', 'rapida', 'gradual', 'suave', 'escalonada'];
    public const TRANSITION_DEFAULT = 'gradual';

    // Modos vetados para Concentrado: la emoción positiva no debe robar atención con un cambio
    // que se despliega lentamente ante el estudiante mientras trabaja. Mismo espíritu que
    // ethics_check::rule_concentrado_never_hides, pero implementado ACÁ y no allá a propósito:
    // ethics_check es TODO O NADA (descarta la respuesta completa), y tumbar 20 dom_actions
    // correctas porque el modelo eligió un fundido lento es desproporcionado. El sanitizador
    // degrada campo a campo, que es la severidad que corresponde a un fallo de presentación.
    public const TRANSITION_FORBIDDEN_FOR_CONCENTRADO = ['suave', 'escalonada'];

    // ─────────────────────────────────────────────────────────────────────────
    // BLOQUE B (PASO 2) — validación de selectores: nivel dinámico + nivel fijo
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Elementos de contenido dinámico válidos para ESTA petición — puerto de
     * sanitizer.py::valid_dynamic_elements(). Corta a MAX_DYNAMIC_ELEMENTS ANTES de filtrar
     * (mismo orden que Python: slice primero, filtra después) y descarta cualquier elemento
     * que no sea un array con "selector" string matcheando DYNAMIC_SELECTOR_PATTERN.
     *
     * Nota de paridad (no bloqueante): Python distingue dict de list vía isinstance(el, dict)
     * — PHP no tiene esa distinción de tipos tras json_decode(..., true) (ambos son array).
     * No se compensa con array_is_list() aquí porque el resultado converge igual: un elemento
     * mal formado (ej. una lista plana en vez de un dict) simplemente no tendrá una clave
     * "selector" válida y se descarta por esa vía, no por el chequeo de tipo.
     *
     * A diferencia de Python, NO emite un aviso si $page_context['elements'] trae más de
     * MAX_DYNAMIC_ELEMENTS — ese log.warning() de sanitizer.py es observabilidad, no
     * seguridad (el corte es idéntico con o sin el aviso). Pendiente documentado, no
     * bloqueante para este bloque.
     */
    public static function valid_dynamic_elements(array $page_context): array {
        $elements = $page_context['elements'] ?? [];
        if (!is_array($elements)) {
            $elements = [];
        }

        $sliced = array_slice($elements, 0, self::MAX_DYNAMIC_ELEMENTS);

        $valid = [];
        foreach ($sliced as $el) {
            if (!is_array($el)) {
                continue;
            }
            $selector = $el['selector'] ?? null;
            if (is_string($selector) && preg_match(self::DYNAMIC_SELECTOR_PATTERN, $selector) === 1) {
                $valid[] = $el;
            }
        }
        return $valid;
    }

    /**
     * Selectores (strings) de los elementos dinámicos válidos — puerto de
     * sanitizer.py::_dynamic_element_selectors() (privada allá también).
     */
    private static function dynamic_element_selectors(array $page_context): array {
        $selectors = array_column(self::valid_dynamic_elements($page_context), 'selector');
        return array_values(array_unique($selectors));
    }

    /**
     * Nivel FIJO — chrome/estructura de Moodle (STATIC_SELECTOR_GATES), sin cambios. Puerto
     * de sanitizer.py::static_selectors().
     */
    public static function static_selectors(array $page_context): array {
        $sel = $page_context['selectors'] ?? [];
        if (!is_array($sel)) {
            $sel = [];
        }

        $result = [];
        foreach (self::STATIC_SELECTOR_GATES as $selector => $gate) {
            if ($gate === null || !empty($sel[$gate])) {
                $result[] = $selector;
            }
        }
        return $result;
    }

    /**
     * Selectores que el LLM puede usar dado el contexto actual de la página: nivel FIJO
     * unión nivel DINÁMICO. Puerto de sanitizer.py::available_selectors().
     */
    public static function available_selectors(array $page_context): array {
        return array_values(array_unique(array_merge(
            self::static_selectors($page_context),
            self::dynamic_element_selectors($page_context)
        )));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BLOQUE C1 (PASO 2) — validación de UNA sola dom_action
    // ─────────────────────────────────────────────────────────────────────────

    // Acciones DOM soportadas — equivalente al `DOM_ACTIONS = Literal[...]` de sanitizer.py
    // (allá lo hace cumplir pydantic al validar RawDomAction; aquí lo hace este array). No
    // estaba en el inventario de constantes de BLOQUE A porque no era una whitelist de
    // *datos* sino el enum de tipo que valida la FORMA de una acción — pertenece a este
    // bloque, donde por primera vez se valida una acción completa.
    public const DOM_ACTIONS = ['hide', 'show', 'set_style', 'add_class', 'remove_class', 'show_resources'];

    /**
     * Envoltorio de preg_match() que aplica el hallazgo 1 del PASO 0 en un solo lugar:
     * preg_match() devuelve int|false, nunca bool — comparar `=== 1` explícito, y fallar
     * CERRADO (tratar como "no matchea") si el patrón mismo no compila, en vez de confundir
     * silenciosamente un error de regex con "no matcheó". Con los patrones de este archivo
     * (todos const, ya probados) un `false` no debería ocurrir nunca en la práctica — el
     * guard existe para no depender de esa suposición.
     */
    private static function matches(string $pattern, string $value): bool {
        $result = preg_match($pattern, $value);
        return $result === 1;
    }

    /**
     * Valida y sanitiza UNA sola dom_action cruda del LLM contra $page_context. Devuelve el
     * array limpio (selector/action[/property][/value]) si sobrevive, o null si se descarta
     * — puerto de la unidad de decisión que en sanitizer.py vive inline dentro del loop de
     * _sanitize_dom_actions() (BLOQUE C1: sin "rationale" todavía, eso es BLOQUE C2).
     *
     * Guard is_string() (hallazgo 2, PASO 0): selector/action deben ser string para que la
     * acción tenga CUALQUIER chance de sobrevivir — si no, se descarta de inmediato, igual
     * que pydantic.ValidationError en RawDomAction(**item) del lado Python (que también
     * descarta el item entero ante un tipo incorrecto, vía `continue` en el loop original).
     */
    public static function sanitize_dom_action(array $raw_action, array $page_context): ?array {
        $selector = $raw_action['selector'] ?? null;
        $action   = $raw_action['action'] ?? null;
        $property = $raw_action['property'] ?? null;
        $value    = $raw_action['value'] ?? null;

        if (!is_string($selector) || !is_string($action)) {
            return null;
        }
        if (!in_array($action, self::DOM_ACTIONS, true)) {
            return null;
        }
        if ($property !== null && !is_string($property)) {
            return null;
        }
        if ($value !== null && !is_string($value)) {
            return null;
        }

        if (!in_array($selector, self::available_selectors($page_context), true)) {
            return null;
        }

        if ($action === 'set_style') {
            // "not act.value" en Python es True solo para None o "" (una cadena "0" es
            // TRUTHY en Python) — empty($value) de PHP trataría "0" como vacío también,
            // divergiendo de Python para ese caso borde. Comparación explícita en su lugar.
            if (!in_array($property, self::STYLE_PROPERTY_WHITELIST, true)
                    || $value === null || $value === '') {
                return null;
            }
            if (strlen($value) > self::MAX_STYLE_VALUE_LEN || self::matches(self::STYLE_VALUE_DENYLIST, $value)) {
                return null;
            }
            // fontFamily solo puede referenciar la variable ya validada contra la whitelist
            // cerrada de fuentes — nunca un nombre de fuente literal (evita sortear
            // FONT_FAMILY_WHITELIST coloreando la fuente directo vía set_style).
            if ($property === 'fontFamily' && $value !== self::FONT_FAMILY_VAR_REFERENCE) {
                return null;
            }
            // C.4 — patrón de FORMA cerrado por propiedad (backgroundImage/fontWeight/
            // transform), además del denylist genérico de arriba, nunca en su lugar.
            $value_pattern = self::STYLE_VALUE_PATTERNS[$property] ?? null;
            if ($value_pattern !== null && !self::matches($value_pattern, $value)) {
                return null;
            }
            return ['selector' => $selector, 'action' => $action, 'property' => $property, 'value' => $value];
        }

        if ($action === 'add_class' || $action === 'remove_class') {
            if (!in_array($value, self::ADAPTIVE_CLASS_WHITELIST, true)) {
                return null;
            }
            // Clases cuya regla CSS actúa sobre el elemento MISMO (no sobre descendientes) y que
            // por tanto arrasan la página entera si caen en "body". El LLM tiende a elegir "body"
            // porque es el único selector siempre disponible, así que la garantía tiene que ser
            // estructural y no depender de que el prompt lo pida bien.
            if ($action === 'add_class' && $selector === 'body'
                    && in_array($value, self::BODY_FORBIDDEN_CLASSES, true)) {
                return null;
            }
            return ['selector' => $selector, 'action' => $action, 'value' => $value];
        }

        // hide / show / show_resources — sin value requerido.
        return ['selector' => $selector, 'action' => $action];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BLOQUE C2 (PASO 2) — rationale, css_vars, el loop completo, ensamblado de sanitize()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Puerto de sanitizer.py::_sanitize_rationale(). Opcional — inválido (HTML, demasiado
     * largo, vacío) se descarta SIN tumbar la acción completa (rationale es explicativo, no
     * funcional).
     *
     * Usa mb_strlen(..., 'UTF-8') en vez de strlen() — confirmado con el usuario
     * (2026-08-01): rationale es texto libre en español (tildes/eñes: "Se ocultó...",
     * "presión..."), strlen() cuenta BYTES UTF-8 en vez de CARACTERES y rechazaría
     * rationales válidos cerca del límite de 140 (cada tilde son 2 bytes pero 1 carácter).
     * Python usa len(value), que cuenta caracteres — mb_strlen() es la paridad real, no
     * strlen(). NOTA PENDIENTE para el futuro puerto de plan_engine.php:
     * MAX_EMPATHIC_MESSAGE_LEN probablemente tiene el mismo problema (mismo patrón de texto
     * libre en español + comparación de longitud) — no se toca aquí, vive fuera de este
     * archivo, pero no hay que perderlo de vista.
     */
    private static function sanitize_rationale(mixed $value): ?string {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > self::MAX_RATIONALE_LEN
                || self::matches(self::RATIONALE_DENYLIST, $value)) {
            return null;
        }
        return $value;
    }

    /**
     * Puerto de sanitizer.py::_sanitize_css_vars(). Usa css_var_value_patterns() (método,
     * no const — ver BLOQUE A: --emotion-font-family se construye en runtime vía
     * preg_quote()). Cada clave sin patrón definido, o cuyo valor no matchea, se descarta
     * individualmente sin tumbar las demás.
     */
    private static function sanitize_css_vars(array $raw_vars): array {
        $patterns = self::css_var_value_patterns();
        $clean = [];
        foreach ($raw_vars as $key => $value) {
            $pattern = is_string($key) ? ($patterns[$key] ?? null) : null;
            if ($pattern !== null && is_string($value) && self::matches($pattern, $value)) {
                $clean[$key] = $value;
            }
        }
        return self::derive_contrast_vars($clean);
    }

    /**
     * Deriva los colores de TEXTO a partir del color que eligió el LLM (2026-08-08).
     *
     * POR QUÉ: `--emotion-primary` está sobrecargada en styles.css — es a la vez el FONDO de
     * cabecera/navbar/botones y el COLOR DE TEXTO de títulos, enlaces y pestañas. Son requisitos
     * de contraste OPUESTOS: el azul pastel que la Tabla 24 pide para Frustrado funciona como
     * fondo pero es ilegible como texto sobre blanco (medido: 1.5:1 real, frente al 4.5:1 que
     * exige WCAG AA). 13 de 17 colores reales del log incumplían AA, en las 4 emociones.
     *
     * La paleta la sigue decidiendo el LLM según la tabla — aquí no se cambia ni un color que él
     * haya elegido. Solo se AÑADEN los dos colores de texto que faltaban, calculados desde el
     * suyo conservando tono y saturación (ver color_contrast::darken_until_readable).
     *
     * Estas dos claves NO están en CSS_VAR_VALUE_PATTERNS_STATIC a propósito: son derivadas, no
     * proponibles. Si el LLM intentara fijarlas, el filtro de arriba ya las descartó antes de
     * llegar aquí, y este método las sobrescribe con el valor calculado.
     *
     * @param array $clean Variables ya validadas por formato.
     * @return array Las mismas, más --emotion-primary-text y --emotion-on-primary.
     */
    private static function derive_contrast_vars(array $clean): array {
        // Si el LLM no fijó color primario, no hay nada que derivar: styles.css cae en los
        // valores de :root, que ya son legibles por construcción.
        if (!isset($clean['--emotion-primary'])) {
            return $clean;
        }

        // Guarda del fondo de página: el texto de cuerpo de Moodle es oscuro y fijo, así que un
        // fondo oscuro volvería ilegible la página entera. Hoy todos los valores observados son
        // claros y pasan sin cambio; esto es defensivo, no correctivo.
        if (isset($clean['--emotion-bg'])) {
            $clean['--emotion-bg'] = color_contrast::lighten_until_readable(
                $clean['--emotion-bg'], color_contrast::DARK_TEXT
            );
        }

        $primary = $clean['--emotion-primary'];
        // El texto de la página se lee sobre el fondo de contenido, que es blanco en las tarjetas
        // (styles.css fija #FFFFFF en .generalbox/.book_content/.que), no sobre --emotion-bg.
        // Blanco es además el caso más exigente, así que sirve para ambos.
        $clean['--emotion-primary-text'] = color_contrast::darken_until_readable(
            $primary, color_contrast::LIGHT_TEXT
        );
        // Texto ENCIMA del color primario (cabecera, navbar, botones). Sustituye la suposición
        // que styles.css tenía escrita a mano sobre qué emociones usan fondo oscuro: ese color
        // lo elige el LLM en runtime, así que se calcula desde el valor real.
        $clean['--emotion-on-primary'] = color_contrast::best_text_on($primary);

        // Mismo problema con el acento cálido de Aburrido: styles.css lo usa como fondo de
        // .btn-primary y Bootstrap pinta el texto en blanco, lo que daba 2.16-2.65:1 en 3 de los
        // 4 colores que el LLM eligió realmente. No se añade a DEFAULT_CSS por la misma razón
        // que --emotion-accent-warm: es específica de Aburrido y se ELIMINA al revertir (vía
        // appliedCssVars) en vez de resetearse a un valor fijo.
        if (isset($clean['--emotion-accent-warm'])) {
            $clean['--emotion-on-accent-warm'] = color_contrast::best_text_on($clean['--emotion-accent-warm']);
        }

        return $clean;
    }

    /**
     * Puerto de sanitizer.py::_sanitize_dom_actions() — aplica sanitize_dom_action() (BLOQUE
     * C1) a cada acción cruda y arma el "rationale" por separado (independiente del tipo de
     * acción, igual que en Python).
     *
     * Punto de corte de MAX_DOM_ACTIONS — confirmado leyendo sanitizer.py de nuevo, no
     * asumido: Python corta en DOS momentos distintos, no uno:
     * 1. `raw_actions[: MAX_DOM_ACTIONS * 2]` — un margen defensivo ANTES de validar nada
     *    (60 = 30*2), para acotar cuántos items crudos se procesan como máximo, sin relación
     *    con cuántos sobreviven.
     * 2. `if len(clean) >= MAX_DOM_ACTIONS: break` — el corte REAL (30), evaluado DESPUÉS de
     *    cada acción que sobrevive exitosamente (dentro del loop, tras el append) — no
     *    después de validar cada item crudo. O sea: el tope real es sobre acciones VÁLIDAS
     *    acumuladas, no sobre acciones crudas recibidas.
     * Portado exacto en ese mismo orden: array_slice() primero (margen), luego el break
     * dentro del loop tras cada push exitoso a $clean (tope real).
     */
    private static function sanitize_dom_actions(array $raw_actions, array $page_context): array {
        $sliced = array_slice($raw_actions, 0, self::MAX_DOM_ACTIONS * 2);

        $clean = [];
        foreach ($sliced as $item) {
            if (!is_array($item)) {
                continue;
            }
            $cleaned = self::sanitize_dom_action($item, $page_context);
            if ($cleaned === null) {
                continue;
            }
            $cleaned['rationale'] = self::sanitize_rationale($item['rationale'] ?? null);
            $clean[] = $cleaned;

            if (count($clean) >= self::MAX_DOM_ACTIONS) {
                break;
            }
        }
        return $clean;
    }

    /**
     * Puerto de sanitizer.py::_sanitize_suggested_resource(). "tutor_virtual" no tiene URL
     * real posible todavía (sin módulo Moodle equivalente en este proyecto) — se deja pasar
     * igual, el JS lo renderiza como texto, no como enlace. "forum"/"glossary" solo se
     * conservan si page_context.resource_urls trae una URL real — nunca se promete un
     * enlace roto.
     */
    private static function sanitize_suggested_resource(mixed $value, array $page_context): ?string {
        if (!is_string($value) || !in_array($value, self::SUGGESTED_RESOURCE_WHITELIST, true)) {
            return null;
        }
        if ($value === 'tutor_virtual') {
            return $value;
        }
        $resource_urls = $page_context['resource_urls'] ?? [];
        if (!is_array($resource_urls)) {
            $resource_urls = [];
        }
        return !empty($resource_urls[$value]) ? $value : null;
    }

    /**
     * Ritmo de la transición — etapa 5 ("Transition") del modelo ISATINE: no QUÉ cambiar, sino
     * cómo se lleva al estudiante desde la interfaz vieja a la nueva.
     *
     * Sin equivalente en sanitizer.py — es funcionalidad nueva de la versión PHP, no un puerto.
     *
     * Difiere a propósito de los otros dos sanitizadores escalares de este archivo en el tipo
     * de retorno: `string`, no `?string`. Un valor irreconocible degrada a TRANSITION_DEFAULT
     * en vez de a null, así que aguas abajo (strategy_dispatcher, dom_applier) el campo SIEMPRE
     * tiene un modo válido y nadie necesita una rama de "y si viene null". Consecuencia
     * deseada: una respuesta mala del LLM produce el comportamiento histórico exacto del
     * plugin ("gradual", 400 ms), no un estado sin definir.
     *
     * $emotion se usa para el veto de Concentrado (ver TRANSITION_FORBIDDEN_FOR_CONCENTRADO).
     * Es el único sanitizador de este archivo que depende de la emoción — el resto valida
     * forma, este valida además adecuación. sanitize() ya recibe $emotion en su firma, así que
     * no hace falta ensanchar nada para conseguirlo.
     */
    private static function sanitize_transition(mixed $value, string $emotion): string {
        if (!is_string($value) || !in_array($value, self::TRANSITION_WHITELIST, true)) {
            return self::TRANSITION_DEFAULT;
        }
        if ($emotion === 'Concentrado'
                && in_array($value, self::TRANSITION_FORBIDDEN_FOR_CONCENTRADO, true)) {
            return self::TRANSITION_DEFAULT;
        }
        return $value;
    }

    /**
     * Puerto de sanitizer.py::_sanitize_empathic_message(). Trunca a
     * MAX_EMPATHIC_MESSAGE_LEN si el LLM se excede (corte duro), vacío/solo-espacios -> null.
     *
     * Usa mb_substr(..., 'UTF-8'), NO substr() — mismo motivo que mb_strlen() en
     * sanitize_rationale() (BLOQUE C2): Python usa value[:N], que corta por CARACTER
     * (codepoint), no por byte. substr() de PHP corta por byte y partiría un carácter UTF-8
     * multibyte a la mitad si el corte cae en medio de una tilde/eñe — corrompiendo el string
     * resultante. Esto era la "NOTA PENDIENTE" que dejamos documentada en BLOQUE C2 para
     * cuando se portara este método — se resuelve acá, no se repite el mismo bug dos veces.
     */
    private static function sanitize_empathic_message(mixed $value): ?string {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, self::MAX_EMPATHIC_MESSAGE_LEN, 'UTF-8');
    }

    /** Enumeraciones de la trazabilidad del prompt v6. */
    private const TRACE_ESTADOS = ['adaptar', 'mantener', 'sin_adaptacion'];
    private const TRACE_MAGNITUDES = ['ninguna', 'leve', 'moderada', 'completa'];
    private const TRACE_NIVELES = ['Visceral', 'Comportamental', 'Reflexivo'];
    private const TRACE_DIMENSIONES = ['COLOR', 'FONT', 'LAYOUT'];
    private const TRACE_MOTIVOS = ['conflicto', 'fuera_de_alcance', 'nivel_inactivo', 'momento_no_aplicable',
        'magnitud_insuficiente', 'accesibilidad', 'otra_emocion'];

    /** Texto plano acotado (sin HTML ni saltos de linea). */
    private static function trace_text($value, int $max): ?string {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
        return $clean === '' ? null : \core_text::substr($clean, 0, $max);
    }

    /**
     * Valida los campos de trazabilidad del prompt v6 (decision, reglas_aplicadas,
     * reglas_no_aplicadas, verificacion_contraste, limitaciones). No se ejecutan en la pagina:
     * se guardan en Adaptation_Event (local_emotions_send) para el estudio. Lo invalido se
     * descarta campo por campo; nunca falla.
     */
    public static function sanitize_trace(array $raw): array {
        $d = is_array($raw['decision'] ?? null) ? $raw['decision'] : [];
        $estado = in_array($d['estado'] ?? null, self::TRACE_ESTADOS, true) ? $d['estado'] : null;
        $niveles = array_values(array_intersect(self::TRACE_NIVELES, (array) ($d['niveles_norman_aplicados'] ?? [])));
        $trace = [
            'decision' => [
                'estado'   => $estado,
                'motivo'   => self::trace_text($d['motivo'] ?? null, 200),
                'magnitud' => in_array($d['magnitud'] ?? null, self::TRACE_MAGNITUDES, true) ? $d['magnitud'] : null,
                'niveles_norman_aplicados' => $niveles,
            ],
            'reglas_aplicadas' => [],
            'reglas_no_aplicadas' => [],
            'verificacion_contraste' => [],
            'limitaciones' => [],
        ];
        foreach (array_slice((array) ($raw['reglas_aplicadas'] ?? []), 0, 20) as $r) {
            if (is_array($r) && ($id = self::trace_text($r['id_regla'] ?? null, 40)) !== null) {
                $dim = strtoupper((string) ($r['dimension'] ?? ''));
                $trace['reglas_aplicadas'][] = [
                    'id_regla'  => $id,
                    'nivel'     => self::trace_text($r['nivel'] ?? null, 20),
                    'dimension' => in_array($dim, self::TRACE_DIMENSIONES, true) ? $dim : null,
                ];
            }
        }
        foreach (array_slice((array) ($raw['reglas_no_aplicadas'] ?? []), 0, 20) as $r) {
            if (is_array($r)) {
                $trace['reglas_no_aplicadas'][] = [
                    'id_regla' => self::trace_text($r['id_regla'] ?? null, 40),
                    'motivo'   => in_array($r['motivo'] ?? null, self::TRACE_MOTIVOS, true) ? $r['motivo'] : null,
                    'detalle'  => self::trace_text($r['detalle'] ?? null, 200),
                ];
            }
        }
        foreach (array_slice((array) ($raw['verificacion_contraste'] ?? []), 0, 10) as $v) {
            if (is_array($v) && is_numeric($v['ratio_estimado'] ?? null)) {
                $trace['verificacion_contraste'][] = [
                    'par'            => self::trace_text($v['par'] ?? null, 60),
                    'ratio_estimado' => round((float) $v['ratio_estimado'], 2),
                    'umbral'         => is_numeric($v['umbral'] ?? null) ? round((float) $v['umbral'], 2) : null,
                    'cumple'         => isset($v['cumple']) ? (bool) $v['cumple'] : null,
                ];
            }
        }
        foreach (array_slice((array) ($raw['limitaciones'] ?? []), 0, 10) as $l) {
            if (($t = self::trace_text($l, 200)) !== null) {
                $trace['limitaciones'][] = $t;
            }
        }
        return $trace;
    }

    /**
     * Ensambla la salida sanitizada completa — puerto de sanitizer.py::sanitize().
     *
     * Nota de contrato importante (confirmado releyendo plan_engine.py líneas 317-322 antes
     * de escribir esto, no asumido): sanitize() en Python NUNCA devuelve None por sí solo —
     * SIEMPRE arma el array completo, incluso con dom_actions/css_vars vacíos. El colapso a
     * None ("sanitizador rechazó todo") ocurre una capa arriba, en generate_adaptation()
     * (plan_engine.py) — pertenece a plan_engine.php, no a este archivo. Esta función lo
     * replica fielmente: siempre array, nunca null.
     */
    public static function sanitize(
        array $raw, string $emotion, string $norman_level, array $page_context
    ): array {
        return [
            'css_vars'           => self::sanitize_css_vars($raw['css_vars'] ?? []),
            'dom_actions'        => self::sanitize_dom_actions($raw['dom_actions'] ?? [], $page_context),
            'suggested_resource' => self::sanitize_suggested_resource($raw['suggested_resource'] ?? null, $page_context),
            'transition'         => self::sanitize_transition($raw['transition'] ?? null, $emotion),
            'empathic_message'   => self::sanitize_empathic_message($raw['empathic_message'] ?? null),
            'emotion'            => $emotion,
            'norman_level'       => $norman_level,
            'activity_type'      => $page_context['activity_type'] ?? 'unknown',
        ];
    }
}
