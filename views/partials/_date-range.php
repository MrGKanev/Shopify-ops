<?php
/**
 * Partial: date-range row.
 * Required: $partialStartName, $partialStartVal, $partialEndName, $partialEndVal
 * Optional: $partialSubmitLabel (default 'Scan')
 */
?>
<div class="date-row">
  <div class="field">
    <label>From</label>
    <input type="date" name="<?= esc($partialStartName) ?>" value="<?= esc($partialStartVal) ?>" max="<?= date('Y-m-d') ?>">
  </div>
  <div class="field">
    <label>To</label>
    <input type="date" name="<?= esc($partialEndName) ?>" value="<?= esc($partialEndVal) ?>" max="<?= date('Y-m-d') ?>">
  </div>
  <button class="btn btn-submit-end" type="submit"><?= esc($partialSubmitLabel ?? 'Scan') ?></button>
</div>
