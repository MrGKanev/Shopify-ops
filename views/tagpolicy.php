<?= topbar('Tag Policy Audit', 'Required and forbidden Shopify tag combinations') ?>

<?= featureInfoStart('tagpolicy', 'Tag Policy Audit') ?>
  <p><strong>Tag Policy Audit</strong> validates paid Shopify orders against local tag rules in <code>tag_policy.json</code>.</p>
  <ul>
    <li><strong>Required</strong> rules: when all trigger tags exist, required tags must also exist.</li>
    <li><strong>Forbidden</strong> rules: all listed tags must not appear together on the same order.</li>
  </ul>
<?= featureInfoEnd() ?>

<?php if (empty(($tpConfig['required'] ?? [])) && empty(($tpConfig['forbidden'] ?? []))): ?>
  <div class="table-wrap mb-4">
    <div class="empty p-6">
      <div class="icon">⚙</div>
      <h3>No tag policy configured</h3>
      <p>Create <code>tag_policy.json</code> from <code>tag_policy.example.json</code> to enable this audit.</p>
    </div>
  </div>
<?php endif; ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Checks paid orders against the configured required and forbidden tag rules.</div>
  <?php if ($tpError): ?><div class="error-msg mb-3"><?= esc($tpError) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="scan_tagpolicy">
    <?php dateRangePartial('tp', $tpStart, $tpEnd) ?>
  </form>
  <?php if ($tpResult !== null): ?>
    <div class="duration-note mt-4 mb-0">Scanned <strong><?= $tpResult['scanned'] ?></strong> orders - <strong><?= count($tpResult['rows']) ?></strong> policy violations</div>
  <?php endif; ?>
</div>

<?php if ($tpResult !== null): ?>
  <?php if (empty($tpResult['rows'])): ?>
    <?= tableWrapEmpty('No tag policy violations', 'No scanned orders violated the configured tag policy.') ?>
  <?php else: ?>
    <div class="table-wrap">
      <?= tableWrapHeader($tpResult['rows'], 'tbl-tagpolicy', 'Tag Policy Violations', 'tag-policy', $tpResult['start'], 'violation', 'Filter by order #, tag, rule…') ?>
      <table id="tbl-tagpolicy">
        <thead><tr><th>Order</th><th>Placed</th><th>Violations</th><th>Tags</th><th>Email</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($tpResult['rows'] as $row):
            $adminUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
          ?>
          <tr>
            <?= orderNumCell($row['order_number'], $adminUrl) ?>
            <td><?= esc($row['created_at']) ?></td>
            <td>
              <div class="flex flex-col gap-1">
                <?php foreach ($row['violations'] as $v): ?>
                  <span class="chip <?= $v['type'] === 'forbidden' ? 'chip-unpaid' : 'chip-partial' ?>" title="<?= esc($v['detail']) ?>"><?= esc($v['name']) ?></span>
                <?php endforeach; ?>
              </div>
            </td>
            <td class="text-sm"><?= esc(implode(', ', $row['tags'])) ?></td>
            <td class="td-email"><?= esc($row['email']) ?></td>
            <td>
              <span class="chip <?= financialChip($row['financial']) ?>"><?= esc($row['financial']) ?></span>
              <?php if ($row['fulfillment']): ?><span class="chip chip-partial"><?= esc(str_replace('_', ' ', $row['fulfillment'])) ?></span><?php endif; ?>
            </td>
            <?= actionLinks(['shopifyUrl' => $adminUrl, 'orderNum' => $row['order_number'], 'email' => $row['email'], 'spotcheck' => true]) ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
