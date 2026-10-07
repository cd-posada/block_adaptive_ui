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
 * Fase D (migración a PHP, 2026-08-01) — puerto literal de adaptation_service/openrouter_client.py.
 *
 * Cliente HTTP de bajo nivel hacia OpenRouter — solo encapsula el POST en sí, sin interpretar
 * la respuesta ni decidir reintentos/fallbacks (eso es responsabilidad del caller, igual que
 * en Python: "Puede lanzar requests.exceptions.*, el caller decide cómo manejarlas").
 *
 * Curl casi calcado del que ya existe en strategy_dispatcher::dispatch() (mismo estilo:
 * curl_setopt_array() con constantes CURLOPT_ nombradas, CURLOPT_RETURNTRANSFER,
 * CURLOPT_CONNECTTIMEOUT=3) — con una diferencia deliberada de contrato frente a ESE archivo:
 * strategy_dispatcher::dispatch() atrapa cualquier fallo de red y devuelve un array de
 * fallback silencioso (action=none + FALLBACKS[emotion]) porque esa es la fachada completa
 * de la fase Plan. call_openrouter() aquí es la capa de transporte pura, un nivel más abajo
 * — como Python nunca atrapa la excepción de requests dentro de call_openrouter() (la deja
 * propagar), este método tampoco resuelve el fallo silenciosamente: lanza \RuntimeException
 * si curl falla a nivel de transporte (timeout, DNS, conexión rechazada), para que el futuro
 * plan_engine.php decida qué hacer con eso — mismo patrón try/except que ya usa Python en
 * generate_adaptation() alrededor de esta llamada.
 *
 * Un HTTP de error (ej. 500, 401) NO lanza excepción — se devuelve tal cual en 'http_code'
 * junto con el 'body' crudo, igual que Python nunca llama a resp.raise_for_status(): la
 * interpretación de status codes es decisión del caller, no de este cliente de transporte.
 */
class llm_client {

    private const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * POST crudo a OpenRouter.
     *
     * @param array $payload Cuerpo JSON de la petición (mensajes, modelo, etc.).
     * @param float $timeout Timeout total en segundos (CURLOPT_TIMEOUT solo acepta enteros —
     *                       se redondea, mismo comportamiento práctico que el float de Python
     *                       pasado a requests, que internamente también opera en resolución
     *                       de socket, no en fracciones de segundo garantizadas).
     * @return array{http_code: int, body: string} Respuesta cruda — CUALQUIER código HTTP,
     *         sin interpretar.
     * @throws \RuntimeException Si la llamada falla a nivel de transporte (timeout, DNS,
     *         conexión rechazada) — equivalente PHP de las requests.exceptions.* que Python
     *         deja propagar sin atrapar.
     */
    public static function call_openrouter(array $payload, float $timeout): array {
        $apikey = get_config('block_adaptive_ui', 'openrouter_api_key') ?: '';

        $ch = curl_init(self::OPENROUTER_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apikey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) round($timeout),
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);

        $body      = curl_exec($ch);
        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $curl_errno !== 0) {
            throw new \RuntimeException(
                'llm_client::call_openrouter — fallo de transporte hacia OpenRouter: ' .
                    ($curl_error !== '' ? $curl_error : "curl errno {$curl_errno}")
            );
        }

        return ['http_code' => $http_code, 'body' => $body];
    }
}
