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
        . '<p class="muted">Country folders are for browsing and saving. Each domain exists only once in the whole database. Team filters against all countries, then saves unique sites into the selected country.</p>'
        . '<div class="panel-guide-grid">'
        . '<article class="panel-guide-card">'
        . '<h3>1. Our database</h3>'
        . '<p><strong>What:</strong> Country folders for browsing sites.</p>'
        . '<p><strong>How:</strong> Open a country and add sites into that folder (global uniqueness still applies).</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>2. Site adding history</h3>'
        . '<p><strong>What:</strong> Who added which sites, by day.</p>'
        . '<p><strong>How:</strong> Open a day to edit, copy/cut, or delete that history.</p>'
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
        . '<li>Team picks a country to save into → pastes a list → Filter removes domains already anywhere in the database.</li>'
        . '<li>Team adds the unique ones → they join that country folder and <strong>Add history</strong>.</li>'
        . '</ol>'
        . '</div>'
        . '</section>';
}

function render_team_panel_guide(): string
{
    return '<section class="panel-guide">'
        . '<h2>How Team works</h2>'
        . '<p class="muted">Pick a country to save into, paste new sites, Filter against the whole database, then add only globally unique sites into that country.</p>'
        . '<div class="panel-guide-grid">'
        . '<article class="panel-guide-card">'
        . '<h3>1. Filter &amp; add</h3>'
        . '<p><strong>What:</strong> Compare your paste to the entire database.</p>'
        . '<p><strong>How:</strong> Select country (save into) → Paste → Filter (all countries) → Add unique.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>2. Site adding history</h3>'
        . '<p><strong>What:</strong> Your daily batches of new sites (read-only).</p>'
        . '<p><strong>How:</strong> Open a day to copy or review what you added.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>3. Change password</h3>'
        . '<p><strong>What:</strong> Keep your login secure.</p>'
        . '<p><strong>How:</strong> Use Change password in the sidebar (required after demo passwords).</p>'
        . '</article>'
        . '</div>'
        . '<div class="panel-guide-flow">'
        . '<h3>Flow</h3>'
        . '<ol>'
        . '<li>Open <strong>Filter &amp; add</strong> and select the country to save into.</li>'
        . '<li>Paste domains and Filter (duplicates already anywhere in the database are removed).</li>'
        . '<li>Add the unique sites — they join that country folder and today’s history.</li>'
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
        'Filter & add — per country database',
        'Compare a pasted list to one country’s site database and save only root domains that country does not already have.',
        'Select country (type + Enter) → Paste root domains → Clean errors if needed → Filter → Add unique.',
        [
            'Select the country database (type to search, Enter to select).',
            'Paste root domains only (example.com, my-site.co.uk) and Clean errors if needed.',
            'Filter, then add the remaining unique sites to that country.',
        ]
    );
}

function guide_add_history(): string
{
    return render_page_purpose(
        'Site adding history — who added what',
        'Daily record of domains added by each person. Admins can edit or delete a day; Team views their own days read-only.',
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
