<?php

namespace Layerok\PosterPos\Models;

use Layerok\Telegram\Models\Bot;
use Layerok\Telegram\Models\Chat;
use Model;

class Settings extends Model
{
    public $implement = [\System\Behaviors\SettingsModel::class];

    // A unique code
    public $settingsCode = 'layerok_posterpos_settings';

    // Reference to field configuration
    public $settingsFields = 'fields.yaml';

    /**
     * Categories whose presence in a cart adds the spot's extra wait time.
     */
    public static function extraWaitCategories(): array
    {
        return array_values(array_map('intval', (array) self::get('extra_wait_categories', [])));
    }
}
