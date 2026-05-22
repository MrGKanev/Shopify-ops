<?= topbar('Email Checker', 'Find orders with invalid, disposable, or suspicious email addresses') ?>

<?= featureInfoStart('emailcheck', 'Email Checker') ?>
    <p><strong>Email Checker</strong> scans paid Shopify orders in the selected date range and flags those with email addresses that are likely to cause delivery failures — shipping confirmations, tracking updates, and receipts will never reach the customer.</p>
    <ul>
      <li><strong>Critical</strong> — missing email, invalid format, or a known disposable/temporary domain (Mailinator, YOPmail, 10MinuteMail, etc.).</li>
      <li><strong>Warning</strong> — placeholder-looking addresses (test@, noemail@), very short local parts, or suspicious repeated characters.</li>
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
    <?php
$partialStartName = 'email_start'; $partialStartVal = $emailStart;
$partialEndName   = 'email_end';   $partialEndVal   = $emailEnd;
require __DIR__ . '/partials/_date-range.php';
?>
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
    <div class="table-wrap">
      <div class="empty">
        <div class="icon">✅</div>
        <h3>All emails look valid</h3>
        <p>No email issues found across <?= $emailResult['scanned'] ?> orders.</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-header">
        <h2>Email Issues</h2>
        <div class="flex items-center gap-2">
          <span><?= count($emailResult['rows']) ?> order<?= count($emailResult['rows']) !== 1 ? 's' : '' ?></span>
          <button class="btn btn-sm btn-ghost" data-csv-btn="#tbl-emailcheck"
                  data-csv-filename="email-issues-<?= esc($emailResult['start']) ?>.csv">Export CSV</button>
        </div>
      </div>
      <table id="tbl-emailcheck">
        <thead>
          <tr>
            <th>Order</th>
            <th>Date</th>
            <th>Email</th>
            <th>Issues</th>
            <th>Severity</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($emailResult['rows'] as $row):
            $adminUrl = $row['shopify_id'] ? $shopifyAdminBase . '/' . esc($row['shopify_id']) : null;
          ?>
          <tr>
            <td class="order-num">
              <?php if ($adminUrl): ?>
                <a href="<?= $adminUrl ?>" target="_blank" rel="noopener"><?= esc($row['order_number']) ?></a>
              <?php else: ?>
                <?= esc($row['order_number']) ?>
              <?php endif; ?>
              <button class="copy-btn" data-copy="<?= esc(ltrim($row['order_number'], '#')) ?>" title="Copy">⧉</button>
            </td>
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
            <td class="td-actions">
              <?php if ($adminUrl): ?>
                <a class="ignore-btn" href="<?= $adminUrl ?>" target="_blank" rel="noopener">Edit in Shopify</a>
              <?php endif; ?>
              <a class="ignore-btn" href="?page=spotcheck&prefill=<?= urlencode(ltrim($row['order_number'], '#')) ?>">Spot-check</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
