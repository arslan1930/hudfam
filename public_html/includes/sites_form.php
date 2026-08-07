<?php

/**
 * Shared country/language typeahead + domain paste helpers (admin + team).
 */

/**
 * @param list<array{value:string,label?:string,lang?:string,region?:string}> $items
 */
function render_typeahead_field(
    string $name,
    string $label,
    array $items,
    string $value = '',
    array $opts = []
): string {
    $id = (string) ($opts['id'] ?? $name);
    $required = !empty($opts['required']);
    $optional = !empty($opts['optional']);
    $placeholder = (string) ($opts['placeholder'] ?? 'Type to search, Enter to select');
    $help = (string) ($opts['help'] ?? 'Type to search, then press Enter to select.');
    $extraClass = (string) ($opts['class'] ?? '');
    $attrs = (string) ($opts['attrs'] ?? '');

    $jsonItems = [];
    foreach ($items as $it) {
        $jsonItems[] = [
            'value' => (string) ($it['value'] ?? ''),
            'label' => (string) ($it['label'] ?? $it['value'] ?? ''),
            'lang' => (string) ($it['lang'] ?? ''),
            'region' => (string) ($it['region'] ?? ''),
        ];
    }

    $reqMark = $required ? ' <span class="help">(required)</span>' : ($optional ? ' <span class="help">(optional)</span>' : '');
    $reqAttr = $required ? ' data-required="1"' : '';

    $displayValue = $value;
    if ($value !== '') {
        foreach ($jsonItems as $it) {
            if (strcasecmp((string) $it['value'], $value) === 0) {
                $displayValue = (string) $it['label'];
                break;
            }
        }
    }

    $html = '<div class="typeahead' . ($extraClass !== '' ? ' ' . h($extraClass) : '') . '" data-typeahead'
        . $reqAttr . ' data-name="' . h($name) . '" ' . $attrs . '>';
    $html .= '<label for="' . h($id) . '_q">' . h($label) . $reqMark . '</label>';
    $html .= '<input type="hidden" name="' . h($name) . '" id="' . h($id) . '" value="' . h($value) . '" data-typeahead-value'
        . ($required ? ' required' : '') . '>';
    $html .= '<div class="typeahead-control">';
    $html .= '<input type="text" id="' . h($id) . '_q" class="typeahead-input" value="' . h($displayValue) . '"'
        . ' placeholder="' . h($placeholder) . '" autocomplete="off" spellcheck="false" data-typeahead-input>';
    $html .= '<ul class="typeahead-list" hidden data-typeahead-list></ul>';
    $html .= '</div>';
    if ($help !== '') {
        $html .= '<p class="help typeahead-help">' . h($help) . '</p>';
    }
    $html .= '<script type="application/json" data-typeahead-items>' . json_encode(
        $jsonItems,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
    ) . '</script>';
    $html .= '</div>';
    return $html;
}

function render_country_typeahead(string $value = '', array $opts = []): string
{
    $counts = [];
    if (function_exists('prospect_country_folders')) {
        try {
            foreach (prospect_country_folders() as $f) {
                $counts[(string) ($f['country'] ?? '')] = (int) ($f['total'] ?? 0);
            }
        } catch (Throwable $e) {
            $counts = [];
        }
    }
    $items = [];
    $seen = [];
    foreach (list_countries(null, true) as $c) {
        $name = trim((string) ($c['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $key = mb_strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $region = (string) ($c['region'] ?? '');
        $code = strtoupper(trim((string) ($c['code'] ?? '')));
        $display = function_exists('prospect_folder_display_label')
            ? prospect_folder_display_label($name, $region, $code)
            : $name;
        $n = (int) ($counts[$name] ?? 0);
        $items[] = [
            'value' => $name,
            // no. of sites in front — Europe/NA show TLD (.de), others show country name
            'label' => $n . ' · ' . $display,
            'lang' => (string) ($c['default_language'] ?? ''),
            'region' => $region,
        ];
    }
    // Sort suggestions: most sites first
    usort($items, static function ($a, $b) {
        $na = (int) explode(' · ', (string) $a['label'], 2)[0];
        $nb = (int) explode(' · ', (string) $b['label'], 2)[0];
        if ($na !== $nb) {
            return $nb <=> $na;
        }
        return strcasecmp((string) $a['label'], (string) $b['label']);
    });
    return render_typeahead_field(
        (string) ($opts['name'] ?? 'country'),
        (string) ($opts['label'] ?? 'Country'),
        $items,
        $value,
        array_merge([
            'id' => 'country',
            'required' => true,
            'help' => 'Type to search (shows no. of sites). Europe/North America use .de / .us style labels.',
            'attrs' => 'data-fill-language="[data-name=language]" data-fill-region="select[name=region]"',
        ], $opts)
    );
}

function render_language_typeahead(string $value = '', array $opts = []): string
{
    $items = [];
    foreach (list_language_options() as $lang) {
        $items[] = ['value' => $lang, 'label' => $lang];
    }
    return render_typeahead_field(
        (string) ($opts['name'] ?? 'language'),
        (string) ($opts['label'] ?? 'Language'),
        $items,
        $value,
        array_merge([
            'id' => 'language',
            'optional' => true,
            'required' => false,
            'help' => 'Optional. Type to search, then press Enter to select.',
            'placeholder' => 'Optional — type to search, Enter to select',
        ], $opts)
    );
}

/**
 * Domains textarea + Clean errors control (root domains only).
 */
function render_domains_paste_field(
    string $name,
    string $value = '',
    array $opts = []
): string {
    $id = (string) ($opts['id'] ?? $name);
    $label = (string) ($opts['label'] ?? 'Sites (root domains)');
    $rows = (int) ($opts['rows'] ?? 14);
    $required = !empty($opts['required']);
    $class = (string) ($opts['class'] ?? '');
    $placeholder = (string) ($opts['placeholder'] ?? "example.com\nmy-site.co.uk");

    $html = '<div class="domains-paste" data-domains-paste>';
    $html .= '<div class="domains-paste-head">';
    $html .= '<label for="' . h($id) . '">' . h($label) . '</label>';
    $html .= '<button type="button" class="btn secondary small" data-clean-domains>Clean errors</button>';
    $html .= '</div>';
    $html .= '<textarea id="' . h($id) . '" name="' . h($name) . '" rows="' . $rows . '"'
        . ($required ? ' required' : '')
        . ($class !== '' ? ' class="' . h($class) . '"' : '')
        . ' placeholder="' . h($placeholder) . '" data-domains-input spellcheck="false">'
        . h($value) . '</textarea>';
    $html .= '<p class="help" style="margin-top:0.5rem">'
        . 'Root domain only — e.g. <code>example.com</code> or <code>my-site.co.uk</code>. '
        . 'Hyphens and multi-part TLDs are OK. '
        . 'One per line (or commas). Use <strong>Clean errors</strong> to correct '
        . '<code>https</code>, paths, and subdomains into root domains (unfixable lines are kept).'
        . '</p>';
    $html .= '<p class="domains-paste-status help" data-domains-status hidden></p>';
    $html .= '</div>';
    return $html;
}

function sites_form_script_tag(): string
{
    return '<script src="' . h(script_asset_url('js/sites-form.js')) . '" defer></script>';
}
