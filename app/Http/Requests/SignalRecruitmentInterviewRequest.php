<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class SignalRecruitmentInterviewRequest extends JoinRecruitmentInterviewRequest
{
    public function rules(): array
    {
        $type = $this->input('type');
        $description = in_array($type, ['offer', 'answer'], true);
        $ice = $type === 'ice';

        return [
            'type' => ['required', 'string', Rule::in(['ready', 'offer', 'answer', 'ice', 'leave'])],
            'target_key' => [Rule::requiredIf($description || $ice), 'nullable', 'string', 'max:100', 'regex:/\A(?:user|applicant):[1-9]\d*\z/'],
            'payload' => [Rule::requiredIf($description || $ice), 'sometimes', 'array:'.($description ? 'sdp' : ($ice ? 'candidate,sdpMid,sdpMLineIndex,usernameFragment' : '')), Rule::when(! $description && ! $ice, 'max:0')],
            'payload.sdp' => [Rule::excludeIf(! $description), 'required', 'string', 'max:8192'],
            'payload.candidate' => [Rule::excludeIf(! $ice), 'required', 'string', 'max:4096'],
            'payload.sdpMid' => [Rule::excludeIf(! $ice), 'nullable', 'string', 'max:100'],
            'payload.sdpMLineIndex' => [Rule::excludeIf(! $ice), 'nullable', 'integer', 'between:0,100'],
            'payload.usernameFragment' => [Rule::excludeIf(! $ice), 'nullable', 'string', 'max:256'],
        ];
    }
}
