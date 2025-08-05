<?php

namespace Layerok\RestApi\Models;

use Model;
use Validator;
use ValidationException;

class ContactsSettings extends Model
{
    public $implement = [\System\Behaviors\SettingsModel::class];
    public $settingsCode = 'layerok_restapi_contacts_settings';
    public $settingsFields = 'plugins/layerok/restapi/models/settings/contacts_fields.yaml';

}
