<?php
/**
 * Communication Team · outreach drafts / offers per Admin project.
 * Save reusable text → one-click copy → paste into email client.
 */
$user = require_team();
ensure_email_campaign_schema();

if (user_is_department_scoped($user) && !user_in_communication_team($user)) {
    flash('error', 'This tool is for Communication Team members.');
    redirect(team_home_url());
}

$base = 'index.php?page=team_email_campaigns_drafts';
$categories = email_campaign_draft_categories();
$actorId = (int) ($user['id'] ?? 0);
ensure_email_campaign_office_proposal_drafts();

$parseDfolder = static function (string $raw): int {
    $raw = trim($raw);
    if ($raw === 'unfiled' || $raw === 'none') {
        return -1;
    }
    return (int) $raw;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $projectId = (int) post('project_id');
    $returnCat = trim((string) post('filter_category'));
    $returnQ = trim((string) post('filter_q'));
    $returnFolder = $parseDfolder((string) post('filter_dfolder'));
    $back = $base . '&project=' . max(0, $projectId);
    if ($returnCat !== '' && isset($categories[$returnCat])) {
        $back .= '&category=' . rawurlencode($returnCat);
    }
    if ($returnQ !== '') {
        $back .= '&q=' . rawurlencode($returnQ);
    }
    if ($returnFolder > 0) {
        $back .= '&dfolder=' . $returnFolder;
    } elseif ($returnFolder < 0) {
        $back .= '&dfolder=unfiled';
    }
    $wantsJson = (string) post('ajax') === '1'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $json = static function (array $payload, int $code = 200) use ($wantsJson, $back): void {
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($code);
            echo json_encode($payload);
            exit;
        }
        if (!empty($payload['ok'])) {
            flash('ok', (string) ($payload['message'] ?? 'Saved.'));
        } else {
            flash('error', (string) ($payload['error'] ?? 'Could not complete.'));
        }
        redirect($back !== '' ? $back : 'index.php?page=team_email_campaigns_drafts');
    };

    $project = $projectId > 0 ? get_email_campaign_project($projectId) : null;
    if (!$project || !email_campaign_project_team_visible($project)) {
        $json(['ok' => false, 'error' => 'This project is not available to Communication Team.'], 403);
    }

    if ($action === 'save_draft') {
        $draftId = (int) post('draft_id');
        $result = save_email_campaign_draft(
            $projectId,
            (string) post('title'),
            (string) post('body'),
            (string) post('category'),
            $draftId,
            $actorId,
            (string) post('subject'),
            (int) post('folder_id')
        );
        if (empty($result['ok'])) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Could not save draft.')], 400);
        }
        $json([
            'ok' => true,
            'id' => (int) ($result['id'] ?? 0),
            'message' => ($draftId > 0 ? 'Updated draft.' : 'Saved new draft.'),
        ]);
    }

    if ($action === 'delete_draft') {
        $result = delete_email_campaign_draft($projectId, (int) post('draft_id'), $user);
        if (empty($result['ok'])) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Could not delete.')], 404);
        }
        $json([
            'ok' => true,
            'message' => 'Deleted “' . (string) ($result['title'] ?? 'draft') . '”.',
        ]);
    }

    if ($action === 'move_draft') {
        $result = move_email_campaign_draft(
            $projectId,
            (int) post('draft_id'),
            (string) post('direction'),
            $user
        );
        if (empty($result['ok'])) {
            $json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Could not move draft.')], 400);
        }
        $json(['ok' => true, 'message' => 'Draft order updated.']);
    }

    $json(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$projects = list_email_campaign_projects(true);
$projectId = (int) get('project');
$filterCategory = trim((string) get('category'));
$draftQ = trim((string) get('q'));
$filterFolderRaw = trim((string) get('dfolder'));
$filterFolderId = $parseDfolder($filterFolderRaw);
if ($filterCategory !== '' && !isset($categories[$filterCategory])) {
    $filterCategory = '';
}

// Default to the office English library, then the first visible project.
if ($projectId < 1 && $projects !== []) {
    $officeName = email_campaign_office_proposal_project_name();
    foreach ($projects as $p) {
        if ((string) ($p['name'] ?? '') === $officeName) {
            $projectId = (int) $p['id'];
            break;
        }
    }
    if ($projectId < 1) {
        $projectId = (int) $projects[0]['id'];
    }
}
$selectedProject = null;
foreach ($projects as $p) {
    if ((int) $p['id'] === $projectId) {
        $selectedProject = $p;
        break;
    }
}
if (!$selectedProject && $projects !== []) {
    $selectedProject = $projects[0];
    $projectId = (int) $selectedProject['id'];
}

$projectCountMap = count_email_campaign_drafts_by_projects(
    array_map(static fn ($p) => (int) $p['id'], $projects),
    $actorId
);

$draftFolders = $selectedProject
    ? list_email_campaign_draft_folders($projectId, $actorId)
    : [];
$visibleFolderIds = [];
foreach ($draftFolders as $folderRow) {
    $visibleFolderIds[(int) $folderRow['id']] = true;
}
if ($filterFolderId > 0 && !isset($visibleFolderIds[$filterFolderId])) {
    $filterFolderId = 0;
}

$drafts = $selectedProject
    ? list_email_campaign_drafts($projectId, null, '', 0, $actorId)
    : [];
$draftGroups = email_campaign_group_draft_cards($drafts);
$editId = (int) get('edit');
$editDraft = ($editId > 0) ? get_email_campaign_draft($editId) : null;
if ($editDraft && (int) ($editDraft['project_id'] ?? 0) !== $projectId) {
    $editDraft = null;
    $editId = 0;
}

$draftVars = [
    'domain' => trim((string) get('domain')),
    'country' => trim((string) get('country')),
    'language' => trim((string) get('language')),
    'name' => trim((string) get('name')),
];
$hasDraftVars = $draftVars['domain'] !== '' || $draftVars['country'] !== ''
    || $draftVars['language'] !== '' || $draftVars['name'] !== '';

$campDraftsHref = static function (
    int $pid,
    string $cat = '',
    string $q = '',
    int $folderId = 0
) use ($base, $draftVars): string {
    $href = $base . '&project=' . $pid;
    if ($cat !== '') {
        $href .= '&category=' . rawurlencode($cat);
    }
    if ($q !== '') {
        $href .= '&q=' . rawurlencode($q);
    }
    if ($folderId > 0) {
        $href .= '&dfolder=' . $folderId;
    } elseif ($folderId < 0) {
        $href .= '&dfolder=unfiled';
    }
    foreach ($draftVars as $vk => $vv) {
        if ($vv !== '') {
            $href .= '&' . rawurlencode($vk) . '=' . rawurlencode($vv);
        }
    }
    return $href;
};

$formAction = $campDraftsHref($projectId, $filterCategory, $draftQ, $filterFolderId);
$filterFolderParam = $filterFolderId > 0 ? (string) $filterFolderId : ($filterFolderId < 0 ? 'unfiled' : '');

render_header('Campaign drafts', 'team');
render_breadcrumbs([
    ['label' => 'Your work', 'href' => 'index.php?page=team_dashboard'],
    ['label' => 'Campaign drafts'],
]);
?>
<div class="topbar">
  <div>
    <h1><?= label_with_info('Campaign drafts', 'Reusable outreach text for Communication Team. Optional subject + tokens ({domain}, {country}, …). Format with bold/italic/images. Copy (or Copy plain) for your email client.') ?></h1>
    <p class="muted">
      <?= count($projects) ?> project<?= count($projects) === 1 ? '' : 's' ?> shared by Admin ·
      format replies / offers / follow-ups · <strong>Copy</strong> keeps formatting for paste
    </p>
  </div>
  <div class="actions">
    <a class="btn secondary" href="index.php?page=team_email_campaigns">Campaign search</a>
  </div>
</div>
<?= guide_campaign_drafts() ?>

<?php if ($projects === []): ?>
<div class="card">
  <div class="empty-state">
    <p>No project draft libraries yet.</p>
    <p class="muted">When Admin creates a project and turns on “Show to Communication Team”, it appears here for drafts.</p>
  </div>
</div>
<?php
    render_footer('team');
    return;
endif;
?>

<div class="camp-drafts-layout">
  <aside class="card camp-drafts-nav">
    <h2 style="margin:0 0 0.65rem">Projects</h2>
    <p class="help" style="margin-top:0">Pick a project to open its drafts.</p>
    <ul class="camp-drafts-project-list">
      <?php foreach ($projects as $p):
          $pid = (int) $p['id'];
          $count = (int) ($projectCountMap[$pid] ?? 0);
          $href = $campDraftsHref($pid, $filterCategory, $draftQ, $filterFolderId);
          $active = $pid === $projectId;
          ?>
        <li>
          <a class="camp-drafts-project-link<?= $active ? ' is-active' : '' ?>" href="<?= h($href) ?>">
            <span class="camp-drafts-project-name"><?= h((string) $p['name']) ?></span>
            <span class="camp-drafts-project-count"><?= (int) $count ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </aside>

  <div class="camp-drafts-main">
    <?php
    $projectName = (string) ($selectedProject['name'] ?? 'Project');
    $draftCount = count($drafts);
    $groupCount = count($draftGroups);
    ?>
    <div class="card">
      <div class="invoice-list-toolbar swe-list-toolbar">
        <div>
          <h2 style="margin:0"><?= h($projectName) ?></h2>
          <p class="help" style="margin:0.25rem 0 0">
            <span data-camp-draft-count><?= (int) $groupCount ?> situation<?= (int) $groupCount === 1 ? '' : 's' ?>
            · <?= (int) $draftCount ?> letter<?= (int) $draftCount === 1 ? '' : 's' ?></span>.
            Search the full library — typing is not limited to a folder or Reply chip.
            <strong>Enter</strong> jumps to the next match, <strong>Shift+Enter</strong> the previous.
            Each situation is one card with <strong>A / B / C</strong> tabs.
            <strong>Show full</strong> reveals the whole letter.
            Add your own name under <strong>Best regards</strong> before you send.
            Tokens: <code>{domain}</code> <code>{site}</code> <code>{country}</code> <code>{language}</code> <code>{name}</code>.
            <?php if ($hasDraftVars): ?>
              · Filling from site
              <?= $draftVars['domain'] !== '' ? '<strong>' . h($draftVars['domain']) . '</strong>' : '' ?>
              <?= $draftVars['country'] !== '' ? ' · ' . h($draftVars['country']) : '' ?>
            <?php endif; ?>
          </p>
          <p class="swe-sent-filters camp-drafts-filters camp-drafts-folder-chips">
            <?php
            $folderLinks = [['id' => 0, 'name' => 'All folders']];
            foreach ($draftFolders as $folderRow) {
                $folderLinks[] = ['id' => (int) $folderRow['id'], 'name' => (string) $folderRow['name']];
            }
            $folderLinks[] = ['id' => -1, 'name' => 'Unfiled'];
            foreach ($folderLinks as $fl):
                $fid = (int) $fl['id'];
                $href = $campDraftsHref($projectId, $filterCategory, $draftQ, $fid);
                $active = $filterFolderId === $fid;
                ?>
              <a class="btn small <?= $active ? '' : 'secondary' ?>"
                 href="<?= h($href) ?>"
                 data-no-processing
                 data-camp-draft-chip="folder"
                 data-camp-draft-chip-value="<?= $fid ?>"><?= h((string) $fl['name']) ?></a>
            <?php endforeach; ?>
          </p>
          <p class="swe-sent-filters camp-drafts-filters">
            <?php
            $catLinks = ['' => 'All'] + $categories;
            foreach ($catLinks as $slug => $label):
                $href = $campDraftsHref($projectId, (string) $slug, $draftQ, $filterFolderId);
                $active = $filterCategory === (string) $slug;
                ?>
              <a class="btn small <?= $active ? '' : 'secondary' ?>"
                 href="<?= h($href) ?>"
                 data-no-processing
                 data-camp-draft-chip="category"
                 data-camp-draft-chip-value="<?= h((string) $slug) ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
          </p>
        </div>
        <div class="actions camp-drafts-toolbar-actions">
          <form method="get" action="index.php" class="camp-drafts-search" role="search"
                data-camp-draft-search-form>
            <input type="hidden" name="page" value="team_email_campaigns_drafts">
            <input type="hidden" name="project" value="<?= (int) $projectId ?>">
            <?php if ($filterCategory !== ''): ?>
              <input type="hidden" name="category" value="<?= h($filterCategory) ?>">
            <?php endif; ?>
            <?php if ($filterFolderParam !== ''): ?>
              <input type="hidden" name="dfolder" value="<?= h($filterFolderParam) ?>">
            <?php endif; ?>
            <?php foreach ($draftVars as $vk => $vv):
                if ($vv === '') {
                    continue;
                } ?>
              <input type="hidden" name="<?= h($vk) ?>" value="<?= h($vv) ?>">
            <?php endforeach; ?>
            <label class="sheet-search" for="camp-draft-search">
              <span class="visually-hidden">Search drafts</span>
              <input id="camp-draft-search" type="search" name="q" value="<?= h($draftQ) ?>"
                     placeholder="Italy, niche, discount, homepage…" autocomplete="off" spellcheck="false"
                     aria-autocomplete="list" aria-controls="camp-draft-suggest" aria-expanded="false"
                     data-camp-draft-search data-no-draft>
              <ul class="swe-admin-delete-suggest" id="camp-draft-suggest" data-camp-draft-suggest
                  hidden role="listbox"></ul>
            </label>
            <span class="sheet-search-meta" data-camp-draft-search-meta></span>
          </form>
          <a class="btn small" href="<?= h($formAction) ?>#camp-draft-form">+ New draft</a>
        </div>
      </div>

      <?php if ($drafts === []): ?>
      <div class="empty-state" id="camp-drafts-empty">
        <p>No drafts in this project yet.</p>
        <p class="muted">Add your first outreach / offer / reply text below.</p>
      </div>
      <?php else: ?>
      <div class="empty-state" id="camp-drafts-search-empty" hidden>
        <p>No drafts match this search.</p>
      </div>
      <div class="camp-drafts-grid">
        <?php
        $draftTotal = count($drafts);
        $draftIndexById = [];
        foreach ($drafts as $di => $row) {
            $draftIndexById[(int) ($row['id'] ?? 0)] = $di;
        }
        foreach ($draftGroups as $group):
            $variants = $group['variants'];
            if ($variants === []) {
                continue;
            }
            $first = $variants[0];
            $cat = (string) ($group['category'] ?? '');
            $cardFolderName = trim((string) ($group['folder_name'] ?? ''));
            $fid = (int) ($group['folder_id'] ?? 0);
            $baseTitle = (string) ($group['base_title'] ?? '');
            $hayParts = [
                $baseTitle,
                email_campaign_draft_category_label($cat),
                $cardFolderName,
            ];
            $letters = [];
            foreach ($variants as $d) {
                $hayParts[] = (string) ($d['title'] ?? '');
                $hayParts[] = (string) ($d['subject'] ?? '');
                $hayParts[] = email_campaign_draft_html_to_plain((string) ($d['body'] ?? ''));
                $let = (string) ($d['_abc'] ?? '');
                if ($let !== '') {
                    $letters[] = $let;
                }
            }
            $haystack = implode(' ', $hayParts);
            $hasAbc = count($letters) > 1;
            $firstId = (int) ($first['id'] ?? 0);
            ?>
          <article class="camp-draft-card" data-camp-draft-card data-draft-id="<?= $firstId ?>"
                   data-camp-draft-haystack="<?= h(mb_strtolower($haystack)) ?>"
                   data-camp-draft-suggest-title="<?= h($baseTitle) ?>"
                   data-camp-draft-category="<?= h($cat) ?>"
                   data-camp-draft-folder="<?= $fid ?>"
                   data-camp-draft-subject="<?= h(trim((string) ($first['subject'] ?? ''))) ?>"
                   data-token-domain="<?= h($draftVars['domain']) ?>"
                   data-token-country="<?= h($draftVars['country']) ?>"
                   data-token-language="<?= h($draftVars['language']) ?>"
                   data-token-name="<?= h($draftVars['name']) ?>">
            <div class="camp-draft-card-head">
              <h3 class="camp-draft-title"><?= h($baseTitle) ?></h3>
              <span class="camp-draft-card-tags">
                <?php if ($cardFolderName !== ''): ?>
                <span class="camp-draft-folder-tag"><?= h($cardFolderName) ?></span>
                <?php endif; ?>
                <span class="swe-status-badge is-ready"><?= h(email_campaign_draft_category_label($cat)) ?></span>
              </span>
            </div>
            <?php if ($hasAbc): ?>
            <div class="camp-draft-abc" data-camp-draft-abc role="tablist" aria-label="A B C wordings">
              <?php foreach ($variants as $vi => $d):
                  $let = (string) ($d['_abc'] ?? '');
                  if ($let === '') {
                      continue;
                  }
                  ?>
                <button type="button" class="btn small<?= $vi === 0 ? ' is-on' : ' secondary' ?>"
                        data-camp-draft-abc-tab="<?= h($let) ?>"
                        aria-pressed="<?= $vi === 0 ? 'true' : 'false' ?>"><?= h($let) ?></button>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php foreach ($variants as $vi => $d):
                $did = (int) $d['id'];
                $title = (string) $d['title'];
                $subject = trim((string) ($d['subject'] ?? ''));
                $bodyHtml = email_campaign_draft_body_html((string) $d['body']);
                $sizeWarn = email_campaign_draft_size_warning((string) $d['body']);
                $let = (string) ($d['_abc'] ?? '');
                $di = $draftIndexById[$did] ?? -1;
                $canMoveUp = $di > 0
                    && (string) ($drafts[$di - 1]['category'] ?? '') === $cat
                    && (int) ($drafts[$di - 1]['folder_id'] ?? 0) === $fid;
                $canMoveDown = $di >= 0 && $di < ($draftTotal - 1)
                    && (string) ($drafts[$di + 1]['category'] ?? '') === $cat
                    && (int) ($drafts[$di + 1]['folder_id'] ?? 0) === $fid;
                $editHref = $formAction . '&edit=' . $did . '#camp-draft-form';
                if ($hasDraftVars) {
                    foreach ($draftVars as $vk => $vv) {
                        if ($vv !== '') {
                            $editHref .= '&' . rawurlencode($vk) . '=' . rawurlencode($vv);
                        }
                    }
                }
                ?>
            <div class="camp-draft-variant<?= $vi === 0 ? ' is-on' : '' ?>"
                 data-camp-draft-variant="<?= h($let !== '' ? $let : (string) $did) ?>"
                 data-draft-id="<?= $did ?>"
                 data-camp-draft-title="<?= h($title) ?>"
                 data-camp-draft-subject="<?= h($subject) ?>">
              <span class="visually-hidden"><?= h($title) ?></span>
              <?php if ($subject !== ''): ?>
              <p class="camp-draft-subject muted" style="margin:0.15rem 0 0.35rem">
                Subject: <strong data-camp-draft-subject-label><?= h($subject) ?></strong>
              </p>
              <?php endif; ?>
              <?php
                $attr = email_campaign_draft_attribution($d);
                if ($attr !== ''):
              ?>
              <p class="help camp-draft-attribution" style="margin:0.2rem 0 0.45rem"><?= h($attr) ?></p>
              <?php endif; ?>
              <div class="camp-draft-preview camp-draft-rich" data-camp-draft-preview data-camp-draft-html><?= $bodyHtml ?></div>
              <?php if ($sizeWarn !== ''): ?>
              <p class="help camp-draft-size-warn" style="margin:0.45rem 0 0"><?= h($sizeWarn) ?></p>
              <?php endif; ?>
              <div class="camp-draft-card-actions actions">
                <button type="button" class="btn small" data-camp-draft-copy
                        title="Copy with formatting for email paste">Copy</button>
                <button type="button" class="btn secondary small" data-camp-draft-copy-plain
                        title="Copy plain text only (reliable in any email client)">Copy plain</button>
                <button type="button" class="btn secondary small" data-camp-draft-expand>Show full</button>
                <a class="btn secondary small" href="<?= h($editHref) ?>">Edit</a>
                <?php if ($canMoveUp): ?>
                <form method="post" action="<?= h($formAction) ?>" class="camp-draft-move-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="move_draft">
                  <input type="hidden" name="project_id" value="<?= $projectId ?>">
                  <input type="hidden" name="draft_id" value="<?= $did ?>">
                  <input type="hidden" name="direction" value="up">
                  <input type="hidden" name="filter_category" value="<?= h($filterCategory) ?>">
                  <input type="hidden" name="filter_q" value="<?= h($draftQ) ?>">
                  <input type="hidden" name="filter_dfolder" value="<?= h($filterFolderParam) ?>">
                  <button class="btn secondary small" type="submit" title="Move up">↑</button>
                </form>
                <?php endif; ?>
                <?php if ($canMoveDown): ?>
                <form method="post" action="<?= h($formAction) ?>" class="camp-draft-move-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="move_draft">
                  <input type="hidden" name="project_id" value="<?= $projectId ?>">
                  <input type="hidden" name="draft_id" value="<?= $did ?>">
                  <input type="hidden" name="direction" value="down">
                  <input type="hidden" name="filter_category" value="<?= h($filterCategory) ?>">
                  <input type="hidden" name="filter_q" value="<?= h($draftQ) ?>">
                  <input type="hidden" name="filter_dfolder" value="<?= h($filterFolderParam) ?>">
                  <button class="btn secondary small" type="submit" title="Move down">↓</button>
                </form>
                <?php endif; ?>
                <?php if (email_campaign_user_can_delete_draft($user, $d)): ?>
                <form method="post" action="<?= h($formAction) ?>" class="camp-draft-delete-form"
                      data-camp-draft-delete
                      onsubmit="return confirm(<?= h(json_encode('Delete draft “' . $title . '”?', JSON_UNESCAPED_UNICODE)) ?>);">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_draft">
                  <input type="hidden" name="project_id" value="<?= $projectId ?>">
                  <input type="hidden" name="draft_id" value="<?= $did ?>">
                  <input type="hidden" name="filter_category" value="<?= h($filterCategory) ?>">
                  <input type="hidden" name="filter_q" value="<?= h($draftQ) ?>">
                  <input type="hidden" name="filter_dfolder" value="<?= h($filterFolderParam) ?>">
                  <button class="btn danger small" type="submit">Delete</button>
                </form>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
            <p class="help camp-draft-copy-status" data-camp-draft-status hidden></p>
          </article>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="card" id="camp-draft-form" style="margin-top:1rem">
      <h2 style="margin:0 0 0.45rem">
        <?= $editDraft ? 'Edit draft' : 'New draft' ?>
        · <?= h($projectName) ?>
      </h2>
      <p class="help" style="margin-top:0">
        Format with <strong>bold</strong>, <em>italic</em>, <u>underline</u>, headings, lists, links, and images.
        Paste a screenshot or use <strong>Image</strong> (auto-compressed).
        <strong>Copy</strong> keeps formatting and pictures for Gmail / Outlook.
      </p>
      <form method="post" action="<?= h($formAction) ?>" class="camp-draft-form" autocomplete="off"
            data-show-processing="<?= $editDraft ? 'Updating draft…' : 'Saving draft…' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_draft">
        <input type="hidden" name="project_id" value="<?= $projectId ?>">
        <input type="hidden" name="draft_id" value="<?= $editDraft ? (int) $editDraft['id'] : 0 ?>">
        <input type="hidden" name="filter_category" value="<?= h($filterCategory) ?>">
        <input type="hidden" name="filter_q" value="<?= h($draftQ) ?>">
        <input type="hidden" name="filter_dfolder" value="<?= h($filterFolderParam) ?>">
        <div class="form-grid">
          <div>
            <label for="camp_draft_title">Title</label>
            <input id="camp_draft_title" name="title" required maxlength="180"
                   value="<?= h((string) ($editDraft['title'] ?? '')) ?>"
                   placeholder="e.g. First outreach · guest post">
          </div>
          <div>
            <label for="camp_draft_category">Category</label>
            <select id="camp_draft_category" name="category" required>
              <?php
              $selCat = normalize_email_campaign_draft_category((string) ($editDraft['category'] ?? ($filterCategory !== '' ? $filterCategory : 'first_outreach')));
              foreach ($categories as $slug => $label):
                  ?>
                <option value="<?= h($slug) ?>" <?= $selCat === $slug ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="camp_draft_folder">Folder</label>
            <select id="camp_draft_folder" name="folder_id">
              <option value="0">Unfiled</option>
              <?php
              $selFolder = (int) ($editDraft['folder_id'] ?? ($filterFolderId > 0 ? $filterFolderId : 0));
              foreach ($draftFolders as $folderOpt):
                  $optId = (int) $folderOpt['id'];
                  ?>
                <option value="<?= $optId ?>" <?= $selFolder === $optId ? 'selected' : '' ?>>
                  <?= h((string) $folderOpt['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="full">
            <label for="camp_draft_subject">Subject <span class="muted">(optional)</span></label>
            <input id="camp_draft_subject" name="subject" maxlength="255"
                   value="<?= h((string) ($editDraft['subject'] ?? '')) ?>"
                   placeholder="e.g. Quick idea for {domain}"
                   data-camp-draft-subject-input>
            <p class="help" style="margin:0.3rem 0 0">
              Insert token:
              <?php foreach (email_campaign_draft_token_defs() as $tok => $tokLabel): ?>
                <button type="button" class="btn secondary small" data-camp-draft-token="{<?= h($tok) ?>}"
                        data-camp-draft-token-target="camp_draft_subject"
                        title="<?= h($tokLabel) ?>">{<?= h($tok) ?></button>
              <?php endforeach; ?>
            </p>
          </div>
          <div class="full">
            <label for="camp_draft_body">Draft text</label>
            <p class="help" style="margin:0 0 0.4rem">
              Tokens in body:
              <?php foreach (email_campaign_draft_token_defs() as $tok => $tokLabel): ?>
                <button type="button" class="btn secondary small" data-camp-draft-token="{<?= h($tok) ?>}"
                        data-camp-draft-token-target="body"
                        title="<?= h($tokLabel) ?>">{<?= h($tok) ?></button>
              <?php endforeach; ?>
            </p>
            <?php
            render_email_campaign_draft_editor(
                'camp_draft_body',
                'body',
                (string) ($editDraft['body'] ?? ''),
                ['placeholder' => "Hi {name},\n\nWe’d love to feature {domain}…\n\nBest,"]
            );
            ?>
          </div>
        </div>
        <p class="actions" style="margin-top:0.85rem">
          <button class="btn" type="submit"><?= $editDraft ? 'Update draft' : 'Save draft' ?></button>
          <?php if ($editDraft): ?>
            <a class="btn secondary" href="<?= h($formAction) ?>#camp-draft-form">Cancel edit</a>
          <?php endif; ?>
        </p>
      </form>
    </div>
  </div>
</div>
<script src="<?= h(script_asset_url('js/email-campaign-drafts.js')) ?>" defer></script>
<?php
render_footer('team');
