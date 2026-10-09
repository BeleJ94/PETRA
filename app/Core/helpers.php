<?php

/*
 * Petra — gestion et contrôle de stations-service.
 * Développé par BELEJ-CONSULTING.
 */

declare(strict_types=1);

use Petra\Core\Csrf;
use Petra\Core\I18n;
use Petra\Core\Url;

/**
 * Échappe une valeur pour une sortie HTML (texte ou attribut).
 * À utiliser pour TOUTE donnée affichée dans une vue.
 */
function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    if ($value instanceof BackedEnum) {
        $value = $value->value;
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Traduction via le catalogue i18n courant (non échappée : passer par e()). */
function t(string $key, array $params = []): string
{
    return I18n::current()->get($key, $params);
}

/** URL absolue depuis la racine de l'application (gère un sous-répertoire). */
function url(string $path = '/', array $query = []): string
{
    return Url::to($path, $query);
}

function asset(string $path): string
{
    return Url::asset($path);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::current()->token()) . '">';
}

function csrf_token(): string
{
    return Csrf::current()->token();
}

/** Encode des données pour un bloc <script type="application/json"> sans risque d'injection. */
function json_for_html(mixed $data): string
{
    return json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
}

function format_date(?string $value, bool $withTime = false): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '';
    }
    $format = I18n::current()->locale() === 'en'
        ? ($withTime ? 'Y-m-d H:i' : 'Y-m-d')
        : ($withTime ? 'd/m/Y H:i' : 'd/m/Y');

    return date($format, $ts);
}

/** Valeur à réafficher dans un champ : saisie précédente (après erreur) sinon valeur courante. */
function old(string $field, mixed $default = null): mixed
{
    return \Petra\Core\FormState::hasOld() ? \Petra\Core\FormState::old($field) : $default;
}

/** Message d'erreur de validation d'un champ, déjà traduit (non échappé). */
function field_error(string $field): ?string
{
    return \Petra\Core\FormState::error($field);
}

/** Libellé d'un statut / d'une énumération via le catalogue i18n. */
function label(string $group, ?string $value): string
{
    return $value === null || $value === '' ? '' : t($group . '.' . $value);
}

/** Attribut aria-current pour la navigation. */
function nav_current(string $currentPath, string $prefix): string
{
    $active = $prefix === '/' ? $currentPath === '/' : ($currentPath === $prefix || str_starts_with($currentPath, $prefix . '/'));

    return $active ? ' aria-current="page"' : '';
}

/** Nombre pour l'affichage uniquement (volumes, index) : aucun calcul ne passe par ici. */
function format_number(mixed $value, int $decimals = 0): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $fr = I18n::current()->locale() !== 'en';

    return number_format((float) $value, $decimals, $fr ? ',' : '.', $fr ? "\u{202F}" : ',');
}

/** Symbole d'une devise (CDF, USD). */
function currency_symbol(string $currency): string
{
    $code = strtoupper($currency);

    return ['CDF' => 'FC', 'USD' => '$'][$code] ?? $code;
}

/**
 * Montant stocké dans la plus petite unité (CDF entier, USD en cents), formaté sans passer par un float.
 */
function format_money(?int $amount, string $currency): string
{
    if ($amount === null) {
        return '';
    }
    $fr = I18n::current()->locale() !== 'en';
    $abs = abs($amount);
    $cents = strtoupper($currency) === 'USD';
    $text = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', $fr ? "\u{202F}" : ',', (string) ($cents ? intdiv($abs, 100) : $abs));
    if ($cents) {
        $text .= ($fr ? ',' : '.') . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    return ($amount < 0 ? '-' : '') . $text . ' ' . currency_symbol($currency);
}

/**
 * Attribut data-drill : ouvre, au clic, la fenêtre de détail (tableau filtrable + exports Excel / PDF).
 *
 * @param string      $table  définition de tableau (PTTables)
 * @param string      $source route JSON DataTables
 * @param array       $params filtres imposés (station_id…)
 * @param string|null $page   page complète équivalente (lien « Ouvrir la liste »)
 * @param string|null $row    lien d'une ligne (ex. /shifts/{id})
 * @param list<array{label: string, params: array}> $chips filtres rapides proposés dans la fenêtre
 */
function drill(string $table, string $source, string $title, array $params = [], ?string $page = null, ?string $row = null, array $chips = []): string
{
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
    $spec = [
        'table' => $table,
        'source' => url($source),
        'title' => $title,
        'params' => $params,
        'page' => $page !== null ? url($page, $params) : null,
        'row' => $row !== null ? url($row) : null,
        'chips' => $chips,
    ];

    return ' data-drill="' . e(json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
}

/**
 * Illustration décorative de public/assets/img/illus/ (dessin vectoriel servi localement).
 * Elle double toujours un texte : elle est donc masquée aux lecteurs d'écran.
 *
 * @param string $size sm | md | lg | wide
 */
function illustration(string $name, string $size = 'md'): string
{
    return '<img class="illus illus--' . e($size) . '" src="' . e(asset('img/illus/' . $name . '.svg')) . '" alt="" aria-hidden="true" loading="lazy">';
}

/**
 * Cuve dessinée : niveau de remplissage et repère du seuil de stock bas.
 *
 * @param int      $percent    remplissage, de 0 à 100
 * @param int|null $lowPercent position du seuil de stock bas, de 0 à 100
 */
function tank_visual(int $percent, ?int $lowPercent = null, bool $low = false, string $size = 'md', string $label = ''): string
{
    $percent = max(0, min(100, $percent));
    $mark = $lowPercent === null ? '' : '<span class="tankv__mark" style="bottom: ' . max(0, min(100, $lowPercent)) . '%"></span>';

    return '<span class="tankv tankv--' . e($size) . ($low ? ' tankv--low' : '') . '" role="img" aria-label="' . e($label !== '' ? $label : $percent . ' %') . '">'
        . '<span class="tankv__fill" style="height: ' . $percent . '%"></span>' . $mark . '</span>';
}

/**
 * Cuve de station-service, vue en coupe : cuve cylindrique enterrée sous la piste, avec son trou d'homme,
 * sa bouche de dépotage et son évent, le niveau de carburant et le repère du seuil de stock bas.
 * Dessin vectoriel en ligne, dimensionné par son conteneur ; tout est calculé en entiers.
 *
 * @param int|null $percent    remplissage de 0 à 100 ; null : cuve jamais jaugée (dessinée vide, sans niveau)
 * @param int|null $lowPercent position du seuil de stock bas, de 0 à 100
 */
function fuel_tank(?int $percent, ?int $lowPercent = null, bool $low = false, string $label = ''): string
{
    static $serial = 0;
    $id = 'tank' . (++$serial);
    $known = $percent !== null;
    $percent = max(0, min(100, (int) $percent));
    // Cuve : de y = 80 (haut) à y = 170 (bas), soit 90 unités de hauteur.
    $level = 170 - intdiv(90 * $percent, 100);
    $mark = $lowPercent === null ? null : 170 - intdiv(90 * max(0, min(100, $lowPercent)), 100);
    [$top, $bottom, $crest] = $low ? ['#E5766C', '#B42318', '#F6C6C1'] : ['#F5B25C', '#D9772F', '#FFE0B0'];
    $wave = $level + 3;
    $svg = '<svg class="fuel-tank' . ($low ? ' fuel-tank--low' : '') . '" viewBox="0 0 320 196" role="img" aria-label="' . e($label !== '' ? $label : $percent . ' %') . '" xmlns="http://www.w3.org/2000/svg">'
        . '<defs>'
        . '<clipPath id="' . $id . '-c"><rect x="32" y="80" width="256" height="90" rx="45"/></clipPath>'
        . '<linearGradient id="' . $id . '-f" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $top . '"/><stop offset="1" stop-color="' . $bottom . '"/></linearGradient>'
        . '<linearGradient id="' . $id . '-s" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#F7F9FB"/><stop offset=".5" stop-color="#E3E9F0"/><stop offset="1" stop-color="#C9D3DE"/></linearGradient>'
        . '<linearGradient id="' . $id . '-g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#E9E3D8"/><stop offset="1" stop-color="#DCD3C4"/></linearGradient>'
        . '</defs>'
        // Sol : remblai, puis la dalle de la piste.
        . '<rect x="0" y="60" width="320" height="136" fill="url(#' . $id . '-g)"/>'
        . '<g fill="#C9BFAE"><circle cx="18" cy="92" r="2"/><circle cx="300" cy="118" r="2.5"/><circle cx="24" cy="150" r="2.5"/><circle cx="304" cy="168" r="2"/><circle cx="12" cy="182" r="2"/><circle cx="160" cy="188" r="2"/><circle cx="92" cy="186" r="1.500"/><circle cx="236" cy="184" r="2"/></g>'
        . '<rect x="0" y="50" width="320" height="12" fill="#5B6573"/><rect x="0" y="50" width="320" height="3" fill="#7C8794"/>'
        . '<g fill="#F2C94C"><rect x="20" y="55" width="26" height="2.500" rx="1"/><rect x="274" y="55" width="26" height="2.500" rx="1"/></g>'
        // Berceaux de la cuve.
        . '<g fill="#8D97A3"><path d="M84 168h30l6 14H78z"/><path d="M206 168h30l6 14h-42z"/></g>'
        // Évent (à droite) et bouche de dépotage (à gauche).
        . '<g stroke="#3F556B" stroke-width="5" stroke-linecap="round" fill="none"><path d="M246 84V16"/><path d="M238 16h16"/></g>'
        . '<rect x="86" y="50" width="14" height="34" fill="#3F556B"/><rect x="80" y="42" width="26" height="9" rx="3" fill="#E0662B"/>'
        // Trou d'homme : rehausse et tampon au ras de la piste.
        . '<rect x="146" y="56" width="28" height="28" fill="#6F8094"/><rect x="138" y="46" width="44" height="9" rx="3" fill="#2F4257"/><rect x="150" y="43" width="20" height="4" rx="2" fill="#1B2A3A"/>'
        // Cuve : virole, carburant, reflets, cerclages.
        . '<rect x="32" y="80" width="256" height="90" rx="45" fill="url(#' . $id . '-s)"/>';
    if ($known && $percent > 0) {
        $svg .= '<g clip-path="url(#' . $id . '-c)">'
            . '<rect x="32" y="' . $level . '" width="256" height="' . (170 - $level) . '" fill="url(#' . $id . '-f)"/>'
            . '<path d="M32 ' . $wave . 'q16 -6 32 0t32 0 32 0 32 0 32 0 32 0 32 0 32 0V' . ($level - 4) . 'H32z" fill="url(#' . $id . '-s)"/>'
            . '<path d="M32 ' . $wave . 'q16 -6 32 0t32 0 32 0 32 0 32 0 32 0 32 0 32 0" fill="none" stroke="' . $crest . '" stroke-width="2"/>'
            . '</g>';
    }
    if ($mark !== null) {
        $svg .= '<g clip-path="url(#' . $id . '-c)"><line x1="32" y1="' . $mark . '" x2="288" y2="' . $mark . '" stroke="#B42318" stroke-width="2" stroke-dasharray="7 5"/></g>';
    }
    $svg .= '<g clip-path="url(#' . $id . '-c)"><rect x="32" y="84" width="256" height="9" fill="#fff" opacity=".45"/>'
        . '<g stroke="#3F556B" stroke-opacity=".28" stroke-width="2"><path d="M96 80v90M224 80v90"/></g></g>'
        . '<rect x="32" y="80" width="256" height="90" rx="45" fill="none" stroke="#3F556B" stroke-width="3"/>';
    // Pourcentage, lisible sur le carburant comme sur l'acier.
    if ($known) {
        $svg .= '<text x="160" y="134" text-anchor="middle" font-family="IBM Plex Sans, Segoe UI, Arial, sans-serif" font-size="30" font-weight="700" fill="#1B2430" stroke="#fff" stroke-width="5" stroke-linejoin="round" paint-order="stroke">' . $percent . ' %</text>';
    } else {
        $svg .= '<text x="160" y="132" text-anchor="middle" font-family="IBM Plex Sans, Segoe UI, Arial, sans-serif" font-size="26" font-weight="700" fill="#8D97A3">?</text>';
    }

    return $svg . '</svg>';
}

/**
 * Petite courbe de tendance (dessin vectoriel en ligne) à partir de valeurs entières, de la plus ancienne à la
 * plus récente. Les coordonnées sont calculées en entiers ; le dernier point est marqué.
 *
 * @param list<int> $values
 */
function sparkline(array $values, string $color = '#1F4E79'): string
{
    $count = count($values);
    if ($count < 2) {
        return '';
    }
    $min = min(0, min($values));
    $max = max(0, max($values));
    $span = max(1, $max - $min);
    $points = [];
    foreach (array_values($values) as $i => $value) {
        $points[] = [intdiv($i * 116, $count - 1) + 2, 30 - intdiv(($value - $min) * 26, $span)];
    }
    $line = implode(' ', array_map(static fn (array $p): string => $p[0] . ',' . $p[1], $points));
    [$x, $y] = $points[$count - 1];
    $zero = 30 - intdiv((0 - $min) * 26, $span);

    return '<svg class="sparkline" viewBox="0 0 120 34" preserveAspectRatio="none" aria-hidden="true">'
        . '<line x1="0" y1="' . $zero . '" x2="120" y2="' . $zero . '" stroke="#C4CAD2" stroke-width="1" stroke-dasharray="3 3" vector-effect="non-scaling-stroke"/>'
        . '<polyline points="' . $line . '" fill="none" stroke="' . e($color) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>'
        . '<circle cx="' . $x . '" cy="' . $y . '" r="2.500" fill="' . e($color) . '"/></svg>';
}
