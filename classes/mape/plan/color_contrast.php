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
 * Fase MAPE-K: PLAN — aritmética de contraste WCAG 2.1 (2026-08-08).
 *
 * POR QUÉ EXISTE: `--emotion-primary` está sobrecargada en styles.css — es a la vez el FONDO de
 * la cabecera/navbar/botones y el COLOR DE TEXTO de títulos, enlaces y pestañas. Son requisitos
 * de contraste OPUESTOS y ninguna variable única puede satisfacer ambos: un azul pastel (que es
 * literalmente lo que la Tabla 24 pide para Frustrado) funciona como fondo pero es ilegible como
 * texto sobre blanco.
 *
 * Medido sobre las adaptaciones reales del log antes de esta clase: los 6 colores que el LLM
 * eligió para Frustrado daban entre 1.5:1 y 1.8:1 contra blanco, cuando WCAG AA exige 4.5:1 —
 * un tercio del mínimo. En total 13 de 17 colores distintos (las 4 emociones) incumplían AA. No
 * era un error del modelo: estaba obedeciendo la tabla. El defecto era de arquitectura del CSS.
 *
 * SOLUCIÓN: no se le quita al LLM la decisión de la paleta (la tabla manda). Su color se conserva
 * intacto como fondo, y de él se DERIVAN los dos colores de texto que faltaban. El oscurecimiento
 * se hace en HSL conservando tono y saturación, así que un azul pastel produce un azul profundo,
 * nunca un gris ni otro color: solo cambia la luminosidad, que es justo lo que la tabla no fija.
 *
 * Clase propia (no métodos privados de sanitizer.php) para poder testear la aritmética de forma
 * aislada con pares de colores de referencia conocidos.
 *
 * @see sanitizer::sanitize_css_vars() — único consumidor.
 */
class color_contrast {

    /** Umbral WCAG 2.1 nivel AA para texto de tamaño normal. */
    public const AA_NORMAL_TEXT = 4.5;

    /** Texto oscuro preferido (el mismo #1a1a1a que styles.css ya usaba a mano). */
    public const DARK_TEXT = '#1a1a1a';

    /** Texto claro. Es ya el extremo: no hay nada más claro que el blanco. */
    public const LIGHT_TEXT = '#ffffff';

    /**
     * Negro puro, de reserva para cuando DARK_TEXT no alcanza el umbral.
     *
     * Con un fondo de tono medio puede ocurrir que NI #1a1a1a NI el blanco lleguen a 4.5:1 —
     * medido con dos colores reales de Concentrado (#1a7ad9 → 4.35:1, #1a7cff → 4.45:1). El
     * negro puro cierra ese hueco por completo: el peor fondo posible es el que iguala ambos
     * contrastes, con luminancia L ≈ 0.179, y ahí el negro rinde (0.179+0.05)/0.05 = 4.58:1.
     * Es decir, negro o blanco SIEMPRE alcanzan AA, sea cual sea el fondo — no hace falta
     * tocar el color que eligió el LLM.
     */
    public const DARK_TEXT_MAX = '#000000';

    /** Paso de luminosidad HSL al oscurecer/aclarar. 2% da precisión suficiente sin iterar de más. */
    private const LIGHTNESS_STEP = 0.02;

    /**
     * Luminancia relativa según WCAG 2.1, con linealización sRGB.
     *
     * @param string $hex Color en formato #RRGGBB (con o sin almohadilla).
     * @return float Luminancia en [0, 1].
     */
    public static function relative_luminance(string $hex): float {
        [$r, $g, $b] = self::to_rgb($hex);
        $channels = [];
        foreach ([$r, $g, $b] as $value) {
            $v = $value / 255;
            $channels[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * Razón de contraste WCAG entre dos colores. Simétrica: ratio(a,b) === ratio(b,a).
     *
     * @param string $hex_a Primer color.
     * @param string $hex_b Segundo color.
     * @return float Razón en [1, 21]. 21 es negro contra blanco.
     */
    public static function ratio(string $hex_a, string $hex_b): float {
        $l1 = self::relative_luminance($hex_a);
        $l2 = self::relative_luminance($hex_b);
        if ($l1 < $l2) {
            [$l1, $l2] = [$l2, $l1];
        }
        return ($l1 + 0.05) / ($l2 + 0.05);
    }

    /**
     * Oscurece un color, conservando tono y saturación, hasta que alcance el contraste objetivo
     * contra un fondo dado. Si ya lo cumple, lo devuelve sin tocar.
     *
     * Conservar H y S es el punto de todo: el pastel de Frustrado se vuelve un azul profundo del
     * MISMO tono, no un gris. La identidad visual de la emoción sobrevive.
     *
     * @param string $hex    Color a oscurecer (#RRGGBB).
     * @param string $bg     Fondo contra el que debe leerse (#RRGGBB).
     * @param float  $target Contraste mínimo. Por defecto AA para texto normal.
     * @return string Color resultante en #RRGGBB minúsculas.
     */
    public static function darken_until_readable(string $hex, string $bg, float $target = self::AA_NORMAL_TEXT): string {
        // Se devuelve el valor ORIGINAL tal cual, sin normalizar: si no hay nada que corregir,
        // no se toca nada — ni siquiera las mayúsculas del hexadecimal. Normalizarlo "de paso"
        // rompía sanitizer_sanitize_test, y con razón: alterar un valor que el LLM eligió y que
        // ya cumple el umbral es un cambio de contrato sin ningún beneficio.
        if (self::ratio($hex, $bg) >= $target) {
            return $hex;
        }

        [$h, $s, $l] = self::to_hsl($hex);
        $candidate = self::normalize($hex);
        for ($step = $l - self::LIGHTNESS_STEP; $step >= 0; $step -= self::LIGHTNESS_STEP) {
            $candidate = self::from_hsl($h, $s, max(0.0, $step));
            if (self::ratio($candidate, $bg) >= $target) {
                return $candidate;
            }
        }
        // Ni el negro puro alcanza el objetivo — solo ocurre si el fondo también es muy oscuro.
        // Se devuelve lo más oscuro obtenido: es el mejor contraste posible conservando el tono,
        // y la guarda de --emotion-bg (ver sanitizer) impide que ese caso llegue en la práctica.
        return $candidate;
    }

    /**
     * Aclara un color conservando tono y saturación hasta alcanzar el contraste objetivo.
     * Contraparte de darken_until_readable(), usada para el fondo de página: el texto de cuerpo
     * de Moodle es oscuro y fijo, así que si el LLM eligiera un fondo oscuro habría que aclararlo.
     *
     * @param string $hex    Color a aclarar (#RRGGBB).
     * @param string $text   Color del texto que debe leerse encima (#RRGGBB).
     * @param float  $target Contraste mínimo.
     * @return string Color resultante en #RRGGBB minúsculas.
     */
    public static function lighten_until_readable(string $hex, string $text, float $target = self::AA_NORMAL_TEXT): string {
        // Igual que darken_until_readable(): si ya cumple, se devuelve intacto (ver ahí el porqué).
        if (self::ratio($hex, $text) >= $target) {
            return $hex;
        }

        [$h, $s, $l] = self::to_hsl($hex);
        $candidate = self::normalize($hex);
        for ($step = $l + self::LIGHTNESS_STEP; $step <= 1.0; $step += self::LIGHTNESS_STEP) {
            $candidate = self::from_hsl($h, $s, min(1.0, $step));
            if (self::ratio($candidate, $text) >= $target) {
                return $candidate;
            }
        }
        return $candidate;
    }

    /**
     * Elige el color de texto que se lee encima de un fondo dado, garantizando el umbral.
     *
     * Reemplaza la suposición que styles.css tenía escrita a mano ("Confundido y Aburrido usan
     * fondo oscuro, Frustrado usa pastel claro"): ese fondo lo elige el LLM en tiempo de
     * ejecución, así que la suposición podía fallar — y ya fallaba para Aburrido, que eligió
     * #FF8C00 (2.3:1) y recibía texto blanco encima.
     *
     * Prefiere #1a1a1a (más suave, coherente con el diseño previo) mientras cumpla el umbral;
     * si no, escala al extremo que más contraste dé. Ver DARK_TEXT_MAX: negro o blanco siempre
     * alcanzan AA sea cual sea el fondo, así que este método nunca devuelve algo ilegible.
     *
     * @param string $bg     Fondo (#RRGGBB).
     * @param float  $target Contraste mínimo.
     * @return string Color de texto en #rrggbb.
     */
    public static function best_text_on(string $bg, float $target = self::AA_NORMAL_TEXT): string {
        if (self::ratio(self::DARK_TEXT, $bg) >= $target) {
            return self::DARK_TEXT;
        }
        if (self::ratio(self::LIGHT_TEXT, $bg) >= $target) {
            return self::LIGHT_TEXT;
        }
        // Ninguno de los dos preferidos llega: se toma el extremo con más contraste.
        return self::ratio(self::DARK_TEXT_MAX, $bg) >= self::ratio(self::LIGHT_TEXT, $bg)
            ? self::DARK_TEXT_MAX
            : self::LIGHT_TEXT;
    }

    /**
     * Normaliza a #rrggbb en minúsculas. Acepta con o sin almohadilla.
     *
     * @param string $hex Color de entrada.
     * @return string Color normalizado.
     */
    public static function normalize(string $hex): string {
        return '#' . strtolower(ltrim(trim($hex), '#'));
    }

    /**
     * @param string $hex Color #RRGGBB.
     * @return array{0:int,1:int,2:int} Canales R, G, B en [0, 255].
     */
    private static function to_rgb(string $hex): array {
        $clean = ltrim(trim($hex), '#');
        return [
            hexdec(substr($clean, 0, 2)),
            hexdec(substr($clean, 2, 2)),
            hexdec(substr($clean, 4, 2)),
        ];
    }

    /**
     * @param string $hex Color #RRGGBB.
     * @return array{0:float,1:float,2:float} Tono en [0,360), saturación y luminosidad en [0,1].
     */
    private static function to_hsl(string $hex): array {
        [$r, $g, $b] = array_map(fn($c) => $c / 255, self::to_rgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $delta = $max - $min;

        if ($delta == 0.0) {
            return [0.0, 0.0, $l];
        }

        $s = $l > 0.5 ? $delta / (2 - $max - $min) : $delta / ($max + $min);
        if ($max == $r) {
            $h = 60 * fmod((($g - $b) / $delta), 6);
        } else if ($max == $g) {
            $h = 60 * ((($b - $r) / $delta) + 2);
        } else {
            $h = 60 * ((($r - $g) / $delta) + 4);
        }
        if ($h < 0) {
            $h += 360;
        }
        return [$h, $s, $l];
    }

    /**
     * @param float $h Tono en [0, 360).
     * @param float $s Saturación en [0, 1].
     * @param float $l Luminosidad en [0, 1].
     * @return string Color #rrggbb en minúsculas.
     */
    private static function from_hsl(float $h, float $s, float $l): string {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        if ($h < 60) {
            [$r, $g, $b] = [$c, $x, 0.0];
        } else if ($h < 120) {
            [$r, $g, $b] = [$x, $c, 0.0];
        } else if ($h < 180) {
            [$r, $g, $b] = [0.0, $c, $x];
        } else if ($h < 240) {
            [$r, $g, $b] = [0.0, $x, $c];
        } else if ($h < 300) {
            [$r, $g, $b] = [$x, 0.0, $c];
        } else {
            [$r, $g, $b] = [$c, 0.0, $x];
        }

        return sprintf(
            '#%02x%02x%02x',
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255)
        );
    }
}
