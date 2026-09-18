<?php
/**
 * Board region renderer — lanes + cards + add-lane ghost.
 *
 * SHARED RENDERER (RT-04/05, SPECIFICATION 5.19): included by both
 * - www/board.php (full page) and
 * - GET /v1/boards/{id}/region (the real-time sync fragment, SPECIFICATION 5.19)
 * so the board page and the sync fragment are structurally identical —
 * there is no second, hand-rolled client-side card renderer (RT-04).
 *
 * Expected in scope: $board (array with 'lanes'), $canEdit (bool), $lang (Lang).
 * Renders the INNER content of .board-lanes-container (the opening div,
 * the screen-reader announcer and the page wrapper stay in board.php).
 */
?>
<?php foreach ($board['lanes'] as $lane): ?>
        <section class="lane" data-lane-id="<?= (int) $lane['id'] ?>" data-lane-position="<?= (int) $lane['position'] ?>" aria-label="<?= htmlspecialchars($lane['title'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="lane-header">
                <?php if (!empty($lane['icon'])): ?>
                <span class="lane-icon" aria-hidden="true"><?= htmlspecialchars($lane['icon'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <h2 class="lane-title" <?php if ($canEdit): ?>contenteditable="false" tabindex="0" role="button" aria-label="<?= htmlspecialchars($lane['title'], ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>><?= htmlspecialchars($lane['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                <span class="lane-card-count" aria-label="<?= count($lane['cards']) ?> cards"><?= count($lane['cards']) ?></span>
                <?php if ($canEdit): ?>
                <button type="button" class="lane-menu-btn" aria-label="<?= htmlspecialchars($lang->get('action.edit'), ENT_QUOTES, 'UTF-8') ?>" aria-haspopup="true" data-lane-menu="<?= (int) $lane['id'] ?>">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <circle cx="8" cy="3" r="1.5" fill="currentColor"/>
                        <circle cx="8" cy="8" r="1.5" fill="currentColor"/>
                        <circle cx="8" cy="13" r="1.5" fill="currentColor"/>
                    </svg>
                </button>
                <?php endif; ?>
            </div>
            <div class="lane-cards" data-lane-id="<?= (int) $lane['id'] ?>" role="list" aria-label="<?= htmlspecialchars($lang->get('card.title'), ENT_QUOTES, 'UTF-8') ?>">
                <?php foreach ($lane['cards'] as $card): ?>
                <?php
                $hasMeta = !empty($card['due_date'])
                    || ($card['comment_count'] ?? 0) > 0
                    || ($card['checklist_progress']['total'] ?? 0) > 0
                    || ($card['attachment_count'] ?? 0) > 0
                    || !empty($card['assigned_users'])
                    || (!empty($card['labels']) && count($card['labels']) > 0)
                    || isset($card['preview_attachment']);
                $cardArchived = !empty($card['is_archived']);
                $cardMetaId = $hasMeta ? 'card-meta-' . (int) $card['id'] : null;
                ?>
                <article class="card<?= $canEdit ? '' : ' card--readonly' ?><?= $cardArchived ? ' card--archived' : '' ?>" draggable="<?= $canEdit && !$cardArchived ? 'true' : 'false' ?>" data-card-id="<?= (int) $card['id'] ?>" data-card-position="<?= (int) $card['position'] ?>" data-assigned="<?= htmlspecialchars(json_encode(array_map(fn($u) => (int) $u['id'], $card['assigned_users'] ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>" role="listitem" aria-roledescription="<?= htmlspecialchars($canEdit ? $lang->get('card.draggable_card') : $lang->get('card.card'), ENT_QUOTES, 'UTF-8') ?>" tabindex="0"<?= $cardMetaId ? ' aria-describedby="' . $cardMetaId . '"' : '' ?>>
                    <a href="/board.php?id=<?= (int) $boardId ?>&amp;card=<?= (int) $card['id'] ?>" class="card-link">
                        <?php if ($cardArchived): ?>
                        <span class="card-archived-badge"><?= htmlspecialchars($lang->get('card.archived'), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <span class="card-title"><?= htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($hasMeta): ?>
                        <div class="card-meta" id="<?= $cardMetaId ?>">
                            <?php if (isset($card['preview_attachment'])): ?>
                            <?php
                            // Board tile thumbnail (FILE-06, §5.23): the card's
                            // first previewable attachment — a decorative icon
                            // for the file, not its content. `loading=lazy`
                            // defers the fetch until the tile nears the
                            // viewport; fixed width/height keep the flex row
                            // from reflowing when the bytes arrive (no CLS).
                            // A failed load leaves an empty transparent slot
                            // (no visible artifact — same as any broken img).
                            ?>
                            <img class="card-thumb" loading="lazy" src="<?= htmlspecialchars('/v1/attachments/' . (int) $card['preview_attachment']['id'] . '/preview', ENT_QUOTES, 'UTF-8') ?>" alt="" width="28" height="28" aria-hidden="true">
                            <?php endif; ?>
                            <?php if (!empty($card['labels']) && count($card['labels']) > 0): ?>
                            <?php
                            // Label dots (LABEL-01): one colored dot per attached label.
                            // Color is the server-stored hex (palette or free-hex),
                            // so the dot always matches the chip in the card modal.
                            // Screen readers get the label names; dots are decorative.
                            $_labelNames = implode(', ', array_column($card['labels'], 'name'));
                            $_labelCap   = 4; // match the avatar-stack cap for visual balance
                            $_labels = array_slice($card['labels'], 0, $_labelCap);
                            $_labelOverflow = count($card['labels']) - $_labelCap;
                            ?>
                            <span class="card-label-dots" aria-label="<?= htmlspecialchars($lang->get('label.card_board', [$_labelNames]), ENT_QUOTES, 'UTF-8') ?>">
                                <?php foreach ($_labels as $_label): ?>
                                <?php
                                $_dotColor = is_string($_label['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $_label['color']) ? $_label['color'] : '#8A8FA3';
                                ?>
                                <span class="card-label-dot" style="background-color: <?= htmlspecialchars($_dotColor, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($_label['name'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></span>
                                <?php endforeach; ?>
                                <?php if ($_labelOverflow > 0): ?>
                                <span class="card-label-dot card-label-dot--overflow" title="<?= $_labelOverflow ?> more" aria-hidden="true">+<?= $_labelOverflow ?></span>
                                <?php endif; ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($card['due_date'])): ?>
                            <?php
                            $dueDate = new DateTime($card['due_date']);
                            $today = new DateTime('today');
                            $diff = $today->diff($dueDate);
                            $dueDateClass = '';
                            $dueDateLabel = '';
                            if ($dueDate < $today) {
                                $dueDateClass = ' card-due-date--overdue';
                                $dueDateLabel = $lang->get('card.due_overdue');
                            } elseif ($diff->days <= 2) {
                                $dueDateClass = ' card-due-date--soon';
                                $dueDateLabel = $lang->get('card.due_soon');
                            }
                            ?>
                            <span class="card-meta-item card-due-date<?= $dueDateClass ?>">
                                <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M5 1v2m6-2v2M2 6h12M3 3h10a1 1 0 011 1v9a1 1 0 01-1 1H3a1 1 0 01-1-1V4a1 1 0 011-1z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                                <?= htmlspecialchars($dueDate->format('M j'), ENT_QUOTES, 'UTF-8') ?><?php if ($dueDateLabel): ?><span class="sr-only"> (<?= htmlspecialchars($dueDateLabel, ENT_QUOTES, 'UTF-8') ?>)</span><?php endif; ?>
                            </span>
                            <?php endif; ?>

                            <?php if (($card['comment_count'] ?? 0) > 0): ?>
                            <span class="card-meta-item">
                                <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 3h12v8H6l-4 3V3z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?= (int) $card['comment_count'] ?>
                            </span>
                            <?php endif; ?>

                            <?php if (($card['checklist_progress']['total'] ?? 0) > 0): ?>
                            <span class="card-meta-item">
                                <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8l3 3 7-7" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?= (int) $card['checklist_progress']['done'] ?>/<?= (int) $card['checklist_progress']['total'] ?>
                            </span>
                            <?php endif; ?>

                            <?php if (($card['attachment_count'] ?? 0) > 0): ?>
                            <span class="card-meta-item">
                                <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 3v8m0 0l3-3m-3 3L5 8" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <?= (int) $card['attachment_count'] ?>
                            </span>
                            <?php endif; ?>

                            <?php if (!empty($card['assigned_users'])): ?>
                            <?php
                                $_allAssigneeNames = implode(', ', array_column($card['assigned_users'], 'name'));
                                $_assigneesLabel   = $lang->get('card.assigned_to', [$_allAssigneeNames]);
                            ?>
                            <span class="card-assignees" aria-label="<?= htmlspecialchars($_assigneesLabel, ENT_QUOTES, 'UTF-8') ?>">
                                <?php
                                    $_avatarUsers = $card['assigned_users'];
                                    $_avatarCap   = 3;
                                    require ROOT_DIR . '/include/templates/assignee-avatar-stack.php';
                                ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </a>
                    <?php if ($canEdit): ?>
                    <button type="button" class="card-menu-btn" aria-label="<?= htmlspecialchars($lang->get('card.card_options'), ENT_QUOTES, 'UTF-8') ?>" aria-haspopup="true" data-card-menu="<?= (int) $card['id'] ?>">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                            <circle cx="3" cy="8" r="1.5" fill="currentColor"/>
                            <circle cx="8" cy="8" r="1.5" fill="currentColor"/>
                            <circle cx="13" cy="8" r="1.5" fill="currentColor"/>
                        </svg>
                    </button>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div>
            <?php if ($canEdit): ?>
            <div class="lane-footer">
                <button type="button" class="lane-add-card-btn" data-add-card="<?= (int) $lane['id'] ?>">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M7 1v12M1 7h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <?= htmlspecialchars($lang->get('card.create'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
            <?php endif; ?>
        </section>
        <?php endforeach; ?>

        <?php if ($canEdit): ?>
        <div class="lane-ghost" id="lane-ghost">
            <button type="button" class="lane-ghost-button" id="btn-add-lane">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 1v14M1 8h14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                <?= htmlspecialchars($lang->get('lane.create'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
        <?php endif; ?>