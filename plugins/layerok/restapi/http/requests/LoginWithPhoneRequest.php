<?php
declare(strict_types=1);
namespace Layerok\Restapi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 *
 */
class LoginWithPhoneRequest extends FormRequest
{
    /**
     * @return string[]
     */
    public function rules(): array
    {
        return [
            'phone' => 'required',
            'password' => 'required',
        ];
    }

    public function messages()
    {
       return parent::messages();
    }
}
