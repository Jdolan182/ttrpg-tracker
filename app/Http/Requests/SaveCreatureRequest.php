<?php

namespace App\Http\Requests;

use App\Models\Creature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCreatureRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(Creature::KINDS)],
            'name' => ['required', 'string', 'max:100'],
            'summary' => ['nullable', 'string', 'max:255'],
            'rating' => ['nullable', 'string', 'max:50'],
            'hp' => ['required', 'integer', 'min:1', 'max:100000'],
            'ac' => ['required', 'integer', 'min:0', 'max:1000'],
            'speed' => ['nullable', 'string', 'max:100'],
            'stats' => ['present', 'array', 'max:30'],
            'stats.*.label' => ['required', 'string', 'max:20', 'distinct:ignore_case'],
            'stats.*.value' => ['required', 'integer', 'between:-1000,1000'],
            'traits' => ['present', 'array', 'max:50'],
            'traits.*.name' => ['required', 'string', 'max:100'],
            'traits.*.description' => ['required', 'string', 'max:2000'],
            'actions' => ['present', 'array', 'max:50'],
            'actions.*.name' => ['required', 'string', 'max:100'],
            'actions.*.description' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stats.*.label.distinct' => 'Each stat needs a different name.',
        ];
    }

    /**
     * Model attributes with only the known keys of each list entry.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $data = $this->validated();

        $entries = fn (array $list) => array_map(
            fn (array $entry) => ['name' => $entry['name'], 'description' => $entry['description']],
            $list,
        );

        return [
            'kind' => $data['kind'],
            'name' => $data['name'],
            'summary' => $data['summary'] ?? '',
            'rating' => $data['rating'] ?? '',
            'hp' => (int) $data['hp'],
            'ac' => (int) $data['ac'],
            'speed' => $data['speed'] ?? '',
            // Form inputs send numbers as strings; store real integers in the JSON.
            'stats' => array_map(fn (array $stat) => ['label' => $stat['label'], 'value' => (int) $stat['value']], $data['stats']),
            'traits' => $entries($data['traits']),
            'actions' => $entries($data['actions']),
        ];
    }
}
