<?php

namespace Layerok\RestApi\Models;

use Model;
use OFFLINE\Mall\Models\Category;
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
        'bonus_enabled_web' => 'required|boolean',
        'max_bonus' => 'required|integer|min:0|max:100',
    ];

    public function beforeSave()
    {
        $validator = Validator::make($this->value, $this->rules);
        $validator->validate();
    }

    /**
     * Categories whose products do not count towards the bonus spending limit.
     */
    public function getBonusExcludedCategoriesOptions(): array
    {
        return Category::orderBy('name')->get()->lists('name', 'id');
    }
}
