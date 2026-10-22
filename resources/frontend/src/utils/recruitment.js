export function examPayload(draft) {
  return {
    title: draft.title.trim(),
    instructions: draft.instructions?.trim() || null,
    passing_percentage: Number(draft.passing_percentage),
    is_active: true,
    questions: draft.questions.map((question) => ({
      type: question.type,
      prompt: question.prompt.trim(),
      points: Number(question.points),
      ...(question.type === 'multiple_choice'
        ? {
            options: question.options.map((option) => option.trim()),
            correct_option: Number(question.correct_option),
          }
        : {}),
    })),
  }
}

export function examAnswers(questions, draft) {
  return questions.map((question) => ({
    question_id: question.id,
    answer: String(draft[question.id] ?? '').trim(),
  }))
}

export function writtenGrades(questions, draft) {
  return questions
    .filter((question) => question.type === 'written')
    .map((question) => ({
      question_id: question.id,
      score: Number(draft[question.id]?.score),
      feedback: draft[question.id]?.feedback?.trim() || null,
    }))
}

export function finalResult(attempt) {
  return attempt?.status === 'graded' &&
    typeof attempt.score === 'number' &&
    typeof attempt.percentage === 'number'
    ? `${attempt.score} / ${attempt.max_score} · ${attempt.percentage}%`
    : null
}

export function applicantRedirect(value) {
  if (
    typeof value !== 'string' ||
    !/^\/applicant\/(?:applications|settings|exams\/\d+|interviews\/\d+)(?:[/?#]|$)/.test(value)
  )
    return '/applicant/applications'
  try {
    const decoded = decodeURIComponent(value)
    if (/[\\\u0000-\u001f\u007f]/.test(decoded) || decoded.includes('..'))
      return '/applicant/applications'
    return value
  } catch {
    return '/applicant/applications'
  }
}

export function jobOpeningPayload(draft) {
  return {
    title: draft.title.trim(),
    description: draft.description.trim(),
    requirements: draft.requirements?.trim() || null,
    location: draft.location?.trim() || null,
    employment_type: draft.employment_type?.trim() || null,
    monthly_salary: typeof draft.monthly_salary === 'string' ? draft.monthly_salary.trim() : '',
    vacancies: Number(draft.vacancies),
    status: draft.status,
    closes_on: draft.closes_on || null,
  }
}

export function hasMonthlySalary(value) {
  return (
    typeof value === 'string' &&
    /^\d{1,13}(?:\.\d{1,2})?$/.test(value) &&
    !/^0+(?:\.0{1,2})?$/.test(value)
  )
}

export function formatMonthlySalary(value) {
  if (!hasMonthlySalary(value)) return 'Salary not recorded'
  const [whole, fraction = ''] = value.split('.')
  const grouped = whole.replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return `PHP ${grouped}.${fraction.padEnd(2, '0')} / month`
}

export function interviewSchedule(draft) {
  const scheduledAt = new Date(draft.scheduled_at)
  if (Number.isNaN(scheduledAt.getTime()))
    throw new Error('Choose a valid interview date and time.')
  return {
    round: draft.round,
    scheduled_at: scheduledAt.toISOString().replace(/\.\d{3}Z$/, 'Z'),
    duration_minutes: Number(draft.duration_minutes),
    interviewer_ids: draft.interviewer_ids.map(Number),
    private_notes: draft.private_notes?.trim() || null,
  }
}

export function interviewTime(value) {
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? 'Schedule unavailable'
    : date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
