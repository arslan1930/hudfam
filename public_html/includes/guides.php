<?php
declare(strict_types=1);

/**
 * Simple in-app help: one shared database of URLs.
 */

function render_page_purpose(string $title, string $what, string $how, array $steps = []): string
{
    $stepsHtml = '';
    if ($steps !== []) {
        $items = '';
        foreach ($steps as $step) {
            $items .= '<li>' . h((string) $step) . '</li>';
        }
        $stepsHtml = '<ol class="page-purpose-steps">' . $items . '</ol>';
    }

    return '<aside class="page-purpose" aria-label="Page guide">'
        . '<div class="page-purpose-badge">What is this?</div>'
        . '<h2 class="page-purpose-title">' . h($title) . '</h2>'
        . '<p class="page-purpose-what"><strong>Purpose:</strong> ' . h($what) . '</p>'
        . '<p class="page-purpose-how"><strong>How it works:</strong> ' . h($how) . '</p>'
        . $stepsHtml
        . '</aside>';
}

function render_admin_panel_guide(): string
{
    return '<section class="panel-guide">'
        . '<h2>How Admin works</h2>'
        . '<p class="muted">Each country has its own URL database. You seed country folders; Team filters new lists against that country and adds only unique sites. Every add is saved in history.</p>'
        . '<div class="panel-guide-grid">'
        . '<article class="panel-guide-card">'
        . '<h3>1. Our database</h3>'
        . '<p><strong>What:</strong> Country folders — one URL list per country.</p>'
        . '<p><strong>How:</strong> Open a country, paste URLs into that country’s database.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>2. Add history</h3>'
        . '<p><strong>What:</strong> Who added which sites, by day.</p>'
        . '<p><strong>How:</strong> Open a day to see the domains that person added.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>3. Users</h3>'
        . '<p><strong>What:</strong> Admin and Team logins.</p>'
        . '<p><strong>How:</strong> Create Team accounts so they can filter and add sites.</p>'
        . '</article>'
        . '</div>'
        . '<div class="panel-guide-flow">'
        . '<h3>Flow</h3>'
        . '<ol>'
        . '<li>Admin opens a <strong>country folder</strong> and adds URLs.</li>'
        . '<li>Team picks the same country → pastes a list → duplicates for that country are removed.</li>'
        . '<li>Team adds the unique ones → they join that country’s database and <strong>Add history</strong>.</li>'
        . '</ol>'
        . '</div>'
        . '</section>';
}

function render_team_panel_guide(): string
{
    return '<section class="panel-guide">'
        . '<h2>How Team works</h2>'
        . '<p class="muted">Paste new sites for a country. Duplicates are removed privately (existing country lists stay hidden). Add only the new unique sites.</p>'
        . '<div class="panel-guide-grid">'
        . '<article class="panel-guide-card">'
        . '<h3>1. Filter &amp; add</h3>'
        . '<p><strong>What:</strong> Compare your paste to one country’s database without seeing that list.</p>'
        . '<p><strong>How:</strong> Select country → Paste → Filter → Add unique into that country.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>2. Add history</h3>'
        . '<p><strong>What:</strong> Your daily batches of new sites.</p>'
        . '<p><strong>How:</strong> Open a day to copy or review what you added.</p>'
        . '</article>'
        . '</div>'
        . '<div class="panel-guide-flow">'
        . '<h3>Flow</h3>'
        . '<ol>'
        . '<li>Open <strong>Filter &amp; add</strong> and select a country.</li>'
        . '<li>Paste domains and Filter (duplicates already in that country are removed privately).</li>'
        . '<li>Add the unique sites — they join that country’s database and today’s history.</li>'
        . '</ol>'
        . '</div>'
        . '</section>';
}

function guide_inventory(): string
{
    return render_page_purpose(
        'Our database — country folders',
        'Each country has its own site database. Admin opens a country folder to view or add sites.',
        'Pick a country folder, then browse or add sites for that country only.',
        [
            'Open a country folder.',
            'Add sites into that country’s database.',
            'Team can filter against the same country list.',
        ]
    );
}

function guide_filter_add(): string
{
    return render_page_purpose(
        'Filter & add — new unique sites only',
        'Paste a list and compare it privately against the existing country database. Existing URLs stay hidden; only new unique sites are shown so you can add them.',
        'Select country → Paste → Filter (known sites removed, list stays private) → Add new unique sites into that same country folder.',
        [
            'Select an existing country database (Germany, Spain, …).',
            'Paste root domains and Filter — duplicates are removed without showing the private country list.',
            'Add only the remaining new unique sites — duplicates never enter the database.',
        ]
    );
}

function guide_add_history(): string
{
    return render_page_purpose(
        'Add history — who added what',
        'Daily record of domains added by each person.',
        'Open a date/person to see the exact domains saved that day.',
        []
    );
}

function guide_admin_add(): string
{
    return render_page_purpose(
        'Add sites — seed a country database',
        'Paste root domains into one country’s folder (no prices). Optional language via search + Enter.',
        'Choose country (type + Enter), paste root domains, Clean errors if needed, save.',
        [
            'Select the country folder (type to search, Enter to select).',
            'Paste root domains only — no https, paths, or subdomains.',
            'Use Clean errors, then Save. Browse them under that country’s folder.',
        ]
    );
}

// Back-compat aliases used by older page snippets (if any remain)
function guide_admin_inventory(): string
{
    return guide_inventory();
}

function guide_team_filter(): string
{
    return guide_filter_add();
}

function guide_admin_users(): string
{
    return render_page_purpose(
        'Users — who can log in',
        'Create Admin and Team accounts.',
        'Team users can open Filter & add and grow Our database. Admins can add sites and view all history.',
        []
    );
}
