<?php
$user = require_team();
ensure_extract_schema();

$batches = [];
try {
    $batches = list_extract_batches(2000);
} catch (Throwable $e) {
    flash('error', 'Extracting sites tables are missing. Open upgrade.php once, then reload.');
}

$wait = extract_hub_waiting_summary($batches);
$waitSites = (int) ($wait['sites'] ?? 0);
$waitCountries = (int) ($wait['countries'] ?? 0);

render_header('Extracting sites', 'team');
?>
<?php render_breadcrumbs([
    ['label' => 'Your work', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Extracting sites'],
]); ?>
<div class="topbar">
  <div>
    <h1>Extracting sites</h1>
    <p class="muted">
      Shared waiting list per country. Open a country, then use <strong>Open &amp; remove</strong> on that country’s shared Sites list.
    </p>
    <?php if ($waitCountries > 0): ?>
      <p class="muted" data-extract-hub-total>
        <strong><?= h(number_format($waitSites)) ?></strong>
        site<?= $waitSites === 1 ? '' : 's' ?> waiting
        · <?= (int) $waitCountries ?> <?= $waitCountries === 1 ? 'country' : 'countries' ?>
        <span class="help">(all countries on this list)</span>
      </p>
    <?php endif; ?>
  </div>
  <div class="actions">
    <?php if (team_page_unlocked($user, 'team_prospect_check')): ?>
      <a class="btn secondary" href="index.php?page=team_prospect_check">Filter &amp; add</a>
    <?php else: ?>
      <span class="muted" style="align-self:center">Sites arrive from Site Finding (Filter &amp; add).</span>
    <?php endif; ?>
  </div>
</div>
<?= guide_extracting() ?>

<?php if ($batches): ?>
<div class="card">
  <div class="invoice-list-toolbar camp-hub-toolbar">
    <label class="sheet-search" for="extract-country-search">
      <span class="visually-hidden">Search countries</span>
      <input id="extract-country-search" type="search" placeholder="Find a country…"
             autocomplete="off" spellcheck="false" data-no-draft>
    </label>
  </div>
  <div class="table-wrap">
  <table class="sheet-cards-mobile">
    <thead>
      <tr>
        <th>Country</th>
        <th title="Shared Sites list waiting to extract — same number for every teammate after you reload">Sites</th>
        <th>Updated</th>
        <th>Last saved</th>
        <th>Last Push</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($batches as $b):
        $bCountry = (string) ($b['country'] ?? '');
        $siteN = (int) ($b['site_count'] ?? 0);
        $cues = extract_hub_row_cues($b);
        $writerName = trim((string) (($b['sites_writer_name'] ?? '') !== ''
            ? $b['sites_writer_name']
            : ($b['sites_writer_username'] ?? '')));
        $writerAt = extract_hub_stamp((string) ($b['sites_writer_at'] ?? ''));
        $writerLabel = last_writer_label($writerName, $writerAt);
        $updatedStamp = extract_hub_stamp((string) ($b['updated_at'] ?? ''));
        $pushStamp = extract_hub_stamp((string) ($b['last_pushed_at'] ?? ''));
        $hay = mb_strtolower($bCountry . ' ' . $siteN . ' sites ' . $writerName);
        $badgeClass = !empty($cues['stale']) ? 'sent' : 'agreed';
        ?>
      <tr data-extract-country-row data-search="<?= h($hay) ?>">
        <td data-label="Country"><strong><?= h($bCountry) ?></strong></td>
        <td data-label="Sites">
          <span class="extract-hub-cues">
            <span class="badge <?= h($badgeClass) ?>"><?= (int) $siteN ?></span>
            <?php if (!empty($cues['large'])): ?>
              <span class="badge extract-large">Large</span>
            <?php endif; ?>
            <?php if (!empty($cues['quiet'])): ?>
              <span class="badge draft">No Push yet</span>
            <?php elseif (!empty($cues['stale'])): ?>
              <span class="badge draft">Quiet</span>
            <?php endif; ?>
          </span>
        </td>
        <td class="muted" data-label="Updated"><?= $updatedStamp !== '' ? h($updatedStamp) : '—' ?></td>
        <td class="muted" data-label="Last saved"><?= $writerLabel !== '' ? h($writerLabel) : '—' ?></td>
        <td class="muted" data-label="Last Push"><?= $pushStamp !== '' ? h($pushStamp) : '—' ?></td>
        <td data-label="Open"><a class="btn secondary small" href="index.php?page=team_extract_batch&amp;id=<?= (int) $b['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="help" id="extract-country-search-empty" hidden>No countries match.</p>
  <script>
  (function () {
    var input = document.getElementById('extract-country-search');
    var emptyEl = document.getElementById('extract-country-search-empty');
    if (!input) return;
    input.addEventListener('input', function () {
      var q = String(input.value || '').trim().toLowerCase();
      var rows = document.querySelectorAll('[data-extract-country-row]');
      var shown = 0;
      rows.forEach(function (row) {
        var hit = !q || String(row.getAttribute('data-search') || '').indexOf(q) !== -1;
        row.hidden = !hit;
        if (hit) shown++;
      });
      if (emptyEl) emptyEl.hidden = !q || shown > 0;
    });
  })();
  </script>
</div>
<?php else: ?>
<div class="card">
  <div class="empty-state" style="min-height:16rem;display:flex;align-items:center;justify-content:center;text-align:center;padding:2rem">
    <div>
      <p style="margin:0 0 0.75rem;font-size:1.1rem">Waiting for sites from the team mate</p>
      <p class="muted" style="margin:0 0 1.25rem;max-width:28rem">
        Country batches stay empty until a teammate filters and adds new unique sites.
        Those sites land in the admin country database and here under <strong>Sites list</strong>.
      </p>
      <?php if (team_page_unlocked($user, 'team_prospect_check')): ?>
        <a class="btn" href="index.php?page=team_prospect_check">Filter &amp; add sites</a>
      <?php else: ?>
        <p class="help" style="margin:0">Ask Site Finding to Filter &amp; add — then countries appear here.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<?php render_footer('team'); ?>
