<?= topbar('Email Checker', 'Find orders with invalid, disposable, or suspicious email addresses') ?>

<?= featureInfoStart('emailcheck', 'Email Checker') ?>
    <p><strong>Email Checker</strong> scans paid Shopify orders in the selected date range and flags those with email addresses that are likely to cause delivery failures - shipping confirmations, tracking updates, and receipts will never reach the customer.</p>
    <ul>
      <li><strong>Critical</strong> - missing email, invalid format, or a known disposable/temporary domain (Mailinator, YOPmail, 10MinuteMail, etc.).</li>
      <li><strong>Warning</strong> - placeholder-looking addresses (test@, noemail@), very short local parts, or suspicious repeated characters.</li>
    </ul>
    <p>Catching these early lets you reach out to the customer via phone or correct the email before the order ships.</p>
<?= featureInfoEnd() ?>

<div class="run-form">
  <h2>Scan date range</h2>
  <div class="hint">Scans all paid and partially paid orders in the range for email issues.</div>

  <?php if ($emailError): ?>
    <div class="error-msg mb-3"><?= esc($emailError) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="scan_emails">
    <?php dateRangePartial('email', $emailStart, $emailEnd) ?>
  </form>

  <?php if ($emailResult !== null): ?>
    <div class="duration-note mt-4 mb-0 flex items-center gap-3 flex-wrap">
      <span>Scanned <strong><?= $emailResult['scanned'] ?></strong> orders
        (<?= esc($emailResult['start']) ?> → <?= esc($emailResult['end']) ?>)
        &mdash; <strong><?= count($emailResult['rows']) ?></strong> with email issues</span>
      <?php if ($emailResult['critical'] > 0): ?>
        <span class="refund-risk-badge refund-risk-active"><?= $emailResult['critical'] ?> critical</span>
      <?php endif; ?>
      <?php if ($emailResult['warnings'] > 0): ?>
        <span class="refund-risk-badge refund-risk-missing"><?= $emailResult['warnings'] ?> warnings</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($emailResult !== null): ?>
  <?php if (empty($emailResult['rows'])): ?>
    <?= tableWrapEmpty('All emails look valid', 'No email issues found across ' . $emailResult['scanned'] . ' orders.') ?>
  <?php else: ?>
    <form method="post" id="bulk-emailcheck-form">
      <input type="hidden" name="action" value="bulk_ignore_orders">
      <input type="hidden" name="redirect_page" value="emailcheck">

      <div class="bulk-bar" id="bar-emailcheck">
        <span class="bulk-count" id="cnt-emailcheck">0 selected</span>
        <input type="text" class="bulk-reason" name="reason" placeholder="Reason (optional)">
        <button class="btn btn-sm btn-danger" type="submit">Ignore selected</button>
        <button class="btn btn-sm btn-ghost" type="button"
          onclick="document.querySelectorAll('#emailcheck-tbody .js-row-check').forEach(function(c){c.checked=false});updateBulkBar('emailcheck')">
          Clear
        </button>
        <button class="btn btn-sm btn-ghost" type="button"
          onclick="exportSelectedCSV('emailcheck','#tbl-emailcheck','email-issues-selected.csv')">
          Export selected
        </button>
      </div>

      <div class="table-wrap">
        <?= tableWrapHeader($emailResult['rows'], 'tbl-emailcheck', 'Email Issues', 'email-issues', $emailResult['start']) ?>
        <table id="tbl-emailcheck">
          <thead>
            <tr>
              <th class="col-check">
                <input type="checkbox" class="js-select-all" data-target="emailcheck-tbody" data-bar="emailcheck" title="Select all">
              </th>
              <th>Order</th>
              <th>Date</th>
              <th>Email</th>
              <th>Issues</th>
              <th>Severity</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="emailcheck-tbody">
            <?php foreach ($emailResult['rows'] as $row):
              $adminUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
            ?>
            <tr>
              <td class="col-check">
                <input type="checkbox" class="js-row-check" name="order_numbers[]"
                       value="<?= esc(ltrim($row['order_number'], '#')) ?>"
                       data-bar="emailcheck" onchange="updateBulkBar('emailcheck')">
              </td>
              <?= orderNumCell($row['order_number'], $adminUrl) ?>
              <td><?= esc($row['created_at']) ?></td>
              <td class="td-email">
                <?= esc($row['email'] ?: '(empty)') ?>
                <?php if ($row['email']): ?>
                  <button class="copy-btn" style="opacity:1" data-copy="<?= esc($row['email']) ?>" title="Copy email">⧉</button>
                <?php endif; ?>
              </td>
              <td>
                <div class="flex flex-col gap-1">
                  <?php foreach ($row['issues'] as $issue): ?>
                    <span class="addr-issue addr-issue-<?= $issue['level'] ?>"><?= esc($issue['message']) ?></span>
                  <?php endforeach; ?>
                </div>
              </td>
              <td>
                <span class="refund-risk-badge <?= $row['severity'] === 'critical' ? 'refund-risk-active' : 'refund-risk-missing' ?>">
                  <?= $row['severity'] ?>
                </span>
              </td>
              <?= actionLinks(['shopifyUrl' => $adminUrl, 'shopifyLabel' => 'Edit in Shopify', 'orderNum' => $row['order_number'], 'spotcheck' => true]) ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </form>
  <?php endif; ?>
<?php endif; ?>
