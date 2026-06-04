<?php
declare(strict_types=1);
namespace Layerok\Restapi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OFFLINE\Mall\Models\User;

/**
 *
 */
class RegistrationWithPhoneRequest extends FormRequest
{
    /**
     * @return string[]
     */
    public function rules(): array
    {
        $minPasswordLength = User::getMinPasswordLength();
        return [
            'phone' => 'required|string|unique:users,username',
            'password' => "required|between:$minPasswordLength,255|confirmed",
            'password_confirmation' => "required_with:password|between:$minPasswordLength,255",
            'agree' => 'accepted'
        ];
    }

    public function messages()
    {
        return [
            'phone.unique' => \Lang::get('layerok.restapi::validation.unique', [
                'attribute' => 'Phone'
            ]),
            'agree.accepted' => \Lang::get('layerok.restapi::validation.checkbox_required')
        ];
    }
    public function validated($key = null, $default = null): array
    {
        $data = parent::validated();

        $data['username'] = $data['phone'];
        // unset($data['phone']);

        return $data;
    }
}
