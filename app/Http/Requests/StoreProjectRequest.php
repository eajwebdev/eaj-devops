<?php

namespace App\Http\Requests;

use App\Enums\ProjectConnectionType;
use App\Models\Project;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Project::class, app(CurrentOrganization::class)->get()]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'key' => strtoupper(trim((string) $this->input('key'))),
            'local_path' => is_string($this->input('local_path')) ? rtrim(trim($this->input('local_path')), '\\/') : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->get()->getKey();

        return [
            'name' => ['required', 'string', 'max:120'],
            'key' => [
                'required', 'string', 'regex:/^[A-Z][A-Z0-9]{1,9}$/',
                Rule::unique('projects', 'key')->where('organization_id', $organizationId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            // A runner from THIS organization that has not been revoked.
            'local_runner_id' => [
                'nullable', 'integer',
                Rule::exists('local_runners', 'id')->where('organization_id', $organizationId)->whereNull('revoked_at'),
            ],
            'connection_type' => [
                'required',
                Rule::in(collect(ProjectConnectionType::cases())->filter->isAvailable()->map->value->all()),
            ],
            // An absolute Windows (C:\...) or POSIX (/...) path. The server never reads it;
            // only the Local Runner on that machine touches the folder (Plan §89.2).
            'local_path' => [
                'required_if:connection_type,local', 'nullable', 'string', 'max:1024',
                'regex:/^(?:[A-Za-z]:[\\\\\/]|\/)[^\x00<>"|?*]*$/',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $path = (string) $this->input('local_path');

                if ($path !== '' && preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $path)) {
                    $validator->errors()->add('local_path', 'Use a full path without ".." segments.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex' => 'Use 2–10 letters or digits, starting with a letter (e.g. BOOK).',
            'key.unique' => 'Another project in this organization already uses this key.',
            'local_path.regex' => 'Enter a full folder path, e.g. C:\\laragon\\www\\booking-saas.',
            'local_path.required_if' => 'Enter the folder path of the local project.',
            'connection_type.in' => 'This connection type is not available yet.',
            'local_runner_id.exists' => 'Choose an active runner from this organization.',
        ];
    }
}
