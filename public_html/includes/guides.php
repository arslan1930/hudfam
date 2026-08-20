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
        . '<p><strong>How:</strong> Open Our database → Add sites (or open a country and paste there).</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>2. Extracted Sites</h3>'
        . '<p><strong>What:</strong> Sites pushed from Team Extracting Results.</p>'
        . '<p><strong>How:</strong> Open Extracted Sites → country → copy or remove.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>3. Emails data</h3>'
        . '<p><strong>What:</strong> Sites with emails - Admin, All sites with emails - Final, and Email campaign data (one sheet per country).</p>'
        . '<p><strong>How:</strong> Archives fill from Team Push. Create a country Email Sheet with site names + emails → Communication Team uses Admin emails search / Campaign search.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>4. Site adding history</h3>'
        . '<p><strong>What:</strong> Who added which sites, by day.</p>'
        . '<p><strong>How:</strong> Open a day to see the domains that person added.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>5. Users</h3>'
        . '<p><strong>What:</strong> Admin and Team logins.</p>'
        . '<p><strong>How:</strong> Create Team accounts so they can filter and add sites.</p>'
        . '</article>'
        . '</div>'
        . '<div class="panel-guide-flow">'
        . '<h3>Flow</h3>'
        . '<ol>'
        . '<li>Admin opens a <strong>country folder</strong> and adds URLs.</li>'
        . '<li>Team picks the same country → pastes a list → duplicates for that country are removed.</li>'
        . '<li>Team adds the unique ones → they join that country’s database and <strong>Site adding history</strong>.</li>'
        . '<li>Team <strong>Push</strong>es Extracting Results → <strong>Extracted Sites</strong> and <strong>Sites with emails - Team</strong>.</li>'
        . '<li>Team adds emails, then <strong>Push to Admin</strong> → <strong>Emails data → Sites with emails - Admin</strong> (also synced to <strong>All sites with emails - Final</strong>). Pushed rows leave the Team working copy.</li>'
        . '<li>Admin creates a <strong>country Email Sheet</strong> under Email campaign data; Communication Team uses <strong>Admin emails search</strong> / <strong>Campaign search</strong> and updates the matching country row.</li>'
        . '</ol>'
        . '</div>'
        . '</section>';
}

function render_team_panel_guide(): string
{
    return '<section class="panel-guide">'
        . '<h2>How Team works</h2>'
        . '<p class="muted">Paste new sites for a country. Duplicates are removed privately (existing country lists stay hidden). Add only the new unique sites — they go into the country database and Extracting sites.</p>'
        . '<div class="panel-guide-grid">'
        . '<article class="panel-guide-card">'
        . '<h3>1. Filter &amp; add</h3>'
        . '<p><strong>What:</strong> Compare your paste to one country’s database without seeing that list.</p>'
        . '<p><strong>How:</strong> Select country → Paste → Filter → Add unique into that country.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>2. Extracting sites</h3>'
        . '<p><strong>What:</strong> Per country: Sites list + Extracting Results.</p>'
        . '<p><strong>How:</strong> Paste results and <strong>Push</strong> → country TLDs route to their folders; generic TLDs stay in the selected country (Extracted Sites + Sites with emails - Team).</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>3. Sites with emails - Team</h3>'
        . '<p><strong>What:</strong> Site names from Extracting Results Push; add up to 4 emails each.</p>'
        . '<p><strong>How:</strong> Fill emails, then <strong>Push to Admin</strong> — they move to the Admin archive and clear from Team.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>4. Site adding history</h3>'
        . '<p><strong>What:</strong> Your daily batches of new sites.</p>'
        . '<p><strong>How:</strong> Open a day to copy or review what you added.</p>'
        . '</article>'
        . '</div>'
        . '<div class="panel-guide-flow">'
        . '<h3>Flow</h3>'
        . '<ol>'
        . '<li>Open <strong>Filter &amp; add</strong> and select a country.</li>'
        . '<li>Paste domains and Filter (duplicates already in that country are removed privately).</li>'
        . '<li>Add the unique sites — they join that country’s database and <strong>Extracting sites → Sites list</strong>.</li>'
        . '<li>Paste into <strong>Extracting Results</strong> and <strong>Push</strong> → Extracted Sites + Sites with emails - Team.</li>'
        . '<li>Add emails in <strong>Sites with emails - Team</strong>, then <strong>Push to Admin</strong>.</li>'
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
        'Paste a list and compare it privately against each destination country’s Our database. Country TLDs (.de, .at, .ch, …) route to their folders; .com/.net/.eu stay in the selected country. Existing URLs stay hidden; only new unique sites are shown.',
        'Select country → Paste → Filter unique sites (TLD route + per-country de-dupe) → Add or Separate. Extractors only see unique sites per country Sites list.',
        [
            'Select an existing country database (Germany, Spain, …) as the starting folder for generic TLDs.',
            'Paste root domains and Filter unique sites — .at/.ch/… go to Austria/Switzerland/… and duplicates in those Our databases are removed.',
            'Add only the remaining unique sites — each country folder and that country’s Extracting Sites list get only what is new there. Separate before Filter is Copy/Delete only.',
        ]
    );
}

function guide_extracting(): string
{
    return render_page_purpose(
        'Extracting sites — Sites list + Results',
        'Each country has its own batch with two boxes: Sites list and Extracting Results.',
        'A country batch is created only when a teammate adds new unique sites. Until then this page stays blank and waits.',
        [
            'Teammate uses Filter & add and saves new unique sites.',
            'Those sites appear here under Sites list for that country (editable textarea; edits autosave).',
            'Sites list tools: Copy, Undo, and Redo while you stay on this page.',
            'Paste into Extracting Results → Clean to root domains (Ready vs Needs attention) → Push uses Ready only. Country TLDs (.de, .at, .ch, …) route to their folders; .com/.net/.eu stay in the selected country.',
            'Sites list is filled only with domains that were unique in that country’s Our database after Filter & add (TLD-routed).',
            'Add emails in Sites with emails - Team, then Push to Admin for the final Sites with emails - Admin archive.',
        ]
    );
}

function guide_add_history(): string
{
    return render_page_purpose(
        'Site adding history — who added what',
        'Daily record of domains added by each person.',
        'Open a date/person to see the exact domains saved that day.',
        []
    );
}

function guide_admin_add(): string
{
    return render_page_purpose(
        'Add sites — inside Our database',
        'Paste root domains into one country’s folder in Our database. Extracted Sites are filled only when Team clicks Push.',
        'In Our database: choose country, paste root domains, Clean to root domains if needed, save.',
        [
            'Open Our database (sidebar).',
            'Use Add sites — select country, paste domains, Clean to root domains, save.',
            'Or open a country folder and add sites there.',
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
        'Create Admin and Team accounts. Temporary passwords are shown once; teammates must change them on first login.',
        'Assign Team users under Departments so they unlock tools. Admin email login needs a unique address (verify under Account). You cannot deactivate or demote yourself, or remove the last active admin.',
        []
    );
}
