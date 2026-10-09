<?php function formatMinutes($minutes, $text)
{

    $hours = floor($minutes / 60);
    $mins = $minutes % 60;

    if ($minutes == 0) return '0 хв' . $text;

    $result = '';

    if ($hours > 0) {
        $result .= $hours . ' год';
    }

    if ($mins > 0) {
        $result .= ($hours ? ' ' : '') . $mins . ' хв';
    }
    $result .= $text;
    return $result;
} ?>
<h2>Час очікування:</h2>

<?php if (count($spots) > 0): ?>
    <form method="POST" action="/backend/layerok/posterpos/waittime/save">
        <?= csrf_field() ?>
        <?php foreach ($spots as $spot): ?>
            <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                    <label style="width: 180px; min-width: 180px; font-weight: 500;">
                    <?= $spot->name ?>
                </label>

                <select class="form-control select2" name="spots[<?= $spot->id ?>][wait_minutes_spot]">
                    <?php for ($i = 0; $i <= 300; $i += 10): ?>

                        <option value="<?= $i ?>" <?= $spot->wait_minutes_spot == $i ? 'selected' : '' ?>>
                            <?= formatMinutes($i, " (самовивіз)") ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <select class="form-control select2" name="spots[<?= $spot->id ?>][wait_minutes_delivery]">
                    <?php for ($i = 0; $i <= 300; $i += 10): ?>
                        <option value="<?= $i ?>" <?= $spot->wait_minutes_delivery == $i ? 'selected' : '' ?>>
                            <?= formatMinutes($i, " (доставка)") ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <select class="form-control select2" name="spots[<?= $spot->id ?>][default_wait_minutes_spot]">
                    <?php for ($i = 0; $i <= 300; $i += 10): ?>
                        <option value="<?= $i ?>" <?= $spot->default_wait_minutes_spot == $i ? 'selected' : '' ?>>
                            <?= formatMinutes($i, " (самовивіз после сброса)") ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <select class="form-control select2" name="spots[<?= $spot->id ?>][default_wait_minutes_delivery]">
                    <?php for ($i = 0; $i <= 300; $i += 10): ?>
                        <option value="<?= $i ?>" <?= $spot->default_wait_minutes_delivery == $i ? 'selected' : '' ?>>
                            <?= formatMinutes($i, " (доставка после сброса)") ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <input type="hidden" name="spots[<?= $spot->id ?>][extra_wait_enabled]" value="0">
                <label style="display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;
                              margin: 0; font-weight: 400; cursor: pointer;">
                    <input type="checkbox" name="spots[<?= $spot->id ?>][extra_wait_enabled]" value="1"
                           style="width: 15px; height: 15px; margin: 0; flex: 0 0 auto;"
                           <?= $spot->extra_wait_enabled ? 'checked' : '' ?>>
                    <span>Додатковий час</span>
                </label>

                <select class="form-control select2" style="max-width: 170px;"
                        name="spots[<?= $spot->id ?>][extra_wait_minutes]">
                    <?php for ($i = 0; $i <= 120; $i += 5): ?>
                        <option value="<?= $i ?>" <?= (int) $spot->extra_wait_minutes === $i ? 'selected' : '' ?>>
                            +<?= $i ?> хв
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
        <?php endforeach; ?>

        <div class="form-group" style="margin: 28px 0 18px; max-width: 460px;">
            <label class="form-label">Категорії, що додають час</label>
            <p class="help-block before-field" style="margin-bottom: 8px;">
                Якщо в замовленні є товар із цих категорій, до часу очікування додається
                значення, вибране навпроти закладу.
            </p>
            <div style="max-height: 220px; overflow: auto; border: 1px solid #d5d5d5;
                        border-radius: 4px; padding: 10px 12px; background: #fff;">
                <?php foreach ($categories as $category): ?>
                    <div style="padding: 3px 0;">
                        <label for="extra-wait-category-<?= $category->id ?>"
                               style="display: flex; align-items: center; gap: 8px; margin: 0; font-weight: 400; cursor: pointer;">
                            <input type="checkbox"
                                   name="extra_wait_categories[]"
                                   value="<?= $category->id ?>"
                                   id="extra-wait-category-<?= $category->id ?>"
                                   style="width: 15px; height: 15px; margin: 0; flex: 0 0 auto;"
                                   <?= in_array((int) $category->id, $extraCategories, true) ? 'checked' : '' ?>>
                            <span><?= e($category->name) ?></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Зберегти</button>
    </form>
<?php else: ?>
    <p>Спотів немає</p>
<?php endif; ?>