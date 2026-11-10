<?php
/**
 * Блок AI-аналізу.
 * Очікує: $ai (результат normalize_ai()), $mode ('web' | 'print').
 */
if (!isset($ai) || !is_array($ai)) { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/helpers.php';

$aiMode = (isset($mode) && $mode === 'print') ? 'print' : 'web';
$aiTiles = [
    ['name' => 'E-E-A-T', 'hint' => 'досвід, експертиза, авторитетність, довіра', 'data' => $ai['eeat']],
    ['name' => 'Якість контенту', 'hint' => 'наскільки контент зручно цитувати AI', 'data' => $ai['content']],
];
$aiShowTiles = $ai['eeat']['score'] !== null || $ai['content']['score'] !== null
    || $ai['eeat']['notes'] !== '' || $ai['content']['notes'] !== '';
?>
<div class="ai-block">
<?php if (!$ai['has_content']): ?>
    <p class="muted ai-message"><?= h($ai['message'] !== '' ? $ai['message'] : 'AI-аналіз для цього звіту відсутній.') ?></p>
<?php else: ?>

    <?php if ($ai['summary'] !== ''): ?>
        <p class="ai-summary"><?= nl2br(h($ai['summary']), false) ?></p>
    <?php endif; ?>

    <?php if ($aiShowTiles): ?>
        <div class="ai-tiles">
            <?php foreach ($aiTiles as $t): $ts = $t['data']['score']; ?>
                <div class="ai-tile">
                    <div class="t-score <?= $ts !== null ? 'tier-' . tier_class($ts) : 'muted' ?>">
                        <?= $ts !== null ? (int)$ts : 'н/д' ?><?php if ($ts !== null): ?><small>/100</small><?php endif; ?>
                    </div>
                    <div class="t-name"><?= h($t['name']) ?> <span class="muted small" style="font-weight:400">- <?= h($t['hint']) ?></span></div>
                    <div class="t-notes"><?= h($t['data']['notes'] !== '' ? $t['data']['notes'] : 'Коментар відсутній.') ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($ai['priorities']): ?>
        <h3 class="ai-h">Топ-пріоритети</h3>
        <ol class="ai-prio">
            <?php foreach ($ai['priorities'] as $pi => $p): ?>
                <li class="prio-card">
                    <div class="prio-head">
                        <span class="prio-num"><?= (int)$pi + 1 ?></span>
                        <span class="prio-action"><?= h($p['action']) ?></span>
                    </div>
                    <?php if ($p['impact'] !== '' || $p['effort'] !== '' || $p['effort_raw'] !== ''): ?>
                        <div class="prio-badges">
                            <?php if ($p['impact'] !== ''): ?>
                                <span class="impact-badge sev-<?= h($p['impact_class']) ?>">Вплив: <?= h($p['impact']) ?></span>
                            <?php endif; ?>
                            <?php if ($p['effort'] !== ''): ?>
                                <span class="effort-badge" title="Орієнтовні зусилля: S - мінімальні, M - середні, L - великі">Зусилля: <?= h($p['effort']) ?></span>
                            <?php elseif ($p['effort_raw'] !== ''): ?>
                                <span class="effort-badge">Зусилля: <?= h($p['effort_raw']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($p['why'] !== ''): ?>
                        <p class="prio-why"><?= h($p['why']) ?></p>
                    <?php endif; ?>
                    <?php if ($p['example'] !== ''): ?>
                        <div class="prio-example"><span class="lbl">Приклад</span><?= rich_html($p['example'], true) ?></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($ai['quick_wins']): ?>
        <h3 class="ai-h">Швидкі перемоги</h3>
        <ul class="ai-checklist">
            <?php foreach ($ai['quick_wins'] as $qw): ?>
                <li><label><input type="checkbox"><span><?= h($qw) ?></span></label></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($ai['strategic']): ?>
        <h3 class="ai-h">Стратегічні рекомендації</h3>
        <ol class="ai-strategic">
            <?php foreach ($ai['strategic'] as $sr): ?>
                <li><?= h($sr) ?></li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($ai['test_prompts']): ?>
        <div class="tp-head">
            <h3 class="ai-h">Тестові запити для моніторингу AI-видимості</h3>
            <?php if ($aiMode === 'web'): ?>
                <button type="button" class="copy-btn" data-copy-from="tpAll">Скопіювати всі</button>
            <?php endif; ?>
        </div>
        <p class="muted small" style="margin:0">Поставте ці запити в ChatGPT, Perplexity, Gemini та Copilot зараз (це базова точка) і через 4-6 тижнів після змін. Порівняйте, чи згадується бренд і чи посилаються на сайт.</p>
        <ol class="tp-list">
            <?php foreach ($ai['test_prompts'] as $tp): ?>
                <li><?= h($tp) ?></li>
            <?php endforeach; ?>
        </ol>
        <?php if ($aiMode === 'web'): ?>
            <textarea id="tpAll" hidden readonly><?= h(implode("\n", $ai['test_prompts'])) ?></textarea>
        <?php endif; ?>
    <?php endif; ?>

<?php endif; ?>
</div>
