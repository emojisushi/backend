<?php
declare(strict_types=1);
namespace Layerok\Restapi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 *
 */
class LoginWithSMSRequest extends FormRequest
{
    /**
     * @return string[]
     */
    public function rules(): array
    {
        return [
            'phone' => 'required',
            'code' => 'required',
        ];
    }

    public function messages()
    {
       return parent::messages();
    }
}
