<?php
declare(strict_types=1);

/**
 * In-app help copy: what each area is for and how to use it.
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
        . '<h2>How the Admin panel works</h2>'
        . '<p class="muted">Hudfam is a linkbuilding inventory tool. Admin builds the priced catalog and email lists; Team searches, filters, and cuts emails into packs.</p>'
        . '<div class="panel-guide-grid">'
        . '<article class="panel-guide-card">'
        . '<h3>1. Catalog</h3>'
        . '<p><strong>What:</strong> Your priced website list, organized by <em>project → country</em>.</p>'
        . '<p><strong>How:</strong> Open a project folder, then a country sheet. Add domains with prices, DR, traffic, language. Team only sees sites for the project they pick.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>2. Projects</h3>'
        . '<p><strong>What:</strong> Client or campaign folders (e.g. Hostinger UK). Each project owns its own country sheets.</p>'
        . '<p><strong>How:</strong> Create a project, then add sites under Catalog for that project + country. Use Send pack to email a shortlist to a client.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>3. Bulk import</h3>'
        . '<p><strong>What:</strong> Paste many domains at once into one project + country sheet.</p>'
        . '<p><strong>How:</strong> Choose project and country first, paste domains (optional price/DR columns), import. Rows land in that project’s country sheet.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>4. Our inventory</h3>'
        . '<p><strong>What:</strong> Company-wide unique domains with no prices — for Team “Filter &amp; add”.</p>'
        . '<p><strong>How:</strong> Upload or paste domains here. Separate from Catalog prices. Team filters this list and copies results.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>5. Email campaigns</h3>'
        . '<p><strong>What:</strong> URL + email lists by country for outreach (Ready → Cut).</p>'
        . '<p><strong>How:</strong> Create a campaign, set country, import contacts. Team uses Email cut to take N ready emails and mark them Cut.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>6. Users</h3>'
        . '<p><strong>What:</strong> Admin and Team logins.</p>'
        . '<p><strong>How:</strong> Create teammates so they can use Catalog search, Filter &amp; add, and Email cut.</p>'
        . '</article>'
        . '</div>'
        . '<div class="panel-guide-flow">'
        . '<h3>Typical Admin flow</h3>'
        . '<ol>'
        . '<li>Create a <strong>Project</strong> for the client.</li>'
        . '<li>Open <strong>Catalog</strong> → that project → add country sheets and priced sites (or use Bulk import).</li>'
        . '<li>Optionally fill <strong>Our inventory</strong> (no prices) and <strong>Email campaigns</strong> (URL+email).</li>'
        . '<li>Team picks the same project + country in Catalog search to find and add sites.</li>'
        . '</ol>'
        . '</div>'
        . '</section>';
}

function render_team_panel_guide(): string
{
    return '<section class="panel-guide">'
        . '<h2>How the Team panel works</h2>'
        . '<p class="muted">You search the Admin catalog, filter company inventory, and cut email contacts into packs. You do not set catalog prices — Admin does that.</p>'
        . '<div class="panel-guide-grid">'
        . '<article class="panel-guide-card">'
        . '<h3>1. Catalog search</h3>'
        . '<p><strong>What:</strong> Look up domains inside one client project and country (with language).</p>'
        . '<p><strong>How:</strong> Select Project → Country → Language (saved when you refresh). Search a domain. If it exists you see price/metrics; if not, add it to that project’s country sheet.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>2. Filter &amp; add</h3>'
        . '<p><strong>What:</strong> Filter Admin’s “Our inventory” (unique domains, no prices).</p>'
        . '<p><strong>How:</strong> Paste domains, remove ones already known, then save new unique sites to inventory and today’s batch.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>3. Cut replied emails</h3>'
        . '<p><strong>What:</strong> Remove replied / dealing emails from the Ready send list so they are not emailed again.</p>'
        . '<p><strong>How:</strong> Paste emails that replied, mark Replied/Dealing/Do not email. Or browse Country sheets and update status there.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>4. Projects &amp; Results</h3>'
        . '<p><strong>What:</strong> Your assigned client projects and pitch outcomes (agreed/rejected).</p>'
        . '<p><strong>How:</strong> Open a project to work its catalog. Check Results for client feedback so you can refill better sites.</p>'
        . '</article>'
        . '</div>'
        . '<div class="panel-guide-flow">'
        . '<h3>Typical Team flow</h3>'
        . '<ol>'
        . '<li><strong>Catalog search:</strong> Project + country + language → search/add domains for that client sheet.</li>'
        . '<li><strong>Filter &amp; add:</strong> Paste domains, keep only uniques in Our inventory.</li>'
        . '<li><strong>Cut replied emails:</strong> Paste emails that replied so they leave the Ready list.</li>'
        . '</ol>'
        . '</div>'
        . '</section>';
}

function guide_admin_catalog(): string
{
    return render_page_purpose(
        'Catalog — priced sites by project & country',
        'This is the main priced inventory. Each project has its own country sheets (folders). Sites here are what Team finds in Catalog search.',
        'Click a project → click a country (or create one) → manage domains on that sheet. Use “Add site” or Bulk import. Optional Global catalogs are company-wide by country only, not tied to a project.',
        [
            'Open a project folder.',
            'Open or create a country sheet for that project.',
            'Add or edit domains (price, DR, traffic, language, status).',
            'Team selects the same project + country in Catalog search to use this list.',
        ]
    );
}

function guide_admin_projects(): string
{
    return render_page_purpose(
        'Projects — client / campaign folders',
        'A project groups catalog sites for one client or campaign. Country sheets live under each project in Catalog.',
        'Create a project, then add sites via Catalog or Bulk import with that project selected. Open a project to see its sites, build packs, and Send pack to the client.',
        [
            'Create a project (name + optional notes).',
            'Add priced sites under Catalog for this project + each country you need.',
            'Use Send pack when a shortlist is ready for the client.',
        ]
    );
}

function guide_admin_bulk_import(): string
{
    return render_page_purpose(
        'Bulk import — many domains into one sheet',
        'Fast way to load many priced (or draft) domains into one project’s country sheet.',
        'Choose project and country (and language if needed), upload a CSV, then import. Everything goes into that project + country in Catalog.',
        [
            'Select project and country first.',
            'Upload CSV (optional columns: price, DR, traffic, etc.).',
            'Import — then review the sheet under Catalog.',
        ]
    );
}

function guide_admin_inventory(): string
{
    return render_page_purpose(
        'Our inventory — unique domains (no prices)',
        'Company-wide domain list without guest-post prices. Used by Team Filter & add — not the same as Catalog.',
        'Team pastes domains into Filter & add; unique ones land here. Keep Catalog for priced project work; keep Our inventory for broad unique-domain lists.',
        [
            'Review domains added by Team (or seed the list yourself).',
            'Team filters new pastes against this list under Filter & add.',
            'Do not expect prices here — prices belong in Catalog.',
        ]
    );
}

function guide_admin_email_campaigns(): string
{
    return render_page_purpose(
        'Email campaigns — outreach contacts by country',
        'Stores website URL + email rows for outreach. Team cuts replied emails so contacts are not emailed again.',
        'Import URL+email rows into country sheets. Status starts Ready. When someone replies, Team marks Replied/Dealing and the contact leaves the Ready send list.',
        [
            'Open or create a country sheet.',
            'Import contacts (URL + email).',
            'Team cuts replied emails so Ready stays clean for the next send.',
        ]
    );
}

function guide_admin_users(): string
{
    return render_page_purpose(
        'Users — who can log in',
        'Manage Admin and Team accounts for Hudfam.',
        'Create Team users so they can use Catalog search, Filter & add, and Cut replied emails. Admins can manage catalog, projects, inventory, and campaigns.',
        []
    );
}

function guide_admin_global_catalog(): string
{
    return render_page_purpose(
        'Global country catalogs (optional)',
        'Company-wide lists by country only — not tied to a client project. Separate from project Catalog sheets.',
        'Use when you want a shared country list outside any project. For client work, prefer project → country under normal Catalog.',
        [
            'Open Catalog with Global mode.',
            'Pick a country sheet.',
            'Add domains to that global country list.',
        ]
    );
}

function guide_team_search(): string
{
    return render_page_purpose(
        'Catalog search — find or add sites for a project',
        'Search Admin’s priced Catalog inside one project and country. Your Project / Country / Language choice is remembered when you refresh.',
        'Select project, then country, then language. Search a domain. If found, you see details; if not, you can add it to that project’s country sheet.',
        [
            'Choose Project → Country → Language (required).',
            'Search the domain.',
            'Use existing match or add a new site to this sheet.',
        ]
    );
}

function guide_team_filter(): string
{
    return render_page_purpose(
        'Filter & add — unique domains into Our inventory',
        'Paste domains, remove ones already in Our inventory, then save only new unique sites (no prices). Different from Catalog search (priced, per project).',
        'Paste a list → Filter → Add unique. New domains go to Our inventory and today’s dated batch.',
        [
            'Paste domains under “Paste new”.',
            'Run Filter to drop duplicates already known.',
            'Add the remaining unique domains to inventory.',
        ]
    );
}

function guide_team_email_cut(): string
{
    return render_page_purpose(
        'Cut replied emails — remove from Ready list',
        'When an outreach email replies (or you are dealing / must not email), cut it from Ready so the next send wave skips it.',
        'Paste one or more email addresses, choose Replied / Dealing / Do not email, and confirm. Or open Country sheets and update status per row.',
        [
            'Paste the email(s) that replied.',
            'Choose status (Replied, Dealing, or Do not email).',
            'Confirm — they leave the Ready send list (record stays for history).',
        ]
    );
}

function guide_team_email_sheets(): string
{
    return render_page_purpose(
        'Email country sheets — browse campaign contacts',
        'Browse Admin’s URL+email lists by country. Update status when someone replies so they are cut from future Ready exports.',
        'Open a country folder, search contacts, mark Replied / Dealing / Do not email. Use Quick cut when you only have email addresses.',
        [
            'Open a country sheet (or All countries).',
            'Find the contact.',
            'Set status to cut them from Ready sends.',
        ]
    );
}

function guide_team_projects(): string
{
    return render_page_purpose(
        'Projects — your assigned client folders',
        'Projects you are assigned to. Each has its own priced catalog and requirements.',
        'Open a project to see inventory, filters, and pitching work for that client. Use Catalog search for project → country → language lookups.',
        []
    );
}

function guide_team_results(): string
{
    return render_page_purpose(
        'Results — client feedback on pitched sites',
        'Read-only outcomes (agreed, rejected, etc.) from packs sent to clients.',
        'Filter by status to see what clients accepted or rejected, then refill better sites into the project catalog.',
        []
    );
}

function guide_admin_clients(): string
{
    return render_page_purpose(
        'Clients — who you send packs to',
        'Client records linked to projects and orders.',
        'Add client contact details, then use Projects → Send pack when a shortlist is ready. Track orders under Orders export.',
        []
    );
}

function guide_admin_countries(): string
{
    return render_page_purpose(
        'Countries — master country list',
        'Shared list of countries used in Catalog sheets, inventory, and email campaigns.',
        'Keep this list accurate so Admin and Team pick the same country names everywhere.',
        []
    );
}
