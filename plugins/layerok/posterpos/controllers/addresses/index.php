<div id="toggle-address-container">
    <?= $this->makePartial('toggle_button') ?>
</div>


<?php if (\Layerok\PosterPos\Models\AddressSettings::get('enable_address_system')): ?>
    <div>
        <?= $mapPartial ?>
    </div>
    <div>
        <?= $this->listRender() ?>
    </div>
<?php else: ?>
    <p class="text-danger"><strong>Система адресов выключена</strong></p>
<?php endif; ?>