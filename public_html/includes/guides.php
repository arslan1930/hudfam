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
        . '<p class="muted">Our database is Admin-only. Team filters new lists against those country folders and never browses the lists. Departments control which tools each teammate sees. Orders and invoices are Admin office tools.</p>'
        . '<div class="panel-guide-grid">'
        . '<article class="panel-guide-card">'
        . '<h3>1. Departments</h3>'
        . '<p><strong>What:</strong> Site Finding, Site Extracting, Email Extracting, Communication.</p>'
        . '<p><strong>How:</strong> Add Team users to a department so their sidebar shows only those tools.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>2. Our database</h3>'
        . '<p><strong>What:</strong> Country folders — one unique URL list per country (Admin only).</p>'
        . '<p><strong>How:</strong> Open Our database → Add sites, or open a country folder.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>3. Extracted Sites + Semrush</h3>'
        . '<p><strong>What:</strong> Sites pushed from Team Extracting Results; Semrush is shared research notes.</p>'
        . '<p><strong>How:</strong> Extracted Sites → country → copy or remove. Semrush Research is optional seed/copy for Finding and Extracting.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>4. Emails data</h3>'
        . '<p><strong>What:</strong> Sites with emails – Admin, All sites with emails – Final, and Email campaign projects (country sheets).</p>'
        . '<p><strong>How:</strong> Archives fill from Team Push. Create a campaign project; Communication Team uses Campaign search and drafts (they do not open the full Admin sheet).</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>5. Orders + Invoices</h3>'
        . '<p><strong>What:</strong> Client publication sheets, live URLs, printable invoices.</p>'
        . '<p><strong>How:</strong> Order management → client sheet. Invoices generate from unpaid live rows or a blank invoice.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>6. Users</h3>'
        . '<p><strong>What:</strong> Admin and Team logins.</p>'
        . '<p><strong>How:</strong> Create Team accounts, assign departments, set a temp password if they cannot sign in.</p>'
        . '</article>'
        . '</div>'
        . '<div class="panel-guide-flow">'
        . '<h3>Flow</h3>'
        . '<ol>'
        . '<li>Admin seeds a <strong>country folder</strong> in Our database (or Team Filter &amp; add writes unique sites there).</li>'
        . '<li>Site Finding pastes a list → duplicates for that country are removed privately → unique sites are saved + Site adding history.</li>'
        . '<li>Site Extracting <strong>Push</strong>es Extracting Results → <strong>Extracted Sites</strong> and <strong>Sites with emails – Team</strong>.</li>'
        . '<li>Email Extracting adds emails, then <strong>Push to Admin</strong> → Admin archive (also synced to Final). Pushed rows leave the Team working copy.</li>'
        . '<li>Admin creates a <strong>campaign project</strong> under Emails data; Communication uses <strong>Campaign search</strong> / <strong>Campaign drafts</strong>.</li>'
        . '<li>When a placement goes live, record it on the client <strong>Order</strong> sheet and generate an <strong>Invoice</strong>.</li>'
        . '</ol>'
        . '</div>'
        . '</section>';
}

function render_team_panel_guide(): string
{
    return '<section class="panel-guide">'
        . '<h2>How Team works</h2>'
        . '<p class="muted">Your sidebar shows tools for the departments Admin assigned. Our database country lists stay private to Admin. Communication Team searches campaign sheets and copies drafts — they do not open the full Admin campaign editor.</p>'
        . '<div class="panel-guide-grid">'
        . '<article class="panel-guide-card">'
        . '<h3>1. Filter &amp; add</h3>'
        . '<p><strong>What:</strong> Compare your paste to one country’s database without seeing that list.</p>'
        . '<p><strong>How:</strong> Select country → Paste → Filter unique sites → Add. Site Finding only.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>2. Extracting sites</h3>'
        . '<p><strong>What:</strong> Per country: Sites list + Extracting Results.</p>'
        . '<p><strong>How:</strong> Paste results and <strong>Push</strong> → Extracted Sites + Sites with emails – Team. Site Extracting only.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>3. Sites with emails – Team</h3>'
        . '<p><strong>What:</strong> Site names from Extracting Results Push; add up to 4 emails each.</p>'
        . '<p><strong>How:</strong> Fill emails, then <strong>Push to Admin</strong>. Email Extracting only.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>4. Campaign search + drafts</h3>'
        . '<p><strong>What:</strong> Find a site/email in Admin campaign projects; copy outreach text.</p>'
        . '<p><strong>How:</strong> Search, delete a row or one email if needed, paste drafts into your email client. Communication only.</p>'
        . '</article>'
        . '</div>'
        . '<div class="panel-guide-flow">'
        . '<h3>Flow</h3>'
        . '<ol>'
        . '<li>Wait until Admin assigns you to a <strong>department</strong> — then your tools appear.</li>'
        . '<li>Site Finding: <strong>Filter &amp; add</strong> → unique sites join Our database (unseen) and Extracting Sites list.</li>'
        . '<li>Site Extracting: paste <strong>Extracting Results</strong> and <strong>Push</strong>.</li>'
        . '<li>Email Extracting: add emails, then <strong>Push to Admin</strong>.</li>'
        . '<li>Communication: <strong>Campaign search</strong> and <strong>Campaign drafts</strong> (copy, do not send from this app).</li>'
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
            'Add emails in Team, then Push to Admin. Final keeps archive copies; Campaign sheets are separate.',
        ]
    );
}

function guide_add_history(): string
{
    return render_page_purpose(
        'Site adding history — who added what',
        'Daily record of domains added by each person. The list shows recent days (paged). Older days are on later pages.',
        'Open a date/person to see the exact domains saved that day. Editing the list autosaves: new lines are added to Our database; removed lines leave Our database unless you delete the day with that option checked. Day details (country/language) do not move sites between folders.',
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

function guide_emails_data(): string
{
    return render_page_purpose(
        'Emails data — Admin, Final, and Campaign',
        'Three separate stores. Admin is the working list Team Push fills. Final is an Admin-only archive. Campaign is project country sheets for Communication Team.',
        'Super search on this hub updates Admin only. Removing the last email deletes the Admin working-list row; Final keeps its archive copy. Repair copies Admin into Final and never deletes archive rows. Campaign emailed marks stay on that project sheet.',
        [
            'Admin: working list from Team Push; mark emailed here.',
            'Final: archive copy of Admin; emailed/remove keeps a copy here. Repair copies Admin → Final. Adding a site here also creates the Admin working-list row.',
            'Campaign: create a project and country sheets. Communication Team searches the project. Emailed marks are per campaign, not Admin/Final.',
        ]
    );
}

function guide_orders(): string
{
    return render_page_purpose(
        'Order management — one sheet per client',
        'Each client has an editable sheet of sites, prices, LIVE URLs, and Banner/Textlink placements. Completed means the LIVE URL is filled. Unpaid LIVE rows are ready to invoice.',
        'Open a client to edit rows, then Generate invoice from unpaid LIVE. Archive hides a client from the default list. Deleting a sheet keeps invoices and clears the client link.',
        [
            'Create a client sheet, add sites, fill LIVE URL when the placement is live.',
            'Use Invoice on unpaid LIVE rows to generate a printable bill.',
            'Archive to hide from the default list; restore from the Archived filter.',
        ]
    );
}

function guide_invoices(): string
{
    return render_page_purpose(
        'Invoices — printable bills',
        'Generate from unpaid LIVE rows on a client sheet, or start a blank invoice (Draft while incomplete, Done when sent). Mark Paid when payment arrives — that writes Paid back onto linked sheet rows.',
        'Notes under an invoice number also print on the bill. The printable letterhead is Topurlz; the app chrome stays TechxForm.',
        [
            'Generate invoice: pick a client and unpaid LIVE rows.',
            'Blank invoice: fill bill-to and line items, Save as draft or Save as done.',
            'Mark Paid on the list or the open bill when payment is received.',
        ]
    );
}

function guide_admin_account(): string
{
    return render_page_purpose(
        'Account — email verify and password',
        'Verify your Admin email so Forgot password can send a reset link. Team cannot self-reset; Admin sets Team passwords on Users.',
        'Save an email, send a verification link, then you can request a 2-hour reset. Sidebar Change password updates the same password as this page.',
        []
    );
}
