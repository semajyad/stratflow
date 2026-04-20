"""
Unit tests for scripts/ci/check_test_touches.py
Run with: python -m pytest tests/Python/test_check_test_touches.py -v
"""

import sys
from pathlib import Path
from unittest.mock import patch

import pytest

sys.path.insert(0, str(Path(__file__).parent.parent.parent))
from scripts.ci.check_test_touches import get_staged_changed_files, source_to_test_path


class TestSourceToTestPath:
    def test_controller_mapping(self):
        assert source_to_test_path("src/Controllers/ApiStoriesController.php") == \
            "tests/Unit/Controllers/ApiStoriesControllerTest.php"

    def test_model_mapping(self):
        assert source_to_test_path("src/Models/User.php") == \
            "tests/Unit/Models/UserTest.php"

    def test_service_mapping(self):
        assert source_to_test_path("src/Services/StripeService.php") == \
            "tests/Unit/Services/StripeServiceTest.php"

    def test_middleware_mapping(self):
        assert source_to_test_path("src/Middleware/AuthMiddleware.php") == \
            "tests/Unit/Middleware/AuthMiddlewareTest.php"

    def test_top_level_src(self):
        assert source_to_test_path("src/Router.php") == \
            "tests/Unit/RouterTest.php"

    def test_exempt_prompts_dir(self):
        assert source_to_test_path("src/Services/Prompts/SystemPrompt.php") is None

    def test_exempt_config_dir(self):
        assert source_to_test_path("src/Config/AppConfig.php") is None

    def test_non_php_file(self):
        assert source_to_test_path("src/Controllers/Foo.js") is None

    def test_non_src_file(self):
        assert source_to_test_path("public/index.php") is None

    def test_test_file_itself(self):
        # test files are not in src/, so they get no mapping
        assert source_to_test_path("tests/Unit/Controllers/FooTest.php") is None

    def test_nested_controller(self):
        assert source_to_test_path("src/Controllers/Api/StoryController.php") == \
            "tests/Unit/Controllers/Api/StoryControllerTest.php"


def test_get_staged_changed_files_excludes_deleted_files():
    result = type("Result", (), {
        "returncode": 0,
        "stdout": "M\tsrc/Core/Request.php\nD\tsrc/Old.php\nA\ttests/Unit/Core/RequestTest.php\n",
        "stderr": "",
    })()

    with patch("scripts.ci.check_test_touches.subprocess.run", return_value=result):
        assert get_staged_changed_files() == [
            "src/Core/Request.php",
            "tests/Unit/Core/RequestTest.php",
        ]


def test_get_staged_changed_files_uses_new_path_for_renames():
    result = type("Result", (), {
        "returncode": 0,
        "stdout": "R100\tsrc/Old.php\tsrc/New.php\nC100\tsrc/Base.php\tsrc/Copy.php\n",
        "stderr": "",
    })()

    with patch("scripts.ci.check_test_touches.subprocess.run", return_value=result):
        assert get_staged_changed_files() == [
            "src/New.php",
            "src/Copy.php",
        ]


def test_get_staged_changed_files_exits_when_git_diff_fails(capsys):
    result = type("Result", (), {
        "returncode": 128,
        "stdout": "",
        "stderr": "fatal: not a git repository",
    })()

    with patch("scripts.ci.check_test_touches.subprocess.run", return_value=result):
        with pytest.raises(SystemExit) as exc_info:
            get_staged_changed_files()

    assert exc_info.value.code == 2
    assert "git diff --cached failed" in capsys.readouterr().err
