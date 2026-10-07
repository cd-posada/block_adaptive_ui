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

use block_adaptive_ui\mape\knowledge\user_model;
use core_php_time_limit;

/**
 * Fase MAPE-K: PLAN — despacho de la estrategia hacia el motor PHP local (plan_engine).
 *
 * Extraído de apply_adaptation.php en la Fase B.2 (2026-07-22): construye el modelo de
 * usuario, invoca plan_engine::generate_adaptation() e interpreta el resultado (action
 * none/recommend, aplica FALLBACKS si el LLM no trae empathic_message). Es la única
 * llamada al motor LLM del ciclo MAPE-K — apply_adaptation::execute() es ahora una fachada
 * delgada que solo recalcula confianza (ver confidence_calculator) y delega aquí.
 */
class strategy_dispatcher {

    // Movido desde apply_adaptation.php sin cambios — respaldo cuando el LLM no trae texto
    // (no es una llamada LLM, es un texto estático por emoción). get_llm_feedback.php tiene su
    // propia copia idéntica; no se toca ni se centraliza en este paso.
    private const FALLBACKS = [
        'Frustrado'  => 'Notamos que la sesión puede sentirse intensa. Simplificamos la pantalla y activamos recursos de apoyo para que puedas avanzar con más calma.',
        'Confundido' => 'Parece que el contenido requiere más claridad. Ampliamos el texto y simplificamos la vista para que sea más fácil de seguir.',
        'Aburrido'   => 'Queremos hacer la sesión más dinámica para ti. Activamos colores más vibrantes y mostramos tu progreso para motivarte.',
    ];

    /**
     * Ejecuta la fase Plan con el motor PHP local (plan_engine) y retorna el resultado ya
     * listo para que apply_adaptation::execute() lo mapee a su execute_returns().
     *
     * v3.0 (2026-09-25): el Plan corre SIEMPRE en PHP. Se eliminaron el flag 'use_php_plan'
     * y la ruta HTTP a adaptation_service (POST /adapt) junto con sus ajustes
     * adaptation_url/adaptation_secret — el bloque ya no depende de ningún servicio Python,
     * solo de la API key de OpenRouter (settings.php).
     *
     * @param string $emotion      Emoción a aplicar.
     * @param string $trend        Tendencia ('mejorando' | 'estable' | 'empeorando').
     * @param float  $confidence   Confianza recalculada (ver confidence_calculator).
     * @param string $page_context JSON crudo del contexto DOM (PARAM_RAW tal como llega del WS).
     * @param int    $userid       Fase E — usuario actual, para user_model::build_user_model().
     * @param int    $courseid     Fase E — curso real (0 si no aplica). Ver docstring de
     *                             user_model y CLAUDE.md para por qué $COURSE/$PAGE->course no
     *                             sirven en este contexto.
     * @return array {action, css_params (JSON string), norman_level, empathic_message}.
     */
    public static function dispatch(
        string $emotion, string $trend, float $confidence, string $page_context,
        int $userid = 0, int $courseid = 0
    ): array {
        $page_ctx = json_decode($page_context ?: '{}', true) ?? [];

        // El estudiante ya decidió aceptar — preferimos esperar al LLM el tiempo que
        // haga falta (medido hasta ~26s en la práctica) a fallar por un timeout corto
        // y no aplicar nada. set_time_limit evita que PHP mate el proceso antes que curl.
        core_php_time_limit::raise(65);

        // Fase E — Modelo de Usuario completo (10 atributos, ver user_model::build_user_model()
        // para el detalle de fuente/flag de cada uno). Se manda TAL CUAL (con su estructura
        // {available, value} por atributo) — es quien arma el prompt (plan_engine) quien
        // decide qué línea emitir según available, no aquí: un solo punto de verdad para
        // "qué ve el LLM".
        $user_model_data = user_model::build_user_model($userid, $courseid, $page_ctx, $emotion);

        // Prompt v6: reglas de la emocion (tabla Rule, con id_rule), intensidad DAiSEE, momento
        // de sesion, ultima adaptacion y perfil — del modelo de usuario v5 de
        // local_emotions_send. Opcional: sin ese plugin el prompt usa sus reglas de respaldo.
        $plancontext = '\\local_emotions_send\\local\\plan_context';
        if (class_exists($plancontext)) {
            // Instalacion nueva: siembra las reglas v6 de la emocion si la tabla Rule no tiene ninguna.
            rules_seeder::ensure($emotion);
            $user_model_data['plan_context'] = $plancontext::build($userid, $courseid, $emotion);
        }

        return self::dispatch_php_local($emotion, $trend, $confidence, $page_ctx, $user_model_data);
    }

    /**
     * Motor PHP local — llama a plan_engine::generate_adaptation() (que a su vez encadena
     * ethics_check::check() y sanitizer::sanitize(), en ese orden). Traduce su shape
     * (css_vars/dom_actions/suggested_resource/empathic_message sueltos, o null) al shape de
     * 4 campos que espera apply_adaptation::execute_returns().
     *
     * course_name: se pasa el mismo default ('Curso activo') que ya usaba
     * generate_adaptation() — no hace falta un valor real acá porque $user_model_data ya
     * trae el curso real (Fase E); _build_user_prompt() prioriza user_model.course sobre
     * este fallback cuando está disponible.
     */
    private static function dispatch_php_local(
        string $emotion, string $trend, float $confidence, array $page_ctx, array $user_model_data
    ): array {
        $sanitized = plan_engine::generate_adaptation(
            $emotion, $confidence, $trend, $page_ctx, 'Curso activo', $user_model_data
        );

        if ($sanitized === null) {
            return [
                'action'           => 'none',
                'css_params'       => '{}',
                'norman_level'     => '',
                'empathic_message' => self::FALLBACKS[$emotion] ?? '',
            ];
        }

        $empathic_message = trim($sanitized['empathic_message'] ?? '');
        if ($empathic_message === '') {
            $empathic_message = self::FALLBACKS[$emotion] ?? '';
        }

        return [
            'action'           => 'recommend',
            'css_params'       => json_encode([
                // (object): un css_vars vacío no debe serializar como "[]".
                'css_vars'           => (object) $sanitized['css_vars'],
                'dom_actions'        => $sanitized['dom_actions'],
                'suggested_resource' => $sanitized['suggested_resource'],
                // Ritmo de la transición (ISATINE etapa 5). Esta reproyección es explícita, no
                // un reenvío opaco: de las 7 claves que devuelve sanitizer::sanitize(), aquí
                // solo viajan 4 — sin esta línea el modo nunca llegaría al navegador.
                // No se replica en la rama de fallo de arriba a propósito: ahí action es
                // 'none' y panels.js ni siquiera parsea css_params, así que sería dato muerto.
                // El default real vive en el cliente (dom_applier), que además debe cubrir el
                // caso de un JSON.parse fallido devolviendo {}.
                'transition'         => $sanitized['transition'],
                // Prompt v6: trazabilidad (decision, reglas aplicadas/no aplicadas, contraste,
                // limitaciones). El navegador la ignora; adaptation_logger la guarda.
                'trace'              => $sanitized['trace'] ?? null,
            ]),
            'norman_level'     => $sanitized['norman_level'],
            'empathic_message' => $empathic_message,
        ];
    }
}
