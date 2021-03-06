<?php

namespace GuoJiangClub\EC\Open\Server\Http\Requests;

use App\Http\Requests\FormRequest;

class CustomerServiceRequest extends FormRequest
{
    public function rules()
    {
        switch ($this->method()) {
            // CREATE
            case 'POST':
                return [
                    'message' => 'required|string',
                    'mobile' => 'required|regex:/^1[3456789]\d{9}$/',
                    'code' => 'required|string'
                ];
            // UPDATE
            case 'PUT':
            case 'PATCH':
                return [
                    // UPDATE ROLES
                ];
            case 'GET':
            case 'DELETE':
            default:
                return [];
        }
    }

    public function messages()
    {
        return [
            // Validation messages
        ];
    }
}