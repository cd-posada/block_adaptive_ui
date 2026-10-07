# block_adaptive_ui — Módulo de Adaptación Visual por Emoción

**Trabajo de Grado — Ingeniería Informática**
**Autor:** Miguel Ángel Salazar Rincón
**Institución:** Politécnico Colombiano Jaime Isaza Cadavid, Medellín, Colombia
**Año:** 2025

---

## Descripción

Plugin de bloque para Moodle 4.4 que adapta dinámicamente la interfaz gráfica
según la emoción detectada del estudiante, **sin recargar la página**.

Implementa el ciclo **MAPE-K** (Monitor, Analyze, Plan, Execute, Knowledge). Ver `CLAUDE.md`
en este mismo directorio para el detalle completo; esta tabla es el mapeo rápido fase→archivo:

| Fase | Archivo | Responsabilidad |
|------|---------|--------|
| **M**onitor | `classes/external/get_adaptation.php` → `mape/monitor/emotion_window_collector.php` | Lee `mdl_local_emotions_log`, ventana `WINDOW_SECONDS` (90s) |
| **A**nalyze | `classes/external/get_adaptation.php` → `mape/analyze/dominant_emotion_calculator.php` | Emoción dominante, tendencia, umbral adaptativo — pura lectura de BD, sin LLM |
| **P**lan | `classes/external/apply_adaptation.php` → `mape/plan/strategy_dispatcher.php` → `adaptation_service` (Python, `plan_engine.py`) | Único punto donde se llama al LLM — solo al clic en "Activar cambios" |
| **E**xecute | `amd/src/adaptive_monitor.js` → `mape/execute/{dom_applier,dom_reverter,transition_manager,explainer,panels}.js` (B.3) | Aplica `css_vars`/`dom_actions`; retroalimentación vía `empathic_message`, incluido en la respuesta de `apply_adaptation` desde B.0.2 (`get_llm_feedback.php` eliminado en B.0.4) |
| **K**nowledge | `classes/external/record_action.php` → `mape/knowledge/{prefs_repository,effectiveness_repository}.php`; `report.php` → `mape/knowledge/report_data_provider.php` | Guarda `activated`/`closed` en `mdl_block_adaptive_ui_prefs`, ajusta el umbral futuro; abre fila de efectividad, completada por `get_adaptation.php` en el siguiente poll — ver `report.php` para el reporte docente |

Desde las Fases B.1/B.2 (2026-07-22), `classes/external/*.php` y `report.php` son fachadas
delgadas — la lógica de negocio real vive en `classes/mape/{monitor,analyze,plan,knowledge}/`
(`execute/` pendiente, Fase B.3). Ver CLAUDE.md → "Estructura de archivos" para el árbol
completo.

El microservicio Python se llamaba `fis_service` (usaba un FIS Mamdani); ese motor fue
eliminado el 2026-07-02 y reemplazado por un LLM, y el directorio se renombró a
`adaptation_service` el 2026-07-05.

---

## Emociones y adaptaciones

| Emoción detectada | Equivalente académico | Adaptación visual |
|-------------------|-----------------------|-------------------|
| `Concentrado`     | engagement            | UI estándar de Moodle — refuerzo positivo sutil, sin LLM |
| `Confundido`      | confusion             | Azules claros, fuente ampliada, ayuda visible |
| `Frustrado`       | frustration           | Paleta ultra-limpia, layout zen, simplificación total |
| `Aburrido`        | boredom               | Colores vibrantes, tipografía dinámica, estímulos |

---

## Arquitectura

```
[mdl_local_emotions_log] ← escrito por local_emotions_send (plugin existente)
         ↓ (polling cada 5s — Monitor+Analyze, SIN LLM)
[classes/external/get_adaptation.php] ← web service AJAX Moodle
         ↓ "recommend" → panel genérico → clic "Activar cambios"
[classes/external/apply_adaptation.php] ← única llamada al LLM (Plan)
         ↓ HTTP POST /adapt
[adaptation_service] en localhost:5001 ← microservicio Python (LLM, OpenRouter)
         ↓ css_params JSON (css_vars + dom_actions)
[amd/src/adaptive_monitor.js] ← módulo AMD (Execute)
         ↓ style.setProperty() + DOM actions
[:root CSS custom properties + DOM] ← cambio instantáneo, sin reload
         ↓
[classes/external/record_action.php] ← Knowledge: guarda decisión del estudiante
```

---

## Dependencias

- **Moodle 4.4+** (Build 2024042200+)
- Plugin `local_emotions_send` instalado y con datos en `mdl_local_emotions_log`
- Microservicio `adaptation_service` ejecutándose en `localhost:5001`

---

## Instalación

```bash
# 1. Copiar carpeta en el directorio de bloques de Moodle
cp -r blocks/adaptive_ui /path/to/moodle/blocks/

# 2. Iniciar el microservicio de adaptación
cd adaptation_service && uvicorn main:app --port 5001

# 3. Instalar el plugin en Moodle
#    Ir a: Site Administration → Notifications → Upgrade Moodle database

# 4. Purgar caches
#    Ir a: Site Administration → Development → Purge all caches
#    (repetir este paso en CUALQUIER deploy futuro que mueva o renombre archivos bajo
#    classes/ — no solo en la instalación inicial; ver CLAUDE.md → "Flujo de despliegue
#    estándar")

# 5. Añadir el bloque a un curso
#    Curso → Activar edición → Add a block → "Adaptación Visual por Emoción"
```

---

## Estructura de archivos

```
blocks/adaptive_ui/
├── block_adaptive_ui.php          # Clase principal del bloque (pasa courseid a init())
├── report.php                     # Fachada de reporte docente (Knowledge) — ?courseid=X
├── version.php                    # Versión y dependencias
├── styles.css                     # CSS variables base
├── LICENSE                        # GNU GPL v3
├── README.md                      # Este archivo
├── CLAUDE.md                      # Documentación detallada del ciclo MAPE-K
├── lang/{es,en}/block_adaptive_ui.php  # Cadenas de idioma
├── db/
│   ├── access.php                 # Capacidades Moodle: addinstance, myaddinstance, viewreports
│   ├── services.php               # Registro de los 4 web services AJAX
│   ├── caches.php                 # Caché MUC (llmresult)
│   ├── upgrade.php                # Migraciones (fis_url→adaptation_url, tabla effectiveness)
│   └── install.xml                # Esquema: mdl_block_adaptive_ui_prefs + effectiveness
├── classes/
│   ├── external/                  # Fachadas de web service — validan/orquestan, delegan en mape/
│   │   ├── get_adaptation.php     # Monitor+Analyze+Knowledge
│   │   ├── apply_adaptation.php   # Plan — única llamada LLM, solo al aceptar
│   │   └── record_action.php      # Knowledge
│   ├── mape/                      # Lógica de negocio real (Fases B.1/B.2/B.3): monitor/analyze/plan/knowledge/execute
│   └── privacy/provider.php       # Cumplimiento Ley 1581 / GDPR
├── pix/icon.png                   # Ícono del bloque (128×128)
├── amd/
│   ├── src/adaptive_monitor.js    # Fase Monitor (poll) + Execute (panel, DOM, feedback)
│   └── build/adaptive_monitor.min.js
└── tests/
    ├── behat/block_adaptive_ui.feature
    ├── privacy/provider_test.php
    └── manual/
        ├── verify_analyze_window.php  # Verificación manual Monitor+Analyze (sin PHPUnit)
        ├── verify_effectiveness.php   # Verificación manual emotion_before/after (Fase 7)
        └── verify_b0_merge.php        # Verificación end-to-end de la fusión B.0
```

---

## Privacidad y datos biométricos

Este plugin **no almacena datos personales**. Solo lee registros ya existentes en
`mdl_local_emotions_log` (responsabilidad del plugin `local_emotions_send`).

Cumplimiento: **Ley 1581 de 2012** (Colombia) — protección de datos personales y biométricos.
Ver: `classes/privacy/provider.php`

---

## Licencia

GNU General Public License v3.0 — ver archivo [LICENSE](LICENSE)

---

## Referencia académica

> Salazar Rincón, M. Á. (2025). *Módulo de adaptación inteligente de interfaces gráficas
> de usuario basado en emociones académicas para la plataforma Polivirtual (Moodle)*.
> Trabajo de Grado, Ingeniería Informática.
> Politécnico Colombiano Jaime Isaza Cadavid, Medellín, Colombia.
