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

    return '<details class="help-details page-purpose">'
        . '<summary>What is this? · ' . h($title) . '</summary>'
        . '<div class="help-details-body">'
        . '<p class="page-purpose-what"><strong>Purpose:</strong> ' . h($what) . '</p>'
        . '<p class="page-purpose-how"><strong>How it works:</strong> ' . h($how) . '</p>'
        . $stepsHtml
        . '</div>'
        . '</details>';
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
        . '<p><strong>What:</strong> One order sheet (country, date, admin, client email or name) and printable invoices.</p>'
        . '<p><strong>How:</strong> Order management → fill the sheet → push unpaid LIVE rows to Invoices. Website prices is a separate Office rate book (publisher prices by country).</p>'
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
        . '<p><strong>How:</strong> From Your work, Open on an extracting task (or <strong>Extracting sites</strong>) opens the country list. First 10–50 / Undo is on that country’s Sites list. Paste results and <strong>Push</strong> → Extracted Sites + Sites with emails – Team. Site Extracting only.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>3. Sites with emails – Team</h3>'
        . '<p><strong>What:</strong> Site names from Extracting Results Push; add up to 4 emails each.</p>'
        . '<p><strong>How:</strong> Fill emails, then <strong>Push to Admin</strong>. Email Extracting only.</p>'
        . '</article>'
        . '<article class="panel-guide-card">'
        . '<h3>4. Campaign search + drafts + Website prices</h3>'
        . '<p><strong>What:</strong> Find a site/email in Admin campaign projects; copy outreach text; keep publisher rate sheets.</p>'
        . '<p><strong>How:</strong> Search, paste drafts into your email client, and manage Website prices. Communication only.</p>'
        . '</article>'
        . '</div>'
        . '<div class="panel-guide-flow">'
        . '<h3>Flow</h3>'
        . '<ol>'
        . '<li>Wait until Admin assigns you to a <strong>department</strong> — then your tools appear.</li>'
        . '<li>Site Finding: <strong>Filter &amp; add</strong> → unique sites join Our database (unseen) and Extracting Sites list.</li>'
        . '<li>Site Extracting: paste <strong>Extracting Results</strong> and <strong>Push</strong>.</li>'
        . '<li>Email Extracting: add emails, then <strong>Push to Admin</strong>.</li>'
        . '<li>Communication: <strong>Campaign search</strong>, <strong>Campaign drafts</strong>, and <strong>Website prices</strong> (copy, do not send from this app).</li>'
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
            'Add only the remaining unique sites — that leftover is this Filter run (your session), not the shared country total. Each destination folder and Extracting Sites list are shared — both teammates see the same country count after refresh. Separate before Filter is Copy/Delete only.',
            'If Admin later removes those sites from Our database, they also leave Extracting. Filter unique and Add again to put them back at the top of Extracting.',
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
            'Open a country. Sites list tools: Copy, Undo, Redo, and Open & remove first 10–50 (Open next continues from the new top). Undo restores them while you stay on this page.',
            'Paste Extracting Results → Clean to root domains → Push Ready only. Country TLDs route; .com/.net/.eu stay in the selected country.',
            'Extracting is the waiting list. It shrinks when you Push, Open & remove, delete lines, or Admin removes the same domains from Our database.',
            'Add emails in Sites with emails, then Push to Admin.',
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
        'Assign Team users under Departments so they unlock tools. Filter Awaiting assignment for Team not in a department. Admin email login needs a unique address — Send verification on the user, or verify under Account for yourself. You cannot deactivate or demote yourself, or remove the last active admin.',
        []
    );
}

function guide_emails_data(): string
{
    return render_page_purpose(
        'Emails data — Admin, Final, and Campaign',
        'Three separate stores. Admin is the working list Team Push fills. Final keeps a copy after Mark emailed or Remove on Admin. Campaign is project country sheets for Communication Team.',
        'Super search on this hub updates Admin only. Removing the last email deletes the Admin working-list row; Final keeps its archive copy. Repair copies Admin into Final and never deletes archive rows. Campaign emailed marks stay on that project sheet.',
        [
            'Admin: working list from Team Push; mark emailed here.',
            'Final: archive copy of Admin; Mark emailed or Remove on Admin keeps a copy here. Repair copies Admin → Final. Adding a site here also creates the Admin working-list row. Existing folders open from the list; use Open an empty country only for a country that is not listed yet. Paste or import CSV / Excel / TXT like Campaign on that sheet.',
            'Campaign: create a project and country sheets. Communication Team searches the project. Emailed marks are per campaign, not Admin/Final. Mark up to here names a send batch so Admin can see who emailed which stretch.',
            'Fill gaps from Admin + Final copies into that country campaign sheet only. Admin emails win when both have the domain. Previously removed sites stay blocked. Campaign emailed marks stay. Admin and Final are not edited.',
        ]
    );
}

function guide_site_prices(): string
{
    return render_page_purpose(
        'Website prices — publisher rate book',
        'One country sheet of website prices and statuses. Team adds rates; site name, DA, DR, and traffic lock after save. Niche fills from Our database when the site already exists in that country.',
        'Open a country. Processing stays at the top, then New, then the rest. This is not Order management and does not write into Our database.',
        [
            'Open a country from the switcher (most-used first) or All countries. On Team, only Communication Team can open these sheets.',
        'Add a site on the sheet. Website, DA, DR, and traffic lock after save; price, status, email, and row color stay editable. Row color washes the whole row. Admin can Unlock identity. Processing and Completed are Admin-only because Processing fills Order management.',
        'Processing / New / Other lanes stay in that order. Search this country filters the open sheet (Enter = next match, Ctrl/Cmd+Enter = all pages). Admin Search all countries jumps to a row in any country — it does not filter the sheet. Team copies one website with Copy; Admin Copy selected copies ticked rows on this page only. Admin can Remove a site (orders stay). Take or pick a manager; clear with —.',
            'Admin sees who added a row and who manages it; Team does not see Admin names.',
        ]
    );
}

function guide_orders(): string
{
    return render_page_purpose(
        'Order management — Processing and Completed',
        'Processing is Website prices Processing, leftover after Website prices leaves Processing, or + Add order. Completed is after a live URL and Mark completed. Only Completed unpaid rows push to an invoice. Website prices never stores LIVE URL, profit, client, or invoice fields.',
            'Open a folder, edit Admin OM fields, mark completed with a live URL, then push unpaid Completed rows to Invoices. Processing and Completed stay on the page as tabs — you do not have to go back to the hub.',
        [
            'Processing opens Website prices Processing when that tab has rows; otherwise Leftover, then Added here. Leftover stays here when Website prices leaves Processing. + Add order is Added here. Fill LIVE URL, country, and client email or name, then Mark completed — saving a live URL does not complete the row. Document URL is the Google Doc for the piece (not the live page). It stays on Processing and Completed — not on invoices. Save still works on half-filled Processing rows.',
            'Copy selected sites or live URLs copies ticked Copy boxes on this page only. Copy all live URLs and Download .txt use this folder and filter (all pages). CSV/Excel are the full sheet. On Completed, Download month close is this calendar month with owner / decided / profit totals.',
            'Completed: unpaid until you click Mark paid (or mark Paid on the invoice). Tick Bill boxes and Push to invoice, or use Push unpaid (N) to open Generate with this filter’s unpaid rows ticked (or an honest label if the list is too long). A row already on a draft or unpaid invoice cannot be pushed again — open that bill from the row. Country and client email/name are required to push. Clearing a live URL on Save also clears Paid (you will be asked to confirm). Paid stays in this folder. Website prices status is not changed when you mark paid.',
            'Open in Website prices jumps to the linked site. If that status no longer matches this folder, the mismatch is shown. Removing a row while Website prices is still Processing brings it back on the next Processing load; removing a Completed-linked row can optionally set Website prices back to Processing.',
            'Team Website prices shows the Completed status only — never LIVE URL, owner/decided/profit, client email/name, or invoices.',
        ]
    );
}

function guide_invoices(): string
{
    return render_page_purpose(
        'Invoices — printable bills',
        'Generate from unpaid LIVE rows on Order management, or start a blank invoice (Draft while incomplete, Waiting when sent). Mark paid when payment arrives — that writes Paid back onto linked sheet rows.',
        'Notes under an invoice number also print on the bill. The printable letterhead is Topurlz; the app chrome stays TechxForm. Bill-as is the email or name from the order — no client folder required.',
        [
            'Generate invoice: tick unpaid LIVE rows pushed from Order management (opening Generate from Invoices starts with none ticked). Tick one bill-as only — mixed emails/names cannot share a bill. If that bill-as already has a Draft or Waiting bill, Add to existing is selected. New invoice gets the next number. Open a Waiting invoice and use Add sites — matching unpaid LIVE rows are ticked. Paid invoices stay locked. Group same amount is off unless you turn it on.',
            'Blank invoice: fill bill-as and line items, Save as draft or Mark as sent. On a generated bill, Save bill as to fix the email/name.',
            'Mark paid on the list or the open bill when payment is received. Draft / Waiting / Paid counts sit above the search. Open a generated bill to see the Order management rows (site, LIVE URL, Completed) and a History of who added sites.',
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

function guide_campaign_search(): string
{
    return render_page_purpose(
        'Campaign search — find a site in a project',
        'One search bar per Admin project shown to Communication Team. Each bar covers every country sheet in that project.',
        'Type a site or email, pick a result, then delete both or remove only one email. Updates go to that country’s campaign sheet. Removing the last email also deletes the site row.',
        [
            'Pick the project search bar Admin shared with Communication Team.',
            'Search site name or email across all countries in that project.',
            'Delete both, or remove only email — JavaScript is required to confirm the update.',
        ]
    );
}

function guide_campaign_drafts(): string
{
    return render_page_purpose(
        'Campaign drafts — copy outreach for email',
        'Reusable formatted replies, offers, and follow-ups per Admin project. Optional subject line and tokens such as {domain} and {country}.',
        'Open a project, write or pick a draft, then Copy (keeps formatting) or Copy plain for your email client. Communication Team only — this is not the full Admin campaign editor.',
        [
            'Choose a project Admin turned on for Communication Team.',
            'Save drafts with formatting; tokens fill from Campaign search when you open drafts for a site.',
            'Copy into your email client. Delete is allowed for the creator or Admin.',
        ]
    );
}

function guide_admin_emails_search(): string
{
    return render_page_purpose(
        'Admin search — Sites with emails, all countries',
        'Super search across every country in Sites with emails - Admin. Results always show site + email + country together.',
        'Search, then delete both or remove only email on that country’s Admin row. Removing the last email deletes the Admin working-list row; Final keeps its archive copy.',
        [
            'Type a site or email (all countries).',
            'Choose delete both or remove only email, then Enter to confirm.',
            'JavaScript is required. This does not open the full Admin sheet.',
        ]
    );
}

function guide_semrush_team(): string
{
    return render_page_purpose(
        'Semrush Research — site names from Extracting Push',
        'Country folders of site names copied when Extracting Results are pushed (same TLD routing), plus optional Admin seed. Does not change Extracted Sites.',
        'Open a country to edit, copy, undo/redo, or comment. Site Finding and Admin can clear a whole country. Site Extracting can research here but cannot Clear.',
        [
            'Open a country folder after Extracting Push (or Admin seed).',
            'Edit the list and add comments. Clear country stays with Site Finding / Admin.',
            'Filter & add stays on Site Finding — this page is research notes, not the unique-sites filter.',
        ]
    );
}

function guide_sites_with_emails_team(): string
{
    return render_page_purpose(
        'Sites with emails — add emails, then Push',
        'Working copy for this country. Sites arrive from Extracting Results Push. Add up to 4 emails, then Push to Admin. Remove all on this sheet does not remove Extracting sites.',
        'Edits autosave. Push on a row needs at least one email (same as Push all to Admin).',
        [
            'Open a site with Open on the row, or Open first 10–50 above (Open next continues). Large opens go in batches of 10.',
            'Opened rows stay highlighted until you enter an email in that row.',
            'Push one site with its row button, or Push all to Admin for every site that has at least one email.',
            'Remove all and Remove listed sites cannot be undone. Extracting sites stay.',
        ]
    );
}

function guide_team_departments(): string
{
    return render_page_purpose(
        'My departments — tasks for your team',
        'Departments Admin assigned you to, with open tasks and due dates. Tools stay locked until you are in a department.',
        'Change status from Dashboard, or open a folder to assign tasks and filter. Only you can change status on a task assigned by name; anyone in the department can update a whole-department (unassigned) task. Anyone in the department can assign a task to a current member.',
        [
            'Dashboard Open on a task goes to that department’s tool (Extracting sites, Filter & add, Team emails, or Campaign search). A Tasks link opens this folder. Dashboard can update Open / In progress / Done without opening the folder.',
            'Open a department folder to assign a task to a teammate already in that department, or to the whole department.',
            'Update status only on your named tasks, or on tasks with no named assignee.',
            'Unlocked tools appear in the sidebar and on Dashboard after assignment. Website prices is Communication Team only.',
        ]
    );
}
