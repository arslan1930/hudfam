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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action');
    $projectId = (int) post('project_id');
    $returnCat = trim((string) post('filter_category'));
    $back = $base . '&project=' . max(0, $projectId);
    if ($returnCat !== '' && isset($categories[$returnCat])) {
        $back .= '&category=' . rawurlencode($returnCat);
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
            (string) post('subject')
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
if ($filterCategory !== '' && !isset($categories[$filterCategory])) {
    $filterCategory = '';
}

// Default to first visible project when none selected.
if ($projectId < 1 && $projects !== []) {
    $projectId = (int) $projects[0]['id'];
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
    array_map(static fn ($p) => (int) $p['id'], $projects)
);

$drafts = $selectedProject
    ? list_email_campaign_drafts($projectId, $filterCategory !== '' ? $filterCategory : null)
    : [];
$editId = (int) get('edit');
$editDraft = ($editId > 0) ? get_email_campaign_draft($editId) : null;
if ($editDraft && (int) ($editDraft['project_id'] ?? 0) !== $projectId) {
    $editDraft = null;
    $editId = 0;
}

$formAction = $base . '&project=' . $projectId;
if ($filterCategory !== '') {
    $formAction .= '&category=' . rawurlencode($filterCategory);
}

$draftVars = [
    'domain' => trim((string) get('domain')),
    'country' => trim((string) get('country')),
    'language' => trim((string) get('language')),
    'name' => trim((string) get('name')),
];
$hasDraftVars = $draftVars['domain'] !== '' || $draftVars['country'] !== ''
    || $draftVars['language'] !== '' || $draftVars['name'] !== '';

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
          $href = $base . '&project=' . $pid;
          if ($filterCategory !== '') {
              $href .= '&category=' . rawurlencode($filterCategory);
          }
          if ($hasDraftVars) {
              foreach ($draftVars as $vk => $vv) {
                  if ($vv !== '') {
                      $href .= '&' . rawurlencode($vk) . '=' . rawurlencode($vv);
                  }
              }
          }
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
    ?>
    <div class="card">
      <div class="invoice-list-toolbar swe-list-toolbar">
        <div>
          <h2 style="margin:0"><?= h($projectName) ?></h2>
          <p class="help" style="margin:0.25rem 0 0">
            <?= (int) $draftCount ?> draft<?= (int) $draftCount === 1 ? '' : 's' ?>
            <?= $filterCategory !== '' ? ' in “' . h(email_campaign_draft_category_label($filterCategory)) . '”' : '' ?>.
            Copy keeps bold / italic / underline / headings / lists / links for paste into your mail client.
            Tokens: <code>{domain}</code> <code>{site}</code> <code>{country}</code> <code>{language}</code> <code>{name}</code>.
            <?php if ($hasDraftVars): ?>
              · Filling from site
              <?= $draftVars['domain'] !== '' ? '<strong>' . h($draftVars['domain']) . '</strong>' : '' ?>
              <?= $draftVars['country'] !== '' ? ' · ' . h($draftVars['country']) : '' ?>
            <?php endif; ?>
          </p>
          <p class="swe-sent-filters camp-drafts-filters">
            <?php
            $catLinks = ['' => 'All'] + $categories;
            foreach ($catLinks as $slug => $label):
                $href = $base . '&project=' . $projectId;
                if ($slug !== '') {
                    $href .= '&category=' . rawurlencode($slug);
                }
                $active = $filterCategory === (string) $slug;
                ?>
              <a class="btn small <?= $active ? '' : 'secondary' ?>" href="<?= h($href) ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
          </p>
        </div>
        <div class="actions">
          <a class="btn small" href="<?= h($formAction) ?>#camp-draft-form">+ New draft</a>
        </div>
      </div>

      <?php if ($drafts === []): ?>
      <div class="empty-state" id="camp-drafts-empty">
        <p>No drafts<?= $filterCategory !== '' ? ' in this category' : ' in this project' ?> yet.</p>
        <p class="muted">Add your first outreach / offer / reply text below.</p>
      </div>
      <?php else: ?>
      <div class="camp-drafts-grid">
        <?php
        $draftTotal = count($drafts);
        foreach ($drafts as $di => $d):
            $did = (int) $d['id'];
            $title = (string) $d['title'];
            $subject = trim((string) ($d['subject'] ?? ''));
            $bodyHtml = email_campaign_draft_body_html((string) $d['body']);
            $sizeWarn = email_campaign_draft_size_warning((string) $d['body']);
            $cat = (string) $d['category'];
            $canMoveUp = $di > 0
                && (string) ($drafts[$di - 1]['category'] ?? '') === $cat;
            $canMoveDown = $di < ($draftTotal - 1)
                && (string) ($drafts[$di + 1]['category'] ?? '') === $cat;
            $editHref = $formAction . '&edit=' . $did . '#camp-draft-form';
            if ($hasDraftVars) {
                foreach ($draftVars as $vk => $vv) {
                    if ($vv !== '') {
                        $editHref .= '&' . rawurlencode($vk) . '=' . rawurlencode($vv);
                    }
                }
            }
            ?>
          <article class="camp-draft-card" data-camp-draft-card data-draft-id="<?= $did ?>"
                   data-camp-draft-subject="<?= h($subject) ?>"
                   data-token-domain="<?= h($draftVars['domain']) ?>"
                   data-token-country="<?= h($draftVars['country']) ?>"
                   data-token-language="<?= h($draftVars['language']) ?>"
                   data-token-name="<?= h($draftVars['name']) ?>">
            <div class="camp-draft-card-head">
              <h3 class="camp-draft-title"><?= h($title) ?></h3>
              <span class="swe-status-badge is-ready"><?= h(email_campaign_draft_category_label($cat)) ?></span>
            </div>
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
              <a class="btn secondary small" href="<?= h($editHref) ?>">Edit</a>
              <?php if ($canMoveUp): ?>
              <form method="post" action="<?= h($formAction) ?>" class="camp-draft-move-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="move_draft">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <input type="hidden" name="draft_id" value="<?= $did ?>">
                <input type="hidden" name="direction" value="up">
                <input type="hidden" name="filter_category" value="<?= h($filterCategory) ?>">
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
                <button class="btn secondary small" type="submit" title="Move down">↓</button>
              </form>
              <?php endif; ?>
              <?php if (email_campaign_user_can_delete_draft($user, $d)): ?>
              <form method="post" action="<?= h($formAction) ?>" class="camp-draft-delete-form"
                    data-camp-draft-delete
                    <?= confirm_attrs(
                        'Delete draft “' . $title . '”?',
                        ['title' => 'Delete draft?', 'confirm_label' => 'Delete', 'danger' => true]
                    ) ?>>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_draft">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                <input type="hidden" name="draft_id" value="<?= $did ?>">
                <input type="hidden" name="filter_category" value="<?= h($filterCategory) ?>">
                <button class="btn danger small" type="submit">Delete</button>
              </form>
              <?php endif; ?>
            </div>
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
