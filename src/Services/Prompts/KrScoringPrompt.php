<?php
declare(strict_types=1);

namespace StratFlow\Services\Prompts;

class KrScoringPrompt
{
    /**
     * Prompt for scoring a merged PR against a single Key Result.
     *
     * Input JSON: {"kr_title":"...","kr_description":"...","kr_target":"...","pr_title":"...","pr_body":"..."}
     * Output: {"score": <0-10 integer>, "rationale": "<one sentence>"}
     */
    public const PROMPT = <<<'PROMPT'
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
PROMPT;
}
