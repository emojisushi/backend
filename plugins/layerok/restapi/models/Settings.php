<?php

namespace Layerok\RestApi\Models;

use Model;
use Validator;
class Settings extends Model
{
    public $implement = [\System\Behaviors\SettingsModel::class];

    // A unique code
    public $settingsCode = 'layerok_restapi_settings';


    // Reference to field configuration
    public $settingsFields = 'fields.yaml';
    public $rules = [
        'bonus_enabled' => 'required|boolean',
        'bonus_rate' => 'required|integer|min:0|max:100',
        'max_bonus' => 'required|integer|min:0|max:100',
        'get_bonus_from_used_bonus' => 'required|boolean',
    ];
    public function beforeSave()
    {
        $validator = Validator::make($this->value, $this->rules);
        $validator->validate();
    }
}
