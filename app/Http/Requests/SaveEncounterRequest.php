<?php

namespace App\Http\Requests;

use App\Support\EncounterPayload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveEncounterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return EncounterPayload::rules();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach (EncounterPayload::problems($validator->validated(), $this->user()) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }
}
