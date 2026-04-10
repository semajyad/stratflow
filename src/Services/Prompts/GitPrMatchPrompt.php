<?php
declare(strict_types=1);

namespace StratFlow\Services\Prompts;

class GitPrMatchPrompt
{
    /**
     * System prompt for AI PR-to-story matching.
     *
     * Input JSON shape:
     * {"pr_title":"...","pr_body":"...","branch":"...","candidates":[{"id":1,"type":"user_story","title":"...","description":"..."},...]}
     *
     * Expected output JSON array:
     * [{"id":1,"type":"user_story","confidence":0.85},...]
     * Only include candidates with confidence > 0.5.
     */
    public const PROMPT = <<<'PROMPT'
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
PROMPT;
}
