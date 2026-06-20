<?php
/**
 * Partial: date-range row.
 * Required: $partialStartName, $partialStartVal, $partialEndName, $partialEndVal
 * Optional: $partialFromLabel   (default 'From' - may contain HTML)
 *           $partialToLabel     (default 'To'   - may contain HTML)
 *           $partialExtraHtml   (raw HTML inserted before the submit button)
 *           $partialSubmitLabel (default 'Scan')
 */
?>
<?= datePresets() ?>
<div class="date-row">
  <div class="field">
    <label><?= $partialFromLabel ?? 'From' ?></label>
    <input type="date" name="<?= esc($partialStartName) ?>" value="<?= esc($partialStartVal) ?>" max="<?= date('Y-m-d') ?>">
  </div>
  <div class="field">
    <label><?= $partialToLabel ?? 'To' ?></label>
    <input type="date" name="<?= esc($partialEndName) ?>" value="<?= esc($partialEndVal) ?>" max="<?= date('Y-m-d') ?>">
  </div>
  <?= $partialExtraHtml ?? '' ?>
  <button class="btn btn-submit-end" type="submit"><?= esc($partialSubmitLabel ?? 'Scan') ?></button>
</div>
