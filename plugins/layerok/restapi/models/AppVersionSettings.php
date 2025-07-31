<?php

namespace Layerok\RestApi\Models;

use Model;
use Validator;
use ValidationException;

class AppVersionSettings extends Model
{
    public $implement = [\System\Behaviors\SettingsModel::class];
    public $settingsCode = 'layerok_restapi_app_version_settings';
    public $settingsFields = 'plugins/layerok/restapi/models/settings/app_version_fields.yaml';
    public $rules = [
        'android_version' => 'required|string',
        'ios_version' => 'required|string',
    ];
    public function beforeSave()
    {
        $validator = Validator::make($this->value, $this->rules);
        $validator->validate();

        if (
            isset($this->value['android_version']) &&
            version_compare($this->value['android_version'], '0.0.1', '<')
        ) {
            throw new ValidationException([
                'android_version' => 'Minimum required version must be at least 0.0.1',
            ]);
        }
        if (
            isset($this->value['ios_version']) &&
            version_compare($this->value['ios_version'], '0.0.1', '<')
        ) {
            throw new ValidationException([
                'ios_version' => 'Minimum required version must be at least 0.0.1',
            ]);
        }
    }
}
