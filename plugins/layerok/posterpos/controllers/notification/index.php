<div class="container-fluid">

    <div class="card mb-3">
        <div class="card-header">
            <h3>Оправить уведомление</h3>
        </div>

        <div class="card-body">

            <form
                data-request="onSendNotification"
                data-request-confirm="Оправить уведомление всем пользователям?"
            >

                <div class="form-group">
                    <label>Заголовок</label>
                    <input
                        type="text"
                        name="title"
                        class="form-control"
                </div>

                <div class="form-group">
                    <label>Сообщение</label>
                    <textarea
                        name="body"
                        class="form-control"
                        rows="3"></textarea>
                </div>

                <button
                    type="submit"
                    class="btn btn-primary">
                    Отпраить на все устройства
                </button>

            </form>

        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Устройства</h3>
        </div>

        <div class="card-body">
            <table class="table">
                <thead>
                <tr>
                    <th>Тип</th>
                    <th>Кол-во</th>
                </tr>
                </thead>

                <tbody>
                <?php foreach ($platformCounts as $row): ?>
                    <tr>
                        <td><?= e($row->platform ?: 'unknown') ?></td>
                        <td><?= $row->total ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>

</div>