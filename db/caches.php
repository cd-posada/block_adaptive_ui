<?php
// db/caches.php — Definiciones de caché MUC para block_adaptive_ui
//
// @package    block_adaptive_ui
// @copyright  2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
//             Politécnico Colombiano Jaime Isaza Cadavid
//             Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

defined('MOODLE_INTERNAL') || die();

// 'adaptationdedup' se eliminó en v2.4: existía para evitar llamar al LLM más de una vez
// cada 95s por emoción, pero get_adaptation.php ya no llama al LLM en el polling (se movió
// a apply_adaptation, invocado solo al aceptar) — ya no había nada costoso que cachear ahí,
// y de hecho no estaba deduplicando correctamente en la práctica.
$definitions = [
    'llmresult' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl'        => 95,
    ],
];
