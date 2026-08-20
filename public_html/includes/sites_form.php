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

    // Always show the canonical value in the input (e.g. "Germany").
    // Suggestion labels may include counts ("6 · Germany") for the dropdown only.
    $displayValue = $value;

    $html = '<div class="typeahead' . ($extraClass !== '' ? ' ' . h($extraClass) : '') . '" data-typeahead'
        . $reqAttr . ' data-name="' . h($name) . '" ' . $attrs . '>';
    $html .= '<label for="' . h($id) . '_q">' . h($label) . $reqMark . '</label>';
    // Single-line values only (country/language names). Multi-line lists must use render_hidden_multiline().
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
            // Site count in front, then country name (never TLD)
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
            'placeholder' => (string) ($opts['placeholder'] ?? 'Search country name…'),
            'help' => (string) ($opts['help'] ?? 'Type a country name. Suggestions show site count · country name.'),
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
 * Email text input with an in-box × clear control.
 * Use anywhere a site has email1…email4 style fields.
 *
 * @param array{
 *   id?:string,placeholder?:string,class?:string,attrs?:string,
 *   aria_label?:string,swe?:bool
 * } $opts
 */
function render_clearable_email_input(string $name, string $value = '', array $opts = []): string
{
    $id = trim((string) ($opts['id'] ?? ''));
    $placeholder = (string) ($opts['placeholder'] ?? '');
    $extraClass = trim((string) ($opts['class'] ?? ''));
    $attrs = trim((string) ($opts['attrs'] ?? ''));
    $formId = trim((string) ($opts['form'] ?? ''));
    $aria = (string) ($opts['aria_label'] ?? ('Clear email'));
    $swe = !empty($opts['swe']);
    $has = trim($value) !== '';

    $html = '<div class="email-field swe-email-field' . ($has ? ' has-value' : '') . '">';
    $html .= '<input type="text" inputmode="email" name="' . h($name) . '"'
        . ($id !== '' ? ' id="' . h($id) . '"' : '')
        . ($formId !== '' ? ' form="' . h($formId) . '"' : '')
        . ' class="' . h(trim('email-field-input ' . $extraClass)) . '"'
        . ' value="' . h($value) . '"'
        . ($placeholder !== '' ? ' placeholder="' . h($placeholder) . '"' : '')
        . ' spellcheck="false" autocomplete="off"'
        . ' data-email-input'
        . ($swe ? ' data-swe-email' : '')
        . ($attrs !== '' ? ' ' . $attrs : '')
        . '>';
    $html .= '<button type="button" class="email-field-clear swe-email-clear"'
        . ' data-email-clear data-swe-email-clear'
        . ' aria-label="' . h($aria) . '" title="Clear email"'
        . ($has ? '' : ' hidden')
        . '>&times;</button>';
    $html .= '</div>';
    return $html;
}

function email_field_clear_script_tag(): string
{
    return '<script src="' . h(script_asset_url('js/email-field-clear.js')) . '" defer></script>';
}

/**
 * Domains textarea + Clean to root domains (Ready vs Needs attention).
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
    $attentionId = $id . '_attention';

    $html = '<div class="domains-paste" data-domains-paste>';
    $html .= '<div class="domains-paste-head">';
    $html .= '<label for="' . h($id) . '">' . h($label) . '</label>';
    $html .= '<button type="button" class="btn secondary small" data-clean-domains title="Convert https/paths/subdomains to root domains; move unfixable lines aside">'
        . 'Clean to root domains</button>';
    $html .= '</div>';
    $html .= '<textarea id="' . h($id) . '" name="' . h($name) . '" rows="' . $rows . '"'
        . ($required ? ' required' : '')
        . ($class !== '' ? ' class="' . h($class) . '"' : '')
        . ' placeholder="' . h($placeholder) . '" data-domains-input spellcheck="false">'
        . h($value) . '</textarea>';
    $html .= '<p class="help" style="margin-top:0.5rem">'
        . 'Root domain only — e.g. <code>example.com</code> or <code>my-site.co.uk</code>. '
        . 'Hyphens and multi-part TLDs are OK. One per line (or commas). '
        . '<strong>Clean to root domains</strong> fixes <code>https</code>/paths/subdomains into the Ready list; '
        . 'lines it cannot fix move to <strong>Needs attention</strong> (Push uses Ready only).'
        . '</p>';
    $html .= '<p class="domains-paste-status help" data-domains-status hidden></p>';
    $html .= '<div class="domains-paste-attention" data-domains-attention-wrap hidden>';
    $html .= '<label for="' . h($attentionId) . '">Needs attention</label>';
    $html .= '<textarea id="' . h($attentionId) . '" rows="4" class="domains-attention-box" '
        . 'data-domains-attention spellcheck="false" placeholder="Unfixable lines appear here after Clean"></textarea>';
    $html .= '<p class="help" style="margin:0.35rem 0 0">Edit or delete these, then Clean again — or leave them; Push / Separate only use the Ready list above.</p>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

function sites_form_script_tag(): string
{
    return '<script src="' . h(script_asset_url('js/sites-form.js')) . '" defer></script>';
}

/**
 * https:// URL for opening a domain or full URL in a new tab, or '' if not openable.
 */
function open_site_url_for_domain(string $domain): string
{
    $raw = trim($domain);
    if ($raw === '') {
        return '';
    }
    // Full URLs (order LIVE links, etc.) — open as given after light cleanup.
    if (preg_match('#^https?://#i', $raw)) {
        $raw = preg_replace('/\s+/', '', $raw) ?? $raw;
        return $raw;
    }
    $host = function_exists('extract_host_candidate')
        ? extract_host_candidate($raw)
        : strtolower($raw);
    $root = function_exists('to_root_domain') ? to_root_domain($host) : $host;
    if ($root !== '' && (!function_exists('is_root_domain') || is_root_domain($root))) {
        return 'https://' . $root;
    }
    $host = strtolower(trim(preg_replace('#^www\.#i', '', $host) ?? $host));
    if ($host === '' || !str_contains($host, '.') || preg_match('/\s/', $host)) {
        return '';
    }
    if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
        return '';
    }
    return 'https://' . $host;
}

/**
 * Compact Open link (optionally next to an editable host input).
 *
 * @param array{class?:string,label?:string} $opts
 */
function render_open_site_anchor(string $domain, array $opts = []): string
{
    $label = (string) ($opts['label'] ?? 'Open');
    $extraClass = trim((string) ($opts['class'] ?? ''));
    $url = open_site_url_for_domain($domain);
    $class = trim('swe-open-site open-site-link ' . $extraClass);
    if ($url === '') {
        return '<a class="' . h($class) . ' is-disabled" data-open-site href="#" tabindex="-1" aria-disabled="true"'
            . ' data-open-host="' . h(strtolower(trim($domain))) . '"'
            . ' title="Fix the site name (needs a valid domain) before opening"'
            . ' aria-label="Site name invalid — cannot open">' . h($label) . '</a>';
    }
    $host = preg_replace('#^https://#i', '', $url) ?? $url;
    return '<a class="' . h($class) . '" data-open-site href="' . h($url) . '"'
        . ' data-open-host="' . h($host) . '"'
        . ' target="_blank" rel="noopener noreferrer"'
        . ' title="Open ' . h($host) . ' in a new tab"'
        . ' aria-label="Open ' . h($host) . ' in a new tab">' . h($label) . '</a>';
}

function open_site_script_tag(): string
{
    return '<script src="' . h(script_asset_url('js/open-site.js')) . '" defer></script>';
}
