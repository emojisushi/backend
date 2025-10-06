<?php

namespace Layerok\PosterPos\Models;

use Model;

class AddressSettings extends Model
{
    public $implement = [\System\Behaviors\SettingsModel::class];

    // A unique code
    public $settingsCode = 'layerok_address_settings';

    // Reference to field configuration
    public $settingsFields = 'address/settings.yaml';


}
