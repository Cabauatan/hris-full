<?php

namespace App\Http\Requests;

use App\Models\RecruitmentExam;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRecruitmentExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->can('create', RecruitmentExam::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'passing_percentage' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
            'questions' => ['required', 'array', 'list', 'between:1,50'],
            'questions.*' => ['required', 'array:type,prompt,points,options,correct_option'],
            'questions.*.type' => ['required', Rule::in(['multiple_choice', 'written'])],
            'questions.*.prompt' => ['required', 'string', 'max:5000'],
            'questions.*.points' => ['required', 'integer', 'between:1,100'],
            'questions.*.options' => ['sometimes', 'array', 'list', 'between:2,8'],
            'questions.*.options.*' => ['required', 'string', 'max:1000'],
            'questions.*.correct_option' => ['sometimes', 'integer', 'between:0,7'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->input('questions', []) as $index => $question) {
                if ($question['type'] === 'written') {
                    foreach (['options', 'correct_option'] as $field) {
                        if (array_key_exists($field, $question)) {
                            $validator->errors()->add("questions.{$index}.{$field}", 'Written questions cannot have options or a correct option.');
                        }
                    }

                    continue;
                }

                if (! isset($question['options'])) {
                    $validator->errors()->add("questions.{$index}.options", 'Multiple-choice questions require options.');
                }

                if (! isset($question['correct_option']) || ! array_key_exists($question['correct_option'], $question['options'] ?? [])) {
                    $validator->errors()->add("questions.{$index}.correct_option", 'Select a valid correct option for this question.');
                }
            }
        }];
    }
}
