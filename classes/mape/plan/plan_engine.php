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

use block_adaptive_ui\mape\plan\prompts\builder as prompt_builder;

defined('MOODLE_INTERNAL') || die();

/**
 * Fase D (migración a PHP, 2026-08-01) — puerto literal de adaptation_service/plan_engine.py.
 *
 * Orquestador de la fase PLAN: construye el prompt, llama al LLM (llm_client.php), corre
 * ethics_check.php sobre la respuesta CRUDA (antes de sanitizer.php — orden confirmado
 * releyendo plan_engine.py línea 310 antes de portar, no asumido), y solo entonces sanitiza.
 *
 * Decisiones de diseño explícitas de este puerto (documentadas para trazabilidad):
 * 1. Mocking de llm_client::call_openrouter() en tests: $call_openrouter_fn (property
 *    estático público, swappable) — PHPUnit no puede interceptar una llamada estática ajena
 *    sin una extensión (uopz/runkit) que este proyecto no tiene; esta es la costura de
 *    testabilidad mínima en PHP puro, sin dependencias nuevas.
 * 2. Logging JSONL: MISMAS claves que _log_jsonl() en Python (sin user_prompt/course_name/
 *    user_model) — paridad exacta a propósito, decisión reversible, no definitiva.
 * 3. Ubicación del log: $CFG->dataroot (no la carpeta del plugin, que está versionada en git
 *    y normalmente no es escribible en producción) — mismo propósito que Python
 *    (Path(__file__).parent), ubicación distinta por necesidad de la plataforma.
 * 4. json_encode((object) $density, ...) para "Densidad de contenido" — mismo patrón ya
 *    establecido en strategy_dispatcher::dispatch() para evitar que un array PHP vacío
 *    serialice como "[]" en vez de "{}" (Python: dict vacío siempre es "{}").
 */
class plan_engine {

    public const OPENROUTER_MODEL  = 'deepseek/deepseek-v4-flash';
    public const REQUEST_TIMEOUT_S = 55;
    private const TEMPERATURE = 0.2;

    // Subido de 4000 a 8000 el 2026-08-08. Con 4000 se midieron 5 respuestas truncadas
    // (finish_reason "length"), 3 de ellas con reasoning_tokens == 4000 exacto, es decir CERO
    // salida útil. Con el razonamiento desactivado (abajo) la salida real ronda 1500-2000
    // tokens, así que 8000 es holgura defensiva, no una expectativa de uso.
    private const MAX_TOKENS  = 8000;

    // ── Enrutamiento de proveedor (2026-08-08) ────────────────────────────────────────────
    // OpenRouter sirve este modelo desde 20 proveedores con rendimientos MUY distintos, y su
    // enrutamiento por defecto mandaba sistemáticamente a AtlasCloud (~15 tok/s medidos). Ese
    // era el verdadero cuello de botella: 6 llamadas idénticas de control tardaron 51-71s, y
    // 5 de esas 6 tenían reasoning_tokens == 0 — o sea que la lentitud NO venía del
    // razonamiento sino del proveedor. Medido con el prompt real del motor:
    //   AtlasCloud ~15 tok/s | OpenInference ~15 tok/s | Baidu ~110 tok/s
    // 'allow_fallbacks' => true a propósito: si ninguno de los de la lista está disponible,
    // se prefiere una respuesta lenta a un fallo duro (no aplicar nada es el peor resultado).
    private const PROVIDER_ORDER = ['Baidu', 'Parasail', 'Novita', 'DeepInfra'];

    // El razonamiento previo del modelo NO aporta a esta tarea (el prompt ya trae las reglas
    // explícitas) y sí cuesta tiempo y presupuesto de tokens. Verificado que NO se puede topar:
    // 'reasoning.max_tokens' => 256 produjo 1332 tokens de razonamiento, y 'effort' => 'low'
    // produjo 1438 — ambos ignorados por este modelo. 'exclude' => true tampoco lo desactiva,
    // solo lo oculta de la respuesta. La única forma que funciona es apagarlo del todo.
    private const REASONING = ['enabled' => false];

    public const EMOTIONS = ['Concentrado', 'Confundido', 'Frustrado', 'Aburrido'];

    /**
     * Prompt v6 — segundos minimos entre adaptaciones (compuerta, Paso 1b). SINCRONIZACION
     * MANUAL con COOLDOWN_MS de amd/src/mape/config.js (30000 ms).
     */
    public const MIN_SECONDS_BETWEEN_ADAPTATIONS = 30;

    /** Prompt v6 — actividades evaluativas: magnitud maxima "moderada", sin reorganizar el layout. */
    public const ACTIVITIES_WITHOUT_LAYOUT_REORG = ['quiz', 'assign'];

    private const NORMAN = [
        'Frustrado'   => 'Visceral + Conductual + Reflexivo',
        'Confundido'  => 'Conductual',
        'Aburrido'    => 'Visceral + Reflexivo',
        'Concentrado' => 'Conductual',
    ];

    private const ELEMENT_VIEW_FIELDS = ['selector', 'role', 'word_count', 'level'];

    /**
     * Overridable en tests — swap por un closure antes de llamar a generate_adaptation() y
     * resetear a null después (ver docstring de clase, decisión 1).
     * @var callable|null
     */
    public static $call_openrouter_fn = null;

    /**
     * Marcas del tramo de decision de la ultima llamada a generate_adaptation(), en ms de
     * epoca con el reloj del servidor (campos Adaptation_Event.decision_requested_at y
     * decision_generated_at de user_model_v5_1):
     *  - requestedms: justo antes de despachar la solicitud al LLM.
     *  - generatedms: al recibir su especificacion (respuesta HTTP 200); ausente si el LLM
     *    fallo o no respondio.
     * Vacio si no se llamo al LLM (p. ej. sin clave de API).
     * @var array
     */
    private static $last_timing = [];

    /** Marcas del tramo de decision de la ultima llamada (ver $last_timing). */
    public static function last_timing(): array {
        return self::$last_timing;
    }

    private static function now_ms(): int {
        return (int) round(microtime(true) * 1000);
    }

    private static function call_openrouter(array $payload, float $timeout): array {
        $fn = self::$call_openrouter_fn ?? [llm_client::class, 'call_openrouter'];
        return $fn($payload, $timeout);
    }

    /** Puerto de `X or default` de Python (falsy → default), no solo "clave ausente". */
    private static function or_default($value, $default) {
        return empty($value) ? $default : $value;
    }

    /**
     * Puerto de _format_platform_context(). Platform Model — enteramente opcional, nunca
     * lanza si viene ausente/parcial.
     */
    private static function format_platform_context(array $page_context): string {
        $platform = self::or_default($page_context['platform_model'] ?? null, []);
        if (!is_array($platform)) {
            $platform = [];
        }
        $device_type = self::or_default($platform['device_type'] ?? null, 'unknown');
        $viewport = self::or_default($platform['viewport'] ?? null, []);
        if (!is_array($viewport)) {
            $viewport = [];
        }
        if (!empty($viewport['width']) && !empty($viewport['height'])) {
            $viewport_str = $viewport['width'] . 'x' . $viewport['height'];
        } else {
            $viewport_str = 'desconocido';
        }
        $connection_type = self::or_default($platform['connection_type'] ?? null, 'desconocida');
        return "Dispositivo: {$device_type}. Viewport: {$viewport_str}. Conexión: {$connection_type}.";
    }

    /**
     * Puerto de _build_user_prompt() — incluye el bloque de Fase E (user_model serializado
     * condicionalmente: curso/programa/idioma solo si available, los 4 flag-off NUNCA
     * referenciados, misma garantía por ausencia de código que el lado Python).
     */
    private static function build_user_prompt(
        string $emotion, float $confidence, string $trend, array $page_context,
        string $course_name, ?array $user_model = null
    ): string {
        $user_model = $user_model ?? [];

        // "Curso" — comportamiento previo PRESERVADO: cae al fallback course_name si no hay
        // curso real disponible, nunca omite la línea por completo (a diferencia de
        // programa/idioma abajo, que sí se omiten si no hay dato).
        $course_attr = $user_model['course'] ?? [];
        $course_value = !empty($course_attr['available']) ? ($course_attr['value'] ?? null) : null;
        if (!empty($course_value) && !empty($course_value['fullname'])) {
            $course_line = "Curso: {$course_value['fullname']}\n";
        } else {
            $course_line = "Curso: {$course_name}\n";
        }

        // "Programa"/"idioma" — atributos NUEVOS, se omiten por completo si no hay dato. Los
        // 4 flag-off (nivel_estudio/genero/edad/entorno_fisico) NUNCA se referencian aquí —
        // ninguna línea de código puede serializarlos, ni detrás de un "if available".
        $user_model_lines = '';
        $programa_attr = $user_model['programa'] ?? [];
        if (!empty($programa_attr['available']) && !empty($programa_attr['value'])) {
            $user_model_lines .= "Programa (categoría del curso): {$programa_attr['value']}\n";
        }
        $idioma_attr = $user_model['idioma'] ?? [];
        if (!empty($idioma_attr['available']) && !empty($idioma_attr['value'])) {
            $user_model_lines .= "Idioma del estudiante: {$idioma_attr['value']}\n";
        }

        // Nivel DINÁMICO — solo campos estructurales (selector/role/word_count/level), nunca
        // texto real, por gobernanza de datos.
        $elements_view = [];
        foreach (sanitizer::valid_dynamic_elements($page_context) as $el) {
            $view = [];
            foreach (self::ELEMENT_VIEW_FIELDS as $field) {
                if (array_key_exists($field, $el)) {
                    $view[$field] = $el[$field];
                }
            }
            $elements_view[] = $view;
        }

        $structural = sanitizer::static_selectors($page_context);
        sort($structural);

        $density = self::or_default($page_context['content_density'] ?? null, []);
        if (!is_array($density)) {
            $density = [];
        }
        $activity = $page_context['activity_type'] ?? 'unknown';

        $opaque_note = !empty($page_context['is_opaque_activity'])
            ? "\nNota: el contenido real de esta actividad vive dentro de un iframe (video/H5P/" .
                "LTI/SCORM/recurso embebido) y NO es escaneable — el inventario de elementos de " .
                'abajo solo cubre el documento que lo rodea. SOLO puedes ajustar colores/' .
                'tipografía globales (css_vars) y selectores estructurales fijos del documento ' .
                "padre; no asumas que existe contenido específico de la actividad para " .
                "targetear.\n"
            : '';

        $jsonflags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        return
            self::format_study_data($emotion, $confidence, $activity, $user_model) .
            "Emoción detectada: {$emotion}\n" .
            'Confianza: ' . number_format($confidence, 2) . "\n" .
            "Tendencia: {$trend}\n" .
            $course_line .
            $user_model_lines .
            "Tipo de actividad actual: {$activity}\n" .
            $opaque_note .
            // (object): un array PHP vacío serializa como "[]", no "{}" — ver decisión 4.
            'Densidad de contenido: ' . json_encode((object) $density, $jsonflags) . "\n" .
            self::format_platform_context($page_context) . "\n" .
            'Elementos de contenido disponibles ahora mismo — usa el campo "selector" EXACTO ' .
            'de uno de estos, elegido según su "role" (heading/paragraph/list_item/' .
            'image_content/image_decorative/button/activity/code/table/media/callout/math) y, ' .
            'si aplica, "word_count"/"level". No inventes un selector fuera de esta lista:' . "\n" .
            json_encode($elements_view, $jsonflags) . "\n\n" .
            'Selectores estructurales fijos disponibles (chrome de Moodle — sidebar, controles ' .
            'de quiz, etc. — no cambian con el contenido de la página):' . "\n" .
            json_encode($structural, $jsonflags) . "\n\n" .
            'Genera el JSON de adaptación siguiendo las reglas de la emoción detectada.';
    }

    /**
     * Puerto MÍNIMO de RawLlmOutput (Pydantic, sanitizer.py) — no existe un validador de
     * forma equivalente en Moodle/PHP para esto (confirmado: Moodle solo valida tipos
     * escalares vía PARAM_* en la capa de web service, nada para JSON anidado arbitrario),
     * así que se escribe aquí. A diferencia de Pydantic, NO rechaza toda la respuesta si un
     * campo tiene el tipo equivocado — lo trata como ausente y aplica el default, de forma
     * silenciosa (Pydantic lanzaría ValidationError y generate_adaptation() devolvería null
     * de inmediato). Decisión pragmática: ningún test de los pedidos ejercita ese caso
     * específico (forma JSON válida pero con un campo de tipo incorrecto), y
     * sanitizer::sanitize_dom_action() ya vuelve a validar cada dom_action individualmente
     * más abajo en el pipeline (defensa en profundidad) — el resultado observable converge
     * incluso sin la estrictez de Pydantic en esta capa. Si en el futuro se necesita paridad
     * estricta (rechazar TODA la respuesta ante una forma inválida, no solo aplicar
     * default), hay que revisar esto explícitamente.
     */
    /**
     * Sección "DATOS DEL ESTUDIO" del mensaje del usuario: los valores que la TAREA del prompt
     * v6 usa por nombre (compuerta, magnitud, niveles activos, personalización). Salen de
     * local_emotions_send (plan_context, en $user_model['plan_context']); los que falten se
     * declaran "no disponible" para que el LLM lo registre en "limitaciones".
     */
    private static function format_study_data(string $emotion, float $confidence, string $activity, ?array $user_model): string {
        $ctx = is_array($user_model['plan_context'] ?? null) ? $user_model['plan_context'] : [];
        $session = $ctx['session'] ?? [];
        $profile = $ctx['profile'] ?? [];
        $na = 'no disponible';
        $list = function ($v) use ($na) {
            return (is_array($v) && $v) ? implode(', ', $v) : $na;
        };
        $context = $profile['context'] ?? [];
        $platform = is_array($user_model['platform']['value'] ?? null) ? $user_model['platform']['value'] : [];
        $contexto_uso = array_filter([
            'dispositivo_declarado' => $context['device'] ?? null,
            'conexion' => $context['connection'] ?? null,
            'modalidad' => $context['modality'] ?? null,
            'dispositivo_detectado' => $platform['device_type'] ?? null,
        ]);
        $lines = [
            'emocion (Emotion.code)' => \block_adaptive_ui\mape\plan\prompts\rules_v6::EMOTION_CODES[$emotion] ?? $emotion,
            'confianza_clasificador' => number_format($confidence, 2),
            'umbral_confianza_minima' => number_format(\block_adaptive_ui\mape\analyze\dominant_emotion_calculator::BASE_THRESHOLD, 2),
            'segundos_desde_ultima_adaptacion' => isset($session['seconds_since_last_adaptation'])
                ? (string) $session['seconds_since_last_adaptation'] : 'sin adaptaciones previas en esta sesión',
            'segundos_minimos_entre_adaptaciones' => (string) self::MIN_SECONDS_BETWEEN_ADAPTATIONS,
            'intensidad_daisee (Emotion_Record.intensity_daisee, 0-3)' => isset($session['intensity'])
                ? (string) $session['intensity'] : $na,
            'momento_sesion' => isset($session['momento_sesion'])
                ? $session['momento_sesion'] . " (minuto {$session['minutes']} de la sesión)" : $na,
            'tipo_actividad_moodle' => $activity,
            'actividades_sin_reorganizacion_de_layout' => implode(', ', self::ACTIVITIES_WITHOUT_LAYOUT_REORG),
            'idioma_nativo' => $list($profile['native_languages'] ?? null),
            'otros_idiomas' => $list($profile['other_languages'] ?? null),
            'nivel_formacion' => $profile['level'] ?? $na,
            'programa (declarado por el estudiante)' => $profile['program'] ?? $na,
            'contexto_uso' => $contexto_uso ? json_encode($contexto_uso, JSON_UNESCAPED_UNICODE) : $na,
            'selectores_moodle_verificados' => 'los de las dos listas de más abajo',
            'fuentes_disponibles_en_servidor' => 'las del MENÚ DE FUENTES',
        ];
        $out = "DATOS DEL ESTUDIO (úsalos en la TAREA):\n";
        foreach ($lines as $k => $v) {
            $out .= "- {$k}: {$v}\n";
        }
        return $out . "\n";
    }

    private static function parse_raw_llm_output(array $decoded): array {
        $css_vars = $decoded['css_vars'] ?? [];
        if (!is_array($css_vars)) {
            $css_vars = [];
        }
        $dom_actions = $decoded['dom_actions'] ?? [];
        if (!is_array($dom_actions)) {
            $dom_actions = [];
        }
        $suggested_resource = $decoded['suggested_resource'] ?? null;
        if (!is_string($suggested_resource)) {
            $suggested_resource = null;
        }
        $empathic_message = $decoded['empathic_message'] ?? null;
        if (!is_string($empathic_message)) {
            $empathic_message = null;
        }
        // Ritmo de la transición (ISATINE etapa 5) — mismo tratamiento que suggested_resource:
        // un tipo distinto de string se trata como ausente. El valor concreto lo valida
        // sanitizer::sanitize_transition(), que además degrada a "gradual" en vez de a null —
        // este campo NUNCA es null aguas abajo, a diferencia de los otros dos escalares.
        $transition = $decoded['transition'] ?? null;
        if (!is_string($transition)) {
            $transition = null;
        }
        return [
            'css_vars'           => $css_vars,
            'dom_actions'        => $dom_actions,
            'suggested_resource' => $suggested_resource,
            'transition'         => $transition,
            'empathic_message'   => $empathic_message,
            // Prompt v6: trazabilidad (no se ejecuta; se registra en Adaptation_Event).
            'trace'              => sanitizer::sanitize_trace($decoded),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOGGING JSONL — artefacto de investigación (decisiones 2 y 3 arriba)
    // ─────────────────────────────────────────────────────────────────────────

    private static function log_jsonl(array $fields): void {
        global $CFG;
        $record = ['timestamp' => microtime(true)] + $fields;
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            debugging('plan_engine: no se pudo serializar el registro JSONL', DEBUG_NORMAL);
            return;
        }
        $dir = make_writable_directory($CFG->dataroot . '/block_adaptive_ui', false);
        if ($dir === false) {
            debugging('plan_engine: no se pudo crear/escribir el directorio de log JSONL', DEBUG_NORMAL);
            return;
        }
        $result = @file_put_contents($dir . '/plan_engine_log.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
        if ($result === false) {
            debugging('plan_engine: no se pudo escribir log JSONL en ' . $dir, DEBUG_NORMAL);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FUNCIÓN PÚBLICA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Puerto de generate_adaptation(). Retorna null si el LLM falla, responde JSON
     * inválido, el chequeo ético rechaza la respuesta, o el sanitizador rechaza
     * absolutamente todo — el caller debe traducir esto a action="none".
     */
    public static function generate_adaptation(
        string $emotion, float $confidence, string $trend, array $page_context,
        string $course_name = 'Curso activo', ?array $user_model = null
    ): ?array {
        self::$last_timing = [];
        // Chequeo ANTES de construir el prompt o tocar la red — confirmado (punto 7): mismo
        // orden que Python (if not OPENROUTER_API_KEY: return None, antes de _build_user_prompt()).
        $apikey = get_config('block_adaptive_ui', 'openrouter_api_key');
        if (empty($apikey)) {
            debugging('plan_engine: openrouter_api_key no configurada', DEBUG_NORMAL);
            return null;
        }

        $user_prompt = self::build_user_prompt($emotion, $confidence, $trend, $page_context, $course_name, $user_model);
        $log_base = [
            'emotion' => $emotion, 'confidence' => $confidence, 'trend' => $trend,
            'model' => self::OPENROUTER_MODEL,
        ];

        $t0 = microtime(true);
        self::$last_timing['requestedms'] = self::now_ms();
        try {
            $resp = self::call_openrouter([
                'model'           => self::OPENROUTER_MODEL,
                'messages'        => [
                    ['role' => 'system', 'content' => prompt_builder::build_system_prompt(
                        $emotion, $user_model['plan_context']['rules'] ?? null)],
                    ['role' => 'user',   'content' => $user_prompt],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature'     => self::TEMPERATURE,
                'max_tokens'      => self::MAX_TOKENS,
                'provider'        => [
                    'order'           => self::PROVIDER_ORDER,
                    'allow_fallbacks' => true,
                ],
                'reasoning'       => self::REASONING,
            ], self::REQUEST_TIMEOUT_S);
        } catch (\Throwable $e) {
            // Python distingue Timeout de RequestException genérico solo para el TEXTO del
            // campo "error" logueado (el valor de retorno, null, es idéntico en ambos casos)
            // — acá se simplifica a una sola rama porque llm_client::call_openrouter() ya
            // lanza un único tipo de excepción para cualquier fallo de transporte; el
            // mensaje real (getMessage()) ya describe qué pasó (timeout incluido), así que
            // no se pierde información útil en el log, solo la distinción de TIPO de
            // excepción de Python.
            $latency_ms = (int) round((microtime(true) - $t0) * 1000);
            debugging('plan_engine: fallo de red llamando a OpenRouter: ' . $e->getMessage(), DEBUG_NORMAL);
            self::log_jsonl($log_base + ['ok' => false, 'error' => $e->getMessage(), 'latency_ms' => $latency_ms]);
            return null;
        }

        $latency_ms = (int) round((microtime(true) - $t0) * 1000);

        if ($resp['http_code'] !== 200) {
            debugging("plan_engine: OpenRouter devolvió HTTP {$resp['http_code']}", DEBUG_NORMAL);
            self::log_jsonl($log_base + [
                'ok' => false, 'error' => "http_{$resp['http_code']}", 'latency_ms' => $latency_ms,
            ]);
            return null;
        }
        self::$last_timing['generatedms'] = self::now_ms();

        $finish_reason = null;
        $usage = null;
        $provider = null;
        $parsed = null;
        $raw_json = null;
        try {
            $resp_json = json_decode($resp['body'], true, 512, JSON_THROW_ON_ERROR);
            $usage = $resp_json['usage'] ?? null;
            // Proveedor que realmente atendió la petición. Se registra desde el 2026-08-08:
            // el rendimiento varía ~7x entre proveedores del mismo modelo (~15 vs ~110 tok/s
            // medidos), y sin este campo esa variabilidad era invisible en el log — se atribuía
            // a "el LLM está lento" sin poder distinguir cuál.
            $provider = $resp_json['provider'] ?? null;
            if (!isset($resp_json['choices'][0])) {
                throw new \RuntimeException('choices[0] ausente en la respuesta de OpenRouter');
            }
            $finish_reason = $resp_json['choices'][0]['finish_reason'] ?? null;
            $content = $resp_json['choices'][0]['message']['content'] ?? null;
            if (!is_string($content)) {
                throw new \RuntimeException('choices[0].message.content ausente o no es string');
            }
            $raw_json = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($raw_json)) {
                throw new \RuntimeException('el contenido del LLM no decodificó a un objeto JSON');
            }
            $parsed = self::parse_raw_llm_output($raw_json);
        } catch (\Throwable $exc) {
            // finish_reason/usage pueden haberse leído igual antes del fallo — se incluyen
            // si están disponibles, para diagnosticar la causa real desde el log.
            //
            // El truncamiento se etiqueta APARTE (2026-08-08): antes caía en este mismo catch
            // genérico y quedaba mezclado bajo "invalid_response:" junto con "el modelo devolvió
            // prosa" y "la forma de la respuesta era otra", que son problemas distintos con
            // soluciones distintas. Para saber que había sido truncamiento había que leer a mano
            // el campo finish_reason vecino. Con la etiqueta propia, el log se puede agrupar por
            // causa y se puede demostrar (no suponer) que un cambio de tokens/proveedor sirvió.
            $prefix = ($finish_reason === 'length') ? 'truncated_output: ' : 'invalid_response: ';
            debugging("plan_engine: respuesta LLM inválida ({$emotion}): " . $exc->getMessage(), DEBUG_NORMAL);
            self::log_jsonl($log_base + [
                'ok' => false, 'error' => $prefix . $exc->getMessage(),
                'latency_ms' => $latency_ms, 'usage' => $usage, 'finish_reason' => $finish_reason,
                'provider' => $provider,
            ]);
            return null;
        }

        $log_base['latency_ms']    = $latency_ms;
        $log_base['usage']         = $usage;
        $log_base['finish_reason'] = $finish_reason;
        $log_base['provider']      = $provider;

        // ORDEN CRÍTICO — ethics_check PRIMERO, sobre dom_actions CRUDAS (post-forma,
        // pre-sanitizer). Confirmado releyendo plan_engine.py línea 310 antes de portar.
        $ethics_violation = ethics_check::check($parsed['dom_actions'], $emotion, $confidence);
        if ($ethics_violation !== null) {
            debugging("plan_engine: rechazado por chequeo ético ({$emotion}): {$ethics_violation}", DEBUG_NORMAL);
            self::log_jsonl($log_base + [
                'ok' => false, 'error' => "rejected_by_ethics_check: {$ethics_violation}", 'raw' => $raw_json,
            ]);
            return null;
        }

        // sanitizer::sanitize() SOLO se llama si ethics_check pasó — nunca antes.
        $norman_level = self::NORMAN[$emotion] ?? 'Conductual';
        $sanitized = sanitizer::sanitize($parsed, $emotion, $norman_level, $page_context);
        $trace = $parsed['trace'];
        $sanitized['trace'] = $trace;
        // Prompt v6: los niveles de Norman los decide el LLM (Paso 3d); si no los declara, se
        // conserva el mapeo fijo por emoción.
        if (!empty($trace['decision']['niveles_norman_aplicados'])) {
            $sanitized['norman_level'] = implode(' + ', $trace['decision']['niveles_norman_aplicados']);
        }
        // Prompt v6: la compuerta puede decidir NO adaptar ("mantener"/"sin_adaptacion" o
        // magnitud "ninguna"). Es una decisión válida, no un fallo: se devuelve sin cambios y
        // con el mensaje empático que explica que la interfaz se mantiene.
        $estado = $trace['decision']['estado'] ?? null;
        $gated = in_array($estado, ['mantener', 'sin_adaptacion'], true)
            || ($trace['decision']['magnitud'] ?? null) === 'ninguna';
        if ($gated && empty($sanitized['dom_actions']) && empty($sanitized['css_vars'])) {
            self::log_jsonl($log_base + ['ok' => true, 'gated' => $estado, 'raw' => $raw_json, 'sanitized' => $sanitized]);
            return $sanitized;
        }

        // El colapso a null es responsabilidad de ESTA capa — sanitize() en sí nunca
        // devuelve null (confirmado en el checkpoint del BLOQUE C2).
        if (empty($sanitized['dom_actions']) && empty($sanitized['css_vars'])) {
            debugging("plan_engine: sanitizador rechazó toda la respuesta ({$emotion})", DEBUG_NORMAL);
            self::log_jsonl($log_base + ['ok' => false, 'error' => 'sanitizer_empty', 'raw' => $raw_json]);
            return null;
        }

        self::log_jsonl($log_base + ['ok' => true, 'raw' => $raw_json, 'sanitized' => $sanitized]);
        return $sanitized;
    }
}
