<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GpsEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
         return [
            'master_address_id' => ['required','integer','exists:master_addresses,id'],
            'delivery_lat'      => ['required','numeric','between:-90,90'],
            'delivery_lng'      => ['required','numeric','between:-180,180'],
        ];
    }

     /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function messages()
    {
        return [
            'master_address_id.required' => 'Master address ID is required.',
            'delivery_lat.required'      => 'Latitude is required.',
            'delivery_lng.required'      => 'Longitude is required.',
        ];
    }

}