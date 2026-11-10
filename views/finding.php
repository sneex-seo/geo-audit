<?php
/**
 * Картка однієї знахідки.
 * Очікує: $f (нормалізована знахідка з report_findings()), $fi (int, порядковий номер),
 *         $mode ('web' | 'print').
 */
if (!isset($f) || !is_array($f)) { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/helpers.php';

$fMode = (isset($mode) && $mode === 'print') ? 'print' : 'web';
$fIdx = isset($fi) ? (int)$fi : 0;
$fSev = (string)($f['severity'] ?? 'low');
$fTag = $fMode === 'print' ? 'div' : 'details';
$fHeadTag = $fMode === 'print' ? 'div' : 'summary';
$fEffort = (string)($f['effort'] ?? '');

$hasBad = !rich_empty($f['bad'] ?? '');
$hasGood = !rich_empty($f['good'] ?? '');
$hasCode = trim((string)($f['code'] ?? '')) !== '';
$hasBody = !rich_empty($f['evidence'] ?? '') || !rich_empty($f['why'] ?? '') || !rich_empty($f['fix'] ?? '')
    || $hasBad || $hasGood || $hasCode || !rich_empty($f['verify'] ?? '');
?>
<<?= $fTag ?> class="fcard sev-<?= h($fSev) ?>" id="f-<?= $fIdx ?>" data-sev="<?= h($fSev) ?>" data-cat="<?= (int)($f['cat_index'] ?? 0) ?>">
    <<?= $fHeadTag ?> class="f-head">
        <span class="sev-badge sev-<?= h($fSev) ?>"><?= h(severity_label_uk($fSev)) ?></span>
        <span class="f-title"><?= h($f['text'] ?? '') ?></span>
        <span class="f-meta">
            <span class="f-cat"><?= h($f['category'] ?? '') ?></span>
            <?php if ($fEffort !== ''): ?>
                <span class="effort-badge" title="Орієнтовні зусилля на впровадження"><?= h($fEffort) ?> &middot; <?= h(effort_label_uk($fEffort)) ?></span>
            <?php endif; ?>
        </span>
    </<?= $fHeadTag ?>>
    <div class="f-body">
        <?php if (!$hasBody): ?>
            <p class="muted small" style="margin:10px 0 0">Додаткових деталей для цього зауваження немає.</p>
        <?php endif; ?>

        <?php if (!rich_empty($f['evidence'] ?? '')): ?>
            <div class="f-sec">
                <h4>Що виявлено</h4>
                <?= rich_html($f['evidence'], true) ?>
            </div>
        <?php endif; ?>

        <?php if (!rich_empty($f['why'] ?? '')): ?>
            <div class="f-sec">
                <h4>Чому це важливо</h4>
                <?= rich_html($f['why']) ?>
            </div>
        <?php endif; ?>

        <?php if (!rich_empty($f['fix'] ?? '')): ?>
            <div class="f-sec">
                <h4>Що зробити</h4>
                <?= rich_html($f['fix']) ?>
            </div>
        <?php endif; ?>

        <?php if ($hasBad || $hasGood): ?>
            <div class="compare<?= ($hasBad && $hasGood) ? '' : ' single' ?>">
                <?php if ($hasBad): ?>
                    <div class="cmp bad">
                        <h4>Як зараз</h4>
                        <?= rich_html($f['bad'], true) ?>
                    </div>
                <?php endif; ?>
                <?php if ($hasGood): ?>
                    <div class="cmp good">
                        <h4>Як треба</h4>
                        <?= rich_html($f['good'], true) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($hasCode): ?>
            <div class="code-wrap">
                <div class="code-head">
                    <h4>Готовий код</h4>
                    <?php if ($fMode === 'web'): ?>
                        <button type="button" class="copy-btn" data-copy-from="code-<?= $fIdx ?>">Копіювати</button>
                    <?php endif; ?>
                </div>
                <pre class="code"><code id="code-<?= $fIdx ?>"><?= h($f['code']) ?></code></pre>
            </div>
        <?php endif; ?>

        <?php if (!rich_empty($f['verify'] ?? '')): ?>
            <div class="f-sec">
                <h4>Як перевірити</h4>
                <?= rich_html($f['verify']) ?>
            </div>
        <?php endif; ?>

        <?php if (($f['id'] ?? '') !== ''): ?>
            <div class="f-id">Код перевірки: <?= h($f['id']) ?></div>
        <?php endif; ?>
    </div>
</<?= $fTag ?>>
