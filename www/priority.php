<?php
/**
 * Personal Priority List Page (PRIO-01..11)
 *
 * Server-rendered "work on next" view across all boards: the computed Inbox
 * section (tiered: In Progress, Inbox, Other) plus the user's Prioritized
 * section (custom order). JS adds Prioritize/Remove and drag-to-reorder;
 * without JS the list still renders and every card links to its card page.
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

// Require authentication (PRIO-11)
$currentUser = $auth->requireAuth();

// Assemble service
$cardModel     = new Shuffle\Model\Card($db);
$laneModel     = new Shuffle\Model\Lane($db);
$boardModel    = new Shuffle\Model\Board($db);
$userPrioModel = new Shuffle\Model\UserPrio($db);

// Digest dependency (PRIO-12..14): "Done yesterday" reads the card_activity
// log. CardService is not needed here (digest is pure read + activity scan),
// so we wire the log service directly.
$userModelForLog   = new Shuffle\Model\User($db);
$activityModel     = new Shuffle\Model\CardActivity($db);
$activityServiceDg = new Shuffle\Service\CardActivityService(
    $activityModel,
    $cardModel,
    $laneModel,
    $userModelForLog
);

$priorityService = new Shuffle\Service\PriorityService(
    $userPrioModel,
    $cardModel,
    $laneModel,
    $boardModel,
    $auth,
    $activityServiceDg,
    $lang
);

$list = $priorityService->getList($currentUser);

$pageTitle   = $lang->get('priority.title');
$currentPage = 'priority';

// Everyone (member/admin/viewer) acts on their own list.
$canEdit = true;

/**
 * Render a single priority list item (shared markup).
 *
 * @param array  $item          One item from PriorityService
 * @param bool   $isReorderable True in the Prioritized section (remove button)
 *                              False in the Inbox section (add button)
 */
function render_priority_item(array $item, bool $isReorderable, bool $canEdit): void
{
    global $lang;
    $title         = (string) ($item['card_title'] ?? '');
    $boardTitle    = (string) ($item['board_title'] ?? '');
    $laneTitle     = (string) ($item['lane_title'] ?? '');
    $cardHtml      = (string) ($item['card_html'] ?? '/boards.php');
    $marker        = (string) ($item['state_marker'] ?? '•');
    $dueDate       = $item['due_date'] ?? null;
    $cardId        = (int) ($item['card_id'] ?? 0);
    $actionLabel   = $isReorderable
        ? $lang->get('priority.action_remove')
        : $lang->get('priority.action_prioritize');
    $actionData    = $isReorderable ? 'remove' : 'prioritize';
    $ariaLabel     = htmlspecialchars(
        $actionLabel . ' — ' . $title,
        ENT_QUOTES, 'UTF-8'
    );

    $svg = $isReorderable
        ? '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
        : '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 2v12M2 8h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
    ?>
<span class="priority-item-title">
    <a href="<?= htmlspecialchars($cardHtml, ENT_QUOTES, 'UTF-8') ?>"
       class="priority-item-link"
       aria-label="<?= htmlspecialchars($title . ' — ' . $boardTitle . ' / ' . $laneTitle, ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
    </a>
</span>
<span class="priority-item-meta">
    <span class="priority-item-marker" title="<?= htmlspecialchars($laneTitle, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"><?= htmlspecialchars($marker, ENT_QUOTES, 'UTF-8') ?></span>
    <span class="priority-item-board" title="<?= htmlspecialchars($boardTitle, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($boardTitle, ENT_QUOTES, 'UTF-8') ?></span>
    <span class="priority-item-lane" title="<?= htmlspecialchars($laneTitle, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($laneTitle, ENT_QUOTES, 'UTF-8') ?></span>
    <?php if ($dueDate !== null && $dueDate !== ''): ?>
    <span class="priority-item-due" title="<?= htmlspecialchars($lang->get('priority.due') . ': ' . $dueDate, ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars((string) $dueDate, ENT_QUOTES, 'UTF-8') ?>
    </span>
    <?php endif; ?>
</span>
<?php if ($canEdit && $cardId > 0): ?>
<button type="button" class="btn btn-sm btn-ghost priority-item-action"
        data-priority-action="<?= $actionData ?>"
        data-card-id="<?= $cardId ?>"
        aria-label="<?= $ariaLabel ?>">
    <?= $svg ?>
</button>
<?php endif; ?>
<?php
}

require ROOT_DIR . '/include/templates/header.php';
?>

<div class="priority-page" id="priority-page">
    <header class="priority-page-header">
        <h1 class="priority-page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="priority-page-intro"><?= htmlspecialchars($lang->get('priority.intro'), ENT_QUOTES, 'UTF-8') ?></p>
    </header>

    <?php
    // Priority digest bar (PRIO-12..14). Progressive enhancement: without JS
    // the digest renders as a selectable pre block below; with JS the
    // "Copy digest" button fetches ?format=markdown (recomputed live with
    // the chosen N) and copies it to the clipboard.
    $digestN = 5; // default top-N (PRIO-12/13); the input is page-local in v1
    $digestMarkdown = '';
    try {
        $digestMarkdown = $priorityService->digestMarkdown($currentUser, $digestN);
    } catch (\Throwable $e) {
        // Activity log unavailable — the bar still renders; the copy button
        // will report the error and the fallback body stays empty.
    }
    $digestLang = json_encode([
        'label'      => $lang->get('priority.digest.label'),
        'desc'       => $lang->get('priority.digest.desc'),
        'top'        => $lang->get('priority.digest.top'),
        'copy'       => $lang->get('priority.digest.copy'),
        'copied'     => $lang->get('priority.digest.copied'),
        'fallback'   => $lang->get('priority.digest.fallback'),
        'error'      => $lang->get('priority.digest.error'),
        'empty'      => $lang->get('priority.digest.empty'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    ?>
    <div class="priority-digest" id="priority-digest"
         data-digest-n="<?= (int) $digestN ?>"
         data-lang="<?= htmlspecialchars($digestLang, ENT_QUOTES, 'UTF-8') ?>">
        <div class="priority-digest-controls">
            <label class="priority-digest-label" for="priority-digest-n"><?= htmlspecialchars($lang->get('priority.digest.label'), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="number" id="priority-digest-n" class="priority-digest-n"
                   min="1" max="50" step="1" value="<?= (int) $digestN ?>"
                   aria-describedby="priority-digest-desc" />
            <button type="button" id="priority-digest-copy" class="btn btn-sm btn-primary"
                    aria-describedby="priority-digest-desc">
                <?= htmlspecialchars($lang->get('priority.digest.copy'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <span id="priority-digest-status" class="priority-digest-status" role="status" aria-live="polite"></span>
        </div>
        <p class="priority-digest-desc" id="priority-digest-desc"><?= htmlspecialchars($lang->get('priority.digest.desc'), ENT_QUOTES, 'UTF-8') ?></p>
        <?php if (trim($digestMarkdown) !== ''): ?>
        <pre class="priority-digest-body" id="priority-digest-body"><?= htmlspecialchars($digestMarkdown, ENT_QUOTES, 'UTF-8') ?></pre>
        <?php else: ?>
        <pre class="priority-digest-body" id="priority-digest-body" style="display:none"></pre>
        <?php endif; ?>
    </div>

    <div class="priority-columns">
        <?php if ($list['inbox'] === [] && $list['prioritized'] === []): ?>
        <p class="priority-all-empty" role="status">
            <?= htmlspecialchars($lang->get('priority.inbox_empty'), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <?php else: ?>

        <!-- Inbox section -->
        <section class="priority-section" aria-labelledby="priority-inbox-heading" id="priority-inbox-section">
            <div class="priority-section-header">
                <h2 id="priority-inbox-heading" class="priority-section-title">
                    <span class="priority-section-count" data-count-section="inbox" aria-hidden="true"><?= count($list['inbox']) ?></span>
                    <?= htmlspecialchars($lang->get('priority.inbox'), ENT_QUOTES, 'UTF-8') ?>
                </h2>
                <p class="priority-section-desc"><?= htmlspecialchars($lang->get('priority.inbox_desc'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <?php if ($list['inbox'] === []): ?>
            <p class="priority-empty" role="status"><?= htmlspecialchars($lang->get('priority.inbox_empty'), ENT_QUOTES, 'UTF-8') ?></p>
            <?php else: ?>
            <ul class="priority-list" role="list" data-priority-section="inbox">
                <?php
                // Group inbox items by tier (1=In Progress, 2=Inbox, 3=Other)
                $tierBuckets = [
                    '1' => [],
                    '2' => [],
                    '3' => [],
                ];
                $labelByTier = [
                    '1' => $lang->get('priority.tier_in_progress'),
                    '2' => $lang->get('priority.tier_inbox'),
                    '3' => $lang->get('priority.tier_other'),
                ];
                foreach ($list['inbox'] as $item) {
                    $t = (string) ($item['tier'] ?? '3');
                    $tierBuckets[$t][] = $item;
                }
                foreach (['1','2','3'] as $tierIdx):
                    $tierItems = $tierBuckets[$tierIdx];
                    if ($tierItems === []) {
                        continue;
                    }
                ?>
                <li class="priority-tier" role="listitem" data-tier="<?= $tierIdx ?>">
                    <h3 class="priority-tier-label"><?= htmlspecialchars($labelByTier[$tierIdx], ENT_QUOTES, 'UTF-8') ?></h3>
                    <ul class="priority-tier-items" role="list">
                        <?php foreach ($tierItems as $item): ?>
                        <li class="priority-item priority-item--inbox" data-card-id="<?= (int) $item['card_id'] ?>">
                            <div class="priority-item-inner">
                                <?php render_priority_item($item, false, true); ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>

        <!-- Prioritized section -->
        <section class="priority-section" aria-labelledby="priority-prioritized-heading" id="priority-prioritized-section">
            <div class="priority-section-header">
                <h2 id="priority-prioritized-heading" class="priority-section-title">
                    <span class="priority-section-count" data-count-section="prioritized" aria-hidden="true"><?= count($list['prioritized']) ?></span>
                    <?= htmlspecialchars($lang->get('priority.prioritized'), ENT_QUOTES, 'UTF-8') ?>
                </h2>
                <p class="priority-section-desc"><?= htmlspecialchars($lang->get('priority.prioritized_desc'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <?php if ($list['prioritized'] === []): ?>
            <p class="priority-empty" role="status"><?= htmlspecialchars($lang->get('priority.prioritized_empty'), ENT_QUOTES, 'UTF-8') ?></p>
            <?php else: ?>
            <ul class="priority-list" role="list" data-priority-section="prioritized" id="priority-reorder-list">
                <?php foreach ($list['prioritized'] as $item): ?>
                <li class="priority-item priority-item--reorderable" data-card-id="<?= (int) $item['card_id'] ?>" draggable="true">
                    <div class="priority-item-inner">
                        <?php render_priority_item($item, true, true); ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>

        <?php endif; ?>
    </div>
</div>

<?php
$priorityLang = json_encode([
    'move_up'        => $lang->get('priority.move_up'),
    'move_down'      => $lang->get('priority.move_down'),
    'added'          => $lang->get('priority.added'),
    'removed'        => $lang->get('priority.removed'),
    'moved'          => $lang->get('priority.moved'),
    'error_failed'   => $lang->get('priority.error_failed'),
    'error_conflict' => $lang->get('priority.error_conflict'),
    'remove'         => $lang->get('priority.action_remove'),
    'prioritize'     => $lang->get('priority.action_prioritize'),
    'prioritized_empty' => $lang->get('priority.prioritized_empty'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>
<script id="priority-script" src="/js/priority.js" data-lang="<?= htmlspecialchars($priorityLang, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require ROOT_DIR . '/include/templates/footer.php'; ?>
