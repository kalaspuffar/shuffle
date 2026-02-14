<?php
/**
 * Search Results Page
 *
 * Displays full-text search results for cards across accessible boards.
 * Server-rendered page with results fetched via the SearchService.
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

// Require authentication
$currentUser = $auth->requireAuth();

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$boardId = isset($_GET['board_id']) && $_GET['board_id'] !== '' ? (int) $_GET['board_id'] : null;
$includeArchived = ($_GET['include_archived'] ?? 'true') !== 'false';

$searchResults = null;
$searchError = null;

if ($query !== '') {
    $searchService = new Shuffle\Service\SearchService($db);

    try {
        $searchResults = $searchService->search($query, $currentUser, $boardId, $includeArchived);
    } catch (\InvalidArgumentException $e) {
        $searchError = $e->getMessage();
    }
}

$pageTitle = $lang->get('search.results_title');
$currentPage = 'search';
require ROOT_DIR . '/include/templates/header.php';
?>

<div class="search-page">
    <h1 class="search-page-title"><?= htmlspecialchars($lang->get('search.results_title'), ENT_QUOTES, 'UTF-8') ?></h1>

    <form class="search-form" action="/search.php" method="get" role="search">
        <div class="search-form-group">
            <input type="search"
                   name="q"
                   class="search-input"
                   value="<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="<?= htmlspecialchars($lang->get('search.placeholder'), ENT_QUOTES, 'UTF-8') ?>"
                   aria-label="<?= htmlspecialchars($lang->get('search.search'), ENT_QUOTES, 'UTF-8') ?>"
                   minlength="3"
                   autofocus>
            <button type="submit" class="search-submit-btn">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <circle cx="7" cy="7" r="5" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M11 11l3.5 3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                <?= htmlspecialchars($lang->get('search.search'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </form>

    <?php if ($searchError !== null): ?>
    <div class="search-error" role="alert">
        <p><?= htmlspecialchars($searchError, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <?php elseif ($searchResults !== null): ?>

    <?php if ($query !== ''): ?>
    <p class="search-results-summary">
        <?= htmlspecialchars(str_replace('{0}', $query, $lang->get('search.results_for')), ENT_QUOTES, 'UTF-8') ?>
        — <?= (int) $searchResults['total'] ?> result<?= $searchResults['total'] !== 1 ? 's' : '' ?>
    </p>
    <?php endif; ?>

    <?php if (empty($searchResults['results'])): ?>
    <div class="search-no-results">
        <p><?= htmlspecialchars($lang->get('search.no_results'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <?php else: ?>
    <div class="search-results" role="list" aria-label="<?= htmlspecialchars($lang->get('search.results_title'), ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($searchResults['results'] as $result): ?>
        <article class="search-result-item<?= $result['is_archived'] ? ' search-result-item--archived' : '' ?>" role="listitem">
            <div class="search-result-header">
                <a href="/card.php?id=<?= (int) $result['card_id'] ?>" class="search-result-title">
                    <?= htmlspecialchars($result['card_title'], ENT_QUOTES, 'UTF-8') ?>
                </a>
                <?php if ($result['is_archived']): ?>
                <span class="search-result-badge search-result-badge--archived"><?= htmlspecialchars($lang->get('search.card_archived'), ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <?php if ($result['board_is_archived']): ?>
                <span class="search-result-badge search-result-badge--board-archived"><?= htmlspecialchars($lang->get('search.board_archived'), ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
            <?php if ($result['card_description_excerpt'] !== ''): ?>
            <p class="search-result-excerpt"><?= htmlspecialchars($result['card_description_excerpt'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <div class="search-result-meta">
                <a href="/board.php?id=<?= (int) $result['board_id'] ?>" class="search-result-board">
                    <?= htmlspecialchars($result['board_title'], ENT_QUOTES, 'UTF-8') ?>
                </a>
                <span class="search-result-separator" aria-hidden="true">&rsaquo;</span>
                <span class="search-result-lane"><?= htmlspecialchars($result['lane_title'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php require ROOT_DIR . '/include/templates/footer.php'; ?>
