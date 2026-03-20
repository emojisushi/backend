<?php if (session('success')): ?>
    <div style="color: green; margin-bottom: 15px;">
        <?= session('success') ?>
    </div>
<?php endif; ?>
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
            <div style="margin-bottom: 15px; display: flex; align-items:center;gap:8px">
                <label>
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
            </div>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary">Зберегти</button>
    </form>
<?php else: ?>
    <p>Спотів немає</p>
<?php endif; ?>