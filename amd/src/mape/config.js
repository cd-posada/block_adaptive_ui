// Adaptive_monitor.js — Monitor y Execute del ciclo MAPE-K (v2.5)
//
// block_adaptive_ui — Módulo de Adaptación Visual por Emoción para Moodle
//
// Copyright (C) 2025 Miguel Ángel Salazar Rincón <salazarmiguel978@gmail.com>
// Politécnico Colombiano Jaime Isaza Cadavid
// Trabajo de Grado — Ingeniería Informática, Medellín, Colombia
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AMD module: mape/config
 *
 * Constantes compartidas del ciclo MAPE-K Monitor+Execute (extraídas de
 * adaptive_monitor.js en la Fase B.3, 2026-07-22). emotionCooldown se exporta como objeto
 * (no como valores primitivos) porque es estado mutable compartido por referencia entre
 * mape/monitor/poll.js (lo lee y escribe) — un valor primitivo re-exportado se copiaría en
 * vez de compartirse.
 *
 * @module block_adaptive_ui/mape/config
 */
define([], function() {
    'use strict';

    var PANEL_ID = 'adaptive-recommendation-panel';
    var FEEDBACK_ID = 'adaptive-feedback-box';
    var COOLDOWN_MS = 30000; // 30 segundos entre paneles

    var PANEL_COLORS = {
        'Frustrado':   '#90CAF9',
        'Confundido':  '#1565C0',
        'Aburrido':    '#00695C',
        'Concentrado': '#1D9E75',
    };

    var GENERIC_MESSAGES = {
        'Frustrado':
            'Detectamos que puede ser una sesión intensa. Te recomendamos adaptar la interfaz para que sea más cómoda.',
        'Confundido':
            'Parece que el contenido puede ser difícil de seguir. Te recomendamos adaptar la vista para mayor claridad.',
        'Aburrido':
            'Queremos hacer la sesión más dinámica para ti. Te recomendamos activar una adaptación visual.',
        'Concentrado':
            '¡Estás en un excelente estado de concentración! Activamos mejoras sutiles para acompañar tu flujo de trabajo.',
    };

    // Fase B.0.2: reducido a un único string genérico de emergencia — el caso "el LLM no
    // generó texto pero apply_adaptation sí respondió" ahora lo cubre el array FALLBACKS por
    // emoción en apply_adaptation.php (siempre viene con contenido si la llamada tuvo éxito).
    // Este string solo se usa si la llamada AJAX a apply_adaptation falla por completo a nivel
    // de red (no llegó ninguna respuesta del servidor) — ver el .catch() de "Activar cambios".
    var FEEDBACK_FALLBACK =
        'No pudimos conectar con el servicio de adaptación en este momento. Puedes intentarlo de nuevo en unos segundos.';

    // CSS vars predeterminadas para reset antes de aplicar nueva emoción
    var DEFAULT_CSS = {
        '--emotion-bg':             '#f5f5f5',
        '--emotion-primary':        '#0f6cbf',
        // Derivadas en PHP por sanitizer::derive_contrast_vars() a partir de --emotion-primary
        // (el LLM no las propone). Tienen que estar AQUÍ sí o sí: revertDOMChanges() limpia
        // exactamente las claves de DEFAULT_CSS más las de appliedCssVars, así que omitirlas
        // las dejaría pegadas en el style inline de <html> tras revertir — justo el tipo de
        // residuo que se corrigió el 2026-08-08.
        '--emotion-primary-text':   '#0f6cbf',
        '--emotion-on-primary':     '#ffffff',
        '--emotion-text-size':      '1rem',
        '--emotion-spacing':        '1rem',
        '--emotion-font-weight':    '400',
        '--emotion-line-height':    '1.5',
        '--emotion-border-radius':  '4px',
        // 0em y NO 'normal': "normal" es una palabra clave y el navegador no puede interpolar
        // entre ella y una longitud, así que el cambio de espaciado saltaba de golpe (medido en
        // Chrome real, 2026-09-16). Debe coincidir con el valor de :root en styles.css.
        '--emotion-letter-spacing': '0em',
        '--emotion-font-family':    'system-ui, sans-serif',
        // Ritmo de la transición (ISATINE etapa 5). No la propone el LLM — la deriva
        // dom_applier a partir del modo ya resuelto, igual que las dos variables de contraste
        // se derivan en PHP. Tiene que estar AQUÍ por el contrato de reversión:
        // revertDOMChanges() limpia exactamente las claves de DEFAULT_CSS más las de
        // appliedCssVars, así que omitirla la dejaría pegada en el style inline de <html> y la
        // página conservaría el ritmo de la última emoción tras revertir.
        // SINCRONIZACIÓN MANUAL: debe coincidir con MODES.gradual.duration de
        // transition_manager.js, es decir 400 × SPEED. SPEED no puede alcanzar este valor desde
        // aquí (es otro módulo y además lo replica styles.css), así que al recalibrar el ritmo
        // hay que subirlo a mano en los tres sitios. Mismo tipo de acoplamiento ya documentado
        // entre MAX_DYNAMIC_ELEMENTS (PHP) y los topes del escáner (JS).
        // Su impacto real es pequeño: applyDOMChanges lo sobreescribe con el valor del modo
        // antes de ejecutar ninguna acción, y revertDOMChanges lo elimina. Importa por
        // coherencia para quien lea el código, no por comportamiento observable.
        '--adaptive-transition-duration': '800ms',
    };

    // Texto de respaldo si el LLM no incluyó "rationale" para una acción puntual.
    var GENERIC_RATIONALE_FALLBACK = 'Cambio aplicado según tu estado emocional detectado.';

    // Cooldown por emoción: timestamp de la última vez que se mostró el panel. Estado mutable
    // compartido por referencia (ver docstring del módulo) — solo mape/monitor/poll.js lo lee
    // y escribe, pero se exporta desde aquí en vez de vivir local a poll.js porque agrupa con
    // el resto de config.js para el resto de este mismo objetivo original (bloque 41-122).
    var emotionCooldown = {Frustrado: null, Confundido: null, Aburrido: null, Concentrado: null};

    // adaptive-truncate como primitiva general (C.3, 2026-07-28) — umbral de palabras por
    // emoción para decidir cuándo un bloque de texto es "denso" y amerita colapsarse.
    // Concentrado: null a propósito — no se le trunca nada, no hay carga cognitiva que reducir.
    //
    // IMPORTANTE (documentado aquí para que no se asuma que ya está conectado): estos números
    // hoy NO tienen ningún consumidor. La decisión real de aplicar "adaptive-truncate" no vive
    // en el cliente — vive en el prompt del LLM (adaptation_service/plan_engine.py,
    // _SYSTEM_PROMPT, sección CONFUNDIDO Fila 1: "si supera 300 [palabras], considera
    // add_class adaptive-truncate"), un umbral fijo de 300, solo mencionado para Confundido,
    // que el LLM aplica como criterio de juicio, no un chequeo determinístico de JS. No existe
    // ningún "40 hardcodeado" en el cliente que reemplazar — attachTruncateDelegate()
    // (mape/execute/panels.js) solo maneja el click de expandir/colapsar, nunca decide CUÁNDO
    // truncar. Generalizar el disparador real para que lea este objeto requiere tocar el
    // prompt de _SYSTEM_PROMPT (Python) — deliberadamente fuera de alcance de C.3 (ver
    // discusión en el informe de C.3). Esta constante existe ya, lista para que una fase
    // posterior (C.4/C.5) la use al escribir esa sección del prompt.
    var TRUNCATE_THRESHOLDS = {
        Confundido: 40,   // colapso agresivo: reducir carga cognitiva
        Aburrido: 70,     // romper bloques densos sin fragmentar de más
        Frustrado: 100,   // solo lo muy extenso; la meta es calma lineal
        Concentrado: null, // sin truncado: no se le esconde nada
    };

    // Etiqueta del botón "Ver más"/"Ver menos" — parametrizable por emoción (mismo valor para
    // las tres por ahora, a propósito, ver PASO 3 de C.3). El texto real se pinta vía CSS
    // (.adaptive-truncate::after { content: var(--adaptive-truncate-label-*, ...) }, ver
    // styles.css) — este objeto es la fuente de verdad que un futuro caller usaría para fijar
    // esas CSS custom properties por elemento, en vez de que el texto quede fijo en el CSS.
    var TRUNCATE_LABELS = {
        Confundido: {collapsed: 'Ver más ▾', expanded: 'Ver menos ▴'},
        Aburrido:   {collapsed: 'Ver más ▾', expanded: 'Ver menos ▴'},
        Frustrado:  {collapsed: 'Ver más ▾', expanded: 'Ver menos ▴'},
    };

    return {
        PANEL_ID: PANEL_ID,
        FEEDBACK_ID: FEEDBACK_ID,
        COOLDOWN_MS: COOLDOWN_MS,
        PANEL_COLORS: PANEL_COLORS,
        GENERIC_MESSAGES: GENERIC_MESSAGES,
        FEEDBACK_FALLBACK: FEEDBACK_FALLBACK,
        DEFAULT_CSS: DEFAULT_CSS,
        GENERIC_RATIONALE_FALLBACK: GENERIC_RATIONALE_FALLBACK,
        emotionCooldown: emotionCooldown,
        TRUNCATE_THRESHOLDS: TRUNCATE_THRESHOLDS,
        TRUNCATE_LABELS: TRUNCATE_LABELS,
    };
});
