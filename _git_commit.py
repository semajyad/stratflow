#!/usr/bin/env python3
"""One-shot Phase C commit helper. Run from stratflow/ directory."""
import subprocess
import sys
import os

REPO = os.path.dirname(os.path.abspath(__file__))

FILES = [
    "src/Services/StoryImprovementService.php",
    "tests/Unit/Services/StoryImprovementServiceTest.php",
    "src/Services/Prompts/WorkItemPrompt.php",
    "src/Services/Prompts/UserStoryPrompt.php",
    "src/Config/routes.php",
    "src/Controllers/WorkItemController.php",
    "src/Controllers/UserStoryController.php",
    "templates/partials/work-item-row.php",
    "templates/partials/user-story-row.php",
    "docs/superpowers/specs/2026-04-10-story-quality-phase-c-design.md",
    "docs/superpowers/plans/2026-04-10-story-quality-phase-c.md",
]

def run(args, **kwargs):
    result = subprocess.run(args, capture_output=True, text=True, cwd=REPO, **kwargs)
    print("CMD:", " ".join(str(a) for a in args))
    print("STDOUT:", result.stdout)
    print("STDERR:", result.stderr)
    print("RC:", result.returncode)
    print()
    return result

print("=== git status --short ===")
run(["git", "status", "--short"])

print("=== git add ===")
run(["git", "add"] + FILES)

print("=== git status after add ===")
run(["git", "status", "--short"])

print("=== git commit ===")
msg = "feat(quality): add Improve with AI button — Phase C story quality\n\nCo-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
run(["git", "commit", "-m", msg])

print("=== git log --oneline -3 ===")
run(["git", "log", "--oneline", "-3"])
