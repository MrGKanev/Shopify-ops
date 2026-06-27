<?php
/**
 * Note templates partial.
 *
 * Expected variables (set by the including file):
 *   $noteTemplates  array  — list of ['label' => ..., 'template' => ...] entries
 *   $noteOrderNum   string — order number used for {{order_number}} substitution
 *   $noteShopifyId  string — Shopify numeric order ID sent to save_order_note
 *   $noteEmail      string — customer email used for {{email}} substitution
 */
if (empty($noteTemplates) || empty($noteShopifyId)) return;
?>
<div class="note-templates">
  <div class="note-templates-label">Add note to Shopify order</div>
  <?php
    $saveOk  = ($_GET['note_ok']    ?? '') === $noteShopifyId;
    $saveErr = ($_GET['note_error'] ?? '') !== '' && ($_GET['note_order'] ?? '') === $noteShopifyId
               ? urldecode($_GET['note_error']) : '';
  ?>
  <?php if ($saveOk): ?>
    <div class="flash flash-ok" style="display:block;margin-bottom:.5rem">Note saved.</div>
  <?php elseif ($saveErr): ?>
    <div class="flash flash-err" style="display:block;margin-bottom:.5rem">Error: <?= esc($saveErr) ?></div>
  <?php endif; ?>
  <form method="post" data-order="<?= esc($noteOrderNum) ?>" data-email="<?= esc($noteEmail) ?>">
    <input type="hidden" name="action" value="save_order_note">
    <input type="hidden" name="shopify_id" value="<?= esc($noteShopifyId) ?>">
    <input type="hidden" name="redirect_page" value="spotcheck">
    <div class="note-templates-row">
      <select class="js-note-template-select note-template-select" name="_template_label">
        <option value="">Custom note...</option>
        <?php foreach ($noteTemplates as $tpl): ?>
          <option value="<?= esc($tpl['template'] ?? '') ?>"><?= esc($tpl['label'] ?? '') ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <textarea class="js-note-textarea note-textarea" name="note" rows="3"
              placeholder="Order note…"></textarea>
    <button class="btn btn-sm" type="submit">Save Note</button>
  </form>
</div>
