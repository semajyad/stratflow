# Gemini Prompts Reference

StratFlow uses three prompt classes in `src/Services/Prompts/`. All prompts are PHP string constants — no templating library. They are passed to `GeminiService::generate()` alongside the user-supplied content.

All prompts target the `gemini-2.0-flash` model.

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

Translates the Mermaid strategy diagram and any OKR data attached to nodes into a prioritised backlog of High-Level Work Items (HLWIs). Each item represents approximately one month of effort for a standard Scrum team.

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
diagram and OKR data into a prioritised backlog of High-Level Work Items (HLWIs).

Task Constraints:
1. Each HLWI must represent approximately 1 month (4 weeks) of effort for a standard
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

## 4. Description Prompt

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

## Tuning Notes

| Setting | Value | Notes |
|---------|-------|-------|
| Model | `gemini-2.0-flash` | Fast, cost-effective; suitable for structured output tasks |
| Temperature | 0.7 | Balances creativity and consistency; lower values (0.2–0.4) produce more deterministic JSON output |
| maxOutputTokens | 4096 | Sufficient for all prompts; increase if work item lists are truncated on large diagrams |

### Improving JSON reliability

For the Work Item prompt, if Gemini occasionally returns JSON wrapped in markdown fences (` ```json ... ``` `), add a post-processing strip step in `GeminiService`:

```php
$text = preg_replace('/^```(?:json)?\s*/m', '', $text);
$text = preg_replace('/^```\s*/m', '', $text);
```

### Improving diagram reliability

If Gemini occasionally returns diagrams with `flowchart TD` instead of `graph TD`, either normalise it in PHP after generation or update the prompt to explicitly forbid `flowchart`.
