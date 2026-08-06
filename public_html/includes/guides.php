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
        . '<p class="muted">One shared database of website URLs. You seed it; Team filters new lists against it and adds only unique sites. Every add is saved in history.</p>'
        . '<div class="panel-guide-grid">'
        . '<article class="panel-guide-card">'
        . '<h3>1. Our database</h3>'
        . '<p><strong>What:</strong> The master list of unique domains (URLs).</p>'
        . '<p><strong>How:</strong> Paste URLs to add them. Team sees the same list when filtering.</p>'
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
        . '<li>Admin adds URLs into <strong>Our database</strong>.</li>'
        . '<li>Team pastes a new list → system removes domains already in the database.</li>'
        . '<li>Team adds the unique ones → they join the database and appear in <strong>Add history</strong>.</li>'
        . '</ol>'
        . '</div>'
        . '</section>';
}

function render_team_panel_guide(): string
{
    return '<section class="panel-guide">'
        . '<h2>How Team works</h2>'
        . '<p class="muted">You use the shared URL database. Paste new sites, remove ones we already have, then add only the unique ones. Your adds are saved in history.</p>'
        . '<div class="panel-guide-grid">'
        . '<article class="panel-guide-card">'
        . '<h3>1. Filter &amp; add</h3>'
        . '<p><strong>What:</strong> Compare your paste to Our database.</p>'
        . '<p><strong>How:</strong> Paste domains → Filter → Add unique. New sites go into the database.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>2. Our database</h3>'
        . '<p><strong>What:</strong> Browse all unique domains already saved.</p>'
        . '<p><strong>How:</strong> Search or filter the list Admin and Team have built together.</p>'
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
        . '<li>Open <strong>Filter &amp; add</strong>.</li>'
        . '<li>Paste new domains and run Filter (duplicates already in the database are removed).</li>'
        . '<li>Add the unique sites — they join Our database and today’s history.</li>'
        . '</ol>'
        . '</div>'
        . '</section>';
}

function guide_inventory(): string
{
    return render_page_purpose(
        'Our database — unique website URLs',
        'The shared list of domains. Admin seeds it; Team adds more after filtering.',
        'Search or browse domains here. New sites from Filter & add land in this same list.',
        [
            'Admin pastes URLs to grow the list.',
            'Team filters new pastes against this list.',
            'Only unique domains are kept.',
        ]
    );
}

function guide_filter_add(): string
{
    return render_page_purpose(
        'Filter & add — keep only new sites',
        'Compare a pasted list to Our database and save only domains we do not already have.',
        'Paste → Filter → Add unique. Added sites join the database and today’s add history.',
        [
            'Paste domains (one per line).',
            'Filter to drop ones already in Our database.',
            'Add the remaining unique sites.',
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
        'Add URLs — seed Our database',
        'Paste URLs or domains into the shared database (no prices).',
        'Paste and save in one step. No uniqueness preview — URLs are stored for Team filtering later.',
        [
            'Paste URLs or domains.',
            'Click Save to Our database.',
            'Browse them under Our database.',
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
        'Team users can open Filter & add and grow Our database. Admins can add URLs and view all history.',
        []
    );
}
