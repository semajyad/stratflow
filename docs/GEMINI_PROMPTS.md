# Gemini Prompts Reference

StratFlow uses seven prompt classes in `src/Services/Prompts/`. All prompts are PHP string constants — no templating library. They are passed to `GeminiService::generate()` alongside the user-supplied content.

The model is configured via the `GEMINI_MODEL` environment variable (defaults to `gemini-2.0-flash`).

---

## 1. Summary Prompt

**File:** `src/Services/Prompts/SummaryPrompt.php`
**Constant:** `SummaryPrompt::PROMPT`
**Used by:** `UploadController@generateSummary`

### Purpose

Converts raw uploaded document text (meeting notes, strategy documents, etc.) into a concise 3-paragraph strategic brief. The brief is the input to the diagram generation step.

### Input format

The prompt constant is sent as the system/instruction preamble. The user content appended after it is the raw extracted text from the uploaded document.

### Expected output

Three paragraphs (plain text, under 500 words total) covering:

1. Core business objectives and goals
2. Key challenges, constraints, and stakeholders
3. Recommended strategic priorities

### Prompt text

```
You are an Enterprise Business Strategist. Summarise these meeting notes/documents
into a concise 3-paragraph brief to prepare for strategic mapping. Focus on:
1. The core business objectives and goals
2. Key challenges, constraints, and stakeholders
3. Recommended strategic priorities

Be specific and reference concrete details from the source material. Keep the total
summary under 500 words.
```

---

## 2. Diagram Prompt

**File:** `src/Services/Prompts/DiagramPrompt.php`
**Constant:** `DiagramPrompt::PROMPT`
**Used by:** `DiagramController@generate`

### Purpose

Converts the 3-paragraph strategic brief into a valid Mermaid.js flowchart. The generated Mermaid source is stored in `strategy_diagrams.mermaid_code` and rendered in the browser using the Mermaid.js library.

### Input format

The prompt constant is the preamble. The user content appended is the AI-generated summary from step 1 (or a manually edited summary).

### Expected output

Raw Mermaid.js source code only — no markdown fences, no explanation. Example:

```
graph TD
    A[Define Strategic Vision] --> B[Identify Key Initiatives]
    B --> C[Stakeholder Alignment]
    B --> D[Resource Planning]
    C --> E[Execution Roadmap]
    D --> E
```

Requirements enforced by the prompt:
- `graph TD` (top-down direction)
- 5–15 nodes
- Unique short node IDs (`A`, `B`, `C` or `STR1`, `STR2`, etc.)
- Square-bracket labels: `A[Label Text]`
- Dependency arrows: `-->`

### Prompt text

```
You are an Expert System Architect. Convert the following strategic brief into a
valid Mermaid.js flowchart. Requirements:
- Use "graph TD" direction (top-down)
- Each node should represent a distinct strategic phase or initiative
- Use descriptive node labels in square brackets, e.g., A[Label Text]
- Show dependencies as arrows (-->)
- Use unique single-letter or short IDs for nodes (A, B, C... or STR1, STR2...)
- Output ONLY the Mermaid.js code, no explanation, no markdown fences
- Minimum 5 nodes, maximum 15 nodes
```

---

## 3. Work Item Prompt

**File:** `src/Services/Prompts/WorkItemPrompt.php`
**Constant:** `WorkItemPrompt::PROMPT`
**Used by:** `WorkItemController@generate`

### Purpose

Translates the Mermaid strategy diagram and any OKR data attached to nodes into a prioritised backlog of High-Level Work Items (High Level Work Items). Each item represents approximately one month of effort for a standard Scrum team.

### Input format

The prompt constant is the preamble. The content appended is a combination of:
- The raw Mermaid diagram source
- A structured list of node OKR data (node key, label, OKR title, OKR description)

### Expected output

A strict JSON array — no markdown fences, no prose. Each element must have exactly these keys:

| Key | Type | Notes |
|-----|------|-------|
| `priority_number` | integer | Starting at 1, ordered most critical first |
| `title` | string | Concise work item title |
| `description` | string | 2–3 sentence scope description |
| `strategic_context` | string | Which diagram nodes this maps to |
| `okr_title` | string | Relevant OKR title, or empty string |
| `okr_description` | string | Relevant OKR description, or empty string |
| `estimated_sprints` | integer | Default 2 (approximately 1 month) |

### Prompt text

```
You are the ThreePoints StratFlow Architect. Translate the following Mermaid strategy
diagram and OKR data into a prioritised backlog of High-Level Work Items (High Level Work Items).

Task Constraints:
1. Each High Level Work Item must represent approximately 1 month (4 weeks) of effort for a standard
   Scrum team (5-9 people), which equals roughly 2 sprints.
2. Every item must directly map back to a node or cluster of nodes in the diagram.
3. Respond strictly in JSON format -- a JSON array only, no markdown fences.
4. Order by priority (most critical first).

Return a JSON array where each element has these exact keys:
- "priority_number" (integer, starting at 1)
- "title" (string, concise work item title)
- "description" (string, 2-3 sentence scope description)
- "strategic_context" (string, which diagram nodes this maps to)
- "okr_title" (string, the relevant OKR if available, else empty string)
- "okr_description" (string, the relevant OKR description if available, else empty string)
- "estimated_sprints" (integer, default 2)
```

---

## 4. Sizing Prompt

**File:** `src/Services/Prompts/WorkItemPrompt.php`
**Constant:** `WorkItemPrompt::SIZING_PROMPT`
**Used by:** `WorkItemController@regenerateSizing`

### Purpose

Bulk re-estimates sprint counts for all existing High-Level Work Items in a project. Called when the user triggers "Regenerate Sizing" from the work items screen. Results are applied via `HLWorkItem::update()` for each returned item.

### Input format

The prompt constant is the preamble. The content appended is a plain-text list of all work items in the project (id, title, description).

### Expected output

A JSON array where each element has:

| Key | Type | Notes |
|-----|------|-------|
| `id` | integer | Work item ID |
| `estimated_sprints` | integer | Minimum 1, maximum 6 |

### Prompt text

```
You are an Agile estimation expert. For each work item below, estimate how many 2-week sprints it would take for a standard Scrum team (5-9 people) to complete.

Return a JSON array where each element has: "id" (integer), "estimated_sprints" (integer, minimum 1, maximum 6).

Work items:
```

---

## 5. Description Prompt

**File:** `src/Services/Prompts/WorkItemPrompt.php`
**Constant:** `WorkItemPrompt::DESCRIPTION_PROMPT`
**Used by:** `WorkItemController@generateDescription`

### Purpose

Generates a detailed 1-month scope description for a single existing work item. Used when the user clicks "Generate Description" on an individual item in the work items list.

### Template variables

The prompt contains three placeholders replaced via `str_replace()` before sending:

| Placeholder | Replaced with |
|-------------|--------------|
| `{title}` | The work item's current title |
| `{context}` | The work item's `strategic_context` field |
| `{summary}` | The project's AI document summary |

### Expected output

A structured plain-text description (maximum 300 words) covering:
1. Key deliverables
2. Technical considerations
3. Dependencies and risks
4. Definition of Done criteria

### Prompt text

```
You are a Technical Project Manager. Generate a detailed 1-month scope description
for the following high-level work item. The description should include:
1. Key deliverables
2. Technical considerations
3. Dependencies and risks
4. Definition of Done criteria

Keep it concise but actionable. Maximum 300 words.

Work Item Title: {title}
Strategic Context: {context}
Overall Strategy Summary: {summary}
```

---

## 6. Prioritisation Prompt

**File:** `src/Services/Prompts/PrioritisationPrompt.php`
**Constants:** `PrioritisationPrompt::RICE_PROMPT`, `PrioritisationPrompt::WSJF_PROMPT`
**Used by:** `PrioritisationController@aiBaseline`

### Purpose

Generates AI-suggested baseline scores for all work items in a project using either the RICE or WSJF framework. The controller calls the appropriate constant based on `projects.selected_framework`. Scores are returned as a JSON array and saved to the corresponding columns on `hl_work_items`.

### Input format

The prompt constant is the preamble. The content appended is a list of all work items in the project (id, title, description).

### Expected output (RICE)

A JSON array where each element has:

| Key | Type | Notes |
|-----|------|-------|
| `id` | integer | Work item ID |
| `reach` | integer | 1–10 |
| `impact` | integer | 1–10 |
| `confidence` | integer | 1–10 |
| `effort` | integer | 1–10 |

### Expected output (WSJF)

A JSON array where each element has:

| Key | Type | Notes |
|-----|------|-------|
| `id` | integer | Work item ID |
| `business_value` | integer | 1–10 |
| `time_criticality` | integer | 1–10 |
| `risk_reduction` | integer | 1–10 |
| `job_size` | integer | 1–10 |

### Prompt text (RICE)

```
You are an Agile Product Manager. For each high-level work item below, estimate RICE scores on a 1-10 scale:
- Reach: How many users/stakeholders will this impact? (1=few, 10=everyone)
- Impact: How significant is the impact per user? (1=minimal, 10=transformative)
- Confidence: How confident are you in the estimates? (1=guess, 10=certain)
- Effort: How much effort is required? (1=trivial, 10=enormous)

Return a JSON array where each element has: "id" (the work item ID), "reach", "impact", "confidence", "effort".
```

### Prompt text (WSJF)

```
You are an Agile Product Manager. For each high-level work item below, estimate WSJF scores on a 1-10 scale:
- Business Value: How much value does this deliver? (1=minimal, 10=critical)
- Time Criticality: How urgent is this? (1=can wait, 10=immediate)
- Risk Reduction: How much risk/opportunity does this address? (1=none, 10=major)
- Job Size: How large is the work? (1=tiny, 10=massive)

Return a JSON array where each element has: "id" (the work item ID), "business_value", "time_criticality", "risk_reduction", "job_size".
```

---

## 7. Risk Prompt

**File:** `src/Services/Prompts/RiskPrompt.php`
**Constants:** `RiskPrompt::GENERATE_PROMPT`, `RiskPrompt::MITIGATION_PROMPT`
**Used by:** `RiskController@generate` (GENERATE_PROMPT), `RiskController@generateMitigation` (MITIGATION_PROMPT)

### Purpose

`GENERATE_PROMPT` analyses all work items in a project and identifies 3–5 major risks, with likelihood and impact scores and links to relevant work items.

`MITIGATION_PROMPT` generates a specific mitigation strategy for a single existing risk, given full context about the risk and its linked work items.

### Input format (GENERATE_PROMPT)

The prompt constant is the preamble. The content appended is a list of all work items (title and description).

### Input format (MITIGATION_PROMPT)

The prompt constant contains template placeholders replaced via `str_replace()` before sending:

| Placeholder | Replaced with |
|-------------|--------------|
| `{title}` | Risk title |
| `{description}` | Risk description |
| `{likelihood}` | Likelihood score (1–5) |
| `{impact}` | Impact score (1–5) |
| `{linked_items}` | Comma-separated list of linked work item titles |

### Expected output (GENERATE_PROMPT)

A JSON array only, no markdown. Each element has:

| Key | Type | Notes |
|-----|------|-------|
| `title` | string | Concise risk title |
| `description` | string | 2–3 sentence risk description |
| `likelihood` | integer | 1 (rare) to 5 (almost certain) |
| `impact` | integer | 1 (negligible) to 5 (catastrophic) |
| `linked_items` | array of strings | Work item titles this risk relates to |

### Expected output (MITIGATION_PROMPT)

Plain text — a concise 2–3 sentence proactive mitigation strategy.

### Prompt text (GENERATE_PROMPT)

```
You are an Enterprise Risk Manager. Analyse the following high-level work items and identify 3-5 major project risks. For each risk:
- title: concise risk title
- description: 2-3 sentence description of the risk
- likelihood: integer 1-5 (1=rare, 5=almost certain)
- impact: integer 1-5 (1=negligible, 5=catastrophic)
- linked_items: array of work item titles this risk relates to

Return a JSON array only, no markdown.
```

### Prompt text (MITIGATION_PROMPT)

```
You are an Enterprise Risk Manager. Given the following risk and its linked work items, write a concise 2-3 sentence proactive mitigation strategy. Be specific and actionable.

Risk: {title}
Description: {description}
Likelihood: {likelihood}/5
Impact: {impact}/5
Linked Work Items: {linked_items}
```

---

## 8. User Story Prompt

**File:** `src/Services/Prompts/UserStoryPrompt.php`
**Constants:** `UserStoryPrompt::DECOMPOSE_PROMPT`, `UserStoryPrompt::SIZE_PROMPT`
**Used by:** `UserStoryController@generate` (DECOMPOSE_PROMPT), `UserStoryController@suggestSize` (SIZE_PROMPT)

### Purpose

`DECOMPOSE_PROMPT` breaks a High-Level Work Item down into 5–10 granular user stories, each representing approximately 3 days of work and following the "As a [role], I want [action], so that [value]" format.

`SIZE_PROMPT` estimates story points for a single user story using the modified Fibonacci scale.

### Input format (DECOMPOSE_PROMPT)

The prompt constant is the preamble. The content appended is the work item title and description.

### Input format (SIZE_PROMPT)

The prompt constant contains template placeholders replaced via `str_replace()`:

| Placeholder | Replaced with |
|-------------|--------------|
| `{title}` | User story title |
| `{description}` | User story description |

### Expected output (DECOMPOSE_PROMPT)

A JSON array where each element has:

| Key | Type | Notes |
|-----|------|-------|
| `title` | string | Full "As a..." user story statement |
| `description` | string | 2–3 sentence technical description |
| `size` | integer | Story points: 1, 2, 3, 5, 8, or 13 |

### Expected output (SIZE_PROMPT)

A JSON object only: `{"size": <number>, "reasoning": "<1 sentence explanation>"}`. Valid sizes: 1, 2, 3, 5, 8, 13, 20.

### Prompt text (DECOMPOSE_PROMPT)

```
You are an Experienced Agile Product Owner. Decompose the following high-level work item into 5-10 actionable user stories. Each story must:
- Follow the format: "As a [role], I want [action], so that [value]"
- Represent approximately 3 days of development work
- Be independently testable

Return a JSON array where each element has:
- "title": the user story in "As a..." format
- "description": 2-3 sentence technical description of what needs to be built
- "size": suggested story points (1, 2, 3, 5, 8, or 13)
```

### Prompt text (SIZE_PROMPT)

```
You are an Expert System Architect. Estimate the story point size for this user story based on complexity, unknowns, and effort. Use the modified Fibonacci scale: 1, 2, 3, 5, 8, 13, 20.

Return ONLY a JSON object: {"size": <number>, "reasoning": "<1 sentence explanation>"}

Story Title: {title}
Story Description: {description}
```

---

## 9. Sprint Prompt

**File:** `src/Services/Prompts/SprintPrompt.php`
**Constant:** `SprintPrompt::ALLOCATE_PROMPT`
**Used by:** `SprintController@aiAllocate`

### Purpose

Auto-allocates unassigned user stories across available sprints, respecting story priority, sprint capacity (total story points), and dependencies between stories. The result is a mapping of story IDs to sprint IDs that the controller applies by inserting rows into `sprint_stories`.

### Input format

The prompt constant contains template placeholders replaced via `str_replace()`:

| Placeholder | Replaced with |
|-------------|--------------|
| `{sprints}` | JSON list of sprints with `id`, `name`, and `team_capacity` |
| `{stories}` | JSON list of unallocated stories with `id`, `title`, `size`, `priority_number`, and `blocked_by` |

### Expected output

A JSON array where each element has:

| Key | Type | Notes |
|-----|------|-------|
| `story_id` | integer | User story ID |
| `sprint_id` | integer | Sprint ID to allocate the story to |

Stories that cannot fit within any sprint's remaining capacity should be omitted (left unallocated).

### Prompt text

```
You are an Agile Project Manager. Allocate the following user stories into sprints based on:
1. Story priority (lower priority_number = higher priority, should be in earlier sprints)
2. Sprint capacity (total story points should not exceed team_capacity)
3. Dependencies (blocked stories should come after their blockers)
4. Even distribution across sprints

Available sprints with their capacities:
{sprints}

Unallocated stories:
{stories}

Return a JSON array where each element has: "story_id" (integer), "sprint_id" (integer).
Only allocate stories that fit within sprint capacity. Leave stories unallocated if they don't fit.
```

---

## 10. Persona Prompt

**File:** `src/Services/Prompts/PersonaPrompt.php`
**Class:** `PersonaPrompt`
**Used by:** `SoundingBoardService` (called once per panel member per evaluation)

### Purpose

Builds a complete LLM evaluation prompt for a single AI persona. The prompt combines the persona's role identity, their perspective description, a criticism-level instruction, and the screen content to evaluate. Used by `SoundingBoardService` to generate per-persona assessments of StratFlow screen content (work items, risks, user stories, etc.).

### Evaluation levels

`PersonaPrompt::EVALUATION_LEVELS` defines three named criticism levels:

| Key | Persona instruction |
|-----|---------------------|
| `devils_advocate` | Challenge the content by pointing out flaws, counterarguments, missing evidence, and unintended consequences to create constructive doubt |
| `red_teaming` | Hunt for and expose flaws, loopholes, and weaknesses in an adversarial and thorough manner |
| `gordon_ramsay` | Surgical, pulls-no-punches critique — identify what's wrong and what needs to be completely redone with specific, actionable feedback |

### Dynamic prompt building

`PersonaPrompt::buildPrompt()` takes four parameters:

| Parameter | Description |
|-----------|-------------|
| `$roleTitle` | The persona's role (e.g. `CEO`, `Senior Developer`) |
| `$promptDescription` | Additional context about the persona's professional perspective |
| `$evaluationLevel` | One of the three level keys above; falls back to `devils_advocate` if invalid |
| `$screenContent` | The page content string to evaluate |

The returned prompt uses PHP heredoc syntax and inlines all four values. No placeholder tokens — everything is interpolated at call time.

### Expected output

Structured plain text with four sections, in this order:

1. **Overall Assessment** (2–3 sentences)
2. **Key Concerns** (bullet points)
3. **Recommendations** (bullet points)
4. **Risk Rating** (`Low`, `Medium`, `High`, or `Critical`)

### Prompt template

```
You are a {roleTitle}. {promptDescription}

{levelInstruction}

Evaluate the following content and provide your professional assessment. Be specific, reference concrete items from the content, and provide actionable recommendations.

Structure your response as:
1. **Overall Assessment** (2-3 sentences)
2. **Key Concerns** (bullet points)
3. **Recommendations** (bullet points)
4. **Risk Rating** (Low/Medium/High/Critical)

Content to evaluate:
---
{screenContent}
```

---

## 11. Drift Prompt

**File:** `src/Services/Prompts/DriftPrompt.php`
**Constant:** `DriftPrompt::ALIGNMENT_PROMPT`
**Used by:** `DriftDetectionService::checkAlignment()`

### Purpose

Assesses whether a newly added user story aligns with the project's original strategic OKRs. Called by `DriftDetectionService` when Gemini is available, as part of the alignment check within the Drift Engine. If the AI is unavailable or returns an error, the method returns null and detection continues without blocking.

### Input format

The prompt constant contains three `{placeholder}` tokens replaced via `str_replace()` before sending:

| Placeholder | Replaced with |
|-------------|--------------|
| `{okrs}` | Combined OKR text from the project's diagram nodes |
| `{story_title}` | Title of the newly added user story |
| `{story_description}` | Description of the newly added user story |

### Expected output

A JSON object only, no prose:

| Key | Type | Notes |
|-----|------|-------|
| `aligned` | boolean | `true` if the story serves the strategic goals |
| `confidence` | number | 0–100 — confidence in the alignment assessment |
| `explanation` | string | 1–2 sentences explaining the assessment |

### Prompt text

```
You are a Strategic Alignment Assessor. Given the original strategic OKRs and a newly added user story, assess whether this story aligns with the original strategic goals.

Return a JSON object with:
- "aligned": boolean (true if the story serves the strategic goals)
- "confidence": number 0-100 (how confident you are)
- "explanation": string (1-2 sentences explaining your assessment)

Original Strategic OKRs:
{okrs}

New User Story:
Title: {story_title}
Description: {story_description}
```

---

## 12. KR Scoring Prompt

**File:** `src/Services/Prompts/KrScoringPrompt.php`
**Constant:** `KrScoringPrompt::PROMPT`
**Used by:** `KrScoringService` (called once per merged PR × key result pair)

### Purpose

Scores how much a merged GitHub pull request contributes to a specific Key Result. Called by `KrScoringService` after `GitPrMatcherService` identifies candidate PR-to-story links. Results are stored in `key_result_contributions`.

### Input format

The prompt constant is the preamble. The content appended is a JSON object:

```json
{
  "kr_title": "...",
  "kr_description": "...",
  "kr_target": "...",
  "pr_title": "...",
  "pr_body": "..."
}
```

### Expected output

A JSON object only, no prose:

| Key | Type | Notes |
|-----|------|-------|
| `score` | integer | 0–10 contribution score |
| `rationale` | string | One sentence, max 120 characters |

### Prompt text

```
You are an engineering performance analyst. Given a Key Result (KR) and a merged
pull request, score how much the PR contributes to that KR.

Scoring guide (integer 0–10):
0  — No discernible connection
1–3 — Marginal or indirect contribution
4–6 — Moderate contribution, addresses part of the KR
7–9 — Strong contribution, directly advances the KR
10 — Complete or near-complete realisation of the KR

Rules:
1. Return ONLY a JSON object. No prose, no markdown.
2. Shape: {"score": <integer 0–10>, "rationale": "<one concise sentence max 120 chars>"}
3. Be conservative — if uncertain, score lower.

Input JSON:
```

---

## 13. Git PR Match Prompt

**File:** `src/Services/Prompts/GitPrMatchPrompt.php`
**Constant:** `GitPrMatchPrompt::PROMPT`
**Used by:** `GitPrMatcherService`

### Purpose

Given a merged GitHub pull request and a list of candidate StratFlow work items (stories or high-level items), identifies which items the PR most likely contributes to and returns a confidence score for each match. Results above 0.5 confidence are used to create `story_git_links` records.

### Input format

The prompt constant is the preamble. The content appended is a JSON object:

```json
{
  "pr_title": "...",
  "pr_body": "...",
  "branch": "...",
  "candidates": [
    {"id": 1, "type": "user_story", "title": "...", "description": "..."},
    ...
  ]
}
```

### Expected output

A JSON array of matched candidates (confidence > 0.5 only):

| Key | Type | Notes |
|-----|------|-------|
| `id` | integer | Candidate item ID |
| `type` | string | `user_story` or `hl_work_item` |
| `confidence` | float | 0.0–1.0 — omit candidates below 0.5 |

### Prompt text

```
You are a software-delivery analyst. Given a GitHub pull request and a list of candidate
work items (user stories or OKR work items), identify which items this PR most likely
contributes to.

Rules:
1. Only include candidates where you are genuinely confident the PR contributes to that item.
2. Confidence is a float from 0.0 to 1.0.
3. Only return candidates with confidence > 0.5. Omit everything else.
4. Respond ONLY with a JSON array. No prose, no markdown fences.
5. Each element: {"id": <integer>, "type": "<user_story|hl_work_item>", "confidence": <float>}

Input JSON:
```

---

## Tuning Notes

| Setting | Value | Notes |
|---------|-------|-------|
| Model | `gemini-3-flash-preview` | Fast, cost-effective; suitable for structured output tasks |
| Temperature | 0.4 | Produces deterministic structured output |
| maxOutputTokens | 8192 | Sufficient for all prompts including full board conversations |

### Improving JSON reliability

For the Work Item prompt, if Gemini occasionally returns JSON wrapped in markdown fences (` ```json ... ``` `), add a post-processing strip step in `GeminiService`:

```php
$text = preg_replace('/^```(?:json)?\s*/m', '', $text);
$text = preg_replace('/^```\s*/m', '', $text);
```

### Improving diagram reliability

If Gemini occasionally returns diagrams with `flowchart TD` instead of `graph TD`, either normalise it in PHP after generation or update the prompt to explicitly forbid `flowchart`.
