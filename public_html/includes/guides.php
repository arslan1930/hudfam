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
        . '<h3>2. Our database</h3>'
        . '<p><strong>What:</strong> Country folders shared with Admin.</p>'
        . '<p><strong>How:</strong> Open a country folder to browse that country’s URLs.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>3. Add history</h3>'
        . '<p><strong>What:</strong> Your daily batches of new sites.</p>'
        . '<p><strong>How:</strong> Open a day to copy or review what you added.</p>'
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
        'Browse sites by country. Each domain exists only once in the whole database.',
        'Pick a country folder. Download/view large lists; Admin can select & delete sites.',
        [
            'Open a country folder.',
            'Download all / View all names for 10k–100k site lists.',
            'Admin: select checkboxes to delete, or remove by filter / .txt list.',
        ]
    );
}

function guide_filter_add(): string
{
    return render_page_purpose(
        'Filter & add — global uniqueness',
        'Compare a pasted list to the entire database. Country only decides where new unique sites are saved.',
        'Select country (save into) → Paste → Clean list → Filter (all countries) → Add unique.',
        [
            'Select the country to save new sites into.',
            'Paste sites; click Clean list if there are https://, www., paths, or duplicates.',
            'Filter against all countries, then add the remaining unique sites into that country.',
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
        'Add sites — seed a country folder',
        'Paste root domains into a country folder (example.com / example.co.uk). Each domain can exist only once globally.',
        'Choose country, paste sites, Clean list if needed, then Save. Domains already anywhere in the database are skipped.',
        [
            'Select the country folder to save into.',
            'Paste sites; use Clean list to fix errors and remove duplicates.',
            'Save — then browse them under that country’s folder.',
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
        'Admin adds, edits, or removes teammates and sets their passwords. Team cannot change passwords.',
        'Create a team login, set a password, share it securely. Remove deletes the login; sites stay in Our database.',
        [
            'Create teammate with username + password.',
            'Edit to change details or reset password (blank = keep).',
            'Remove deletes the login; uncheck Active to disable without deleting.',
        ]
    );
}
