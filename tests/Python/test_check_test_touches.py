"""
Unit tests for scripts/ci/check_test_touches.py
Run with: python -m pytest tests/Python/test_check_test_touches.py -v
"""

import sys
from pathlib import Path
from unittest.mock import patch

import pytest

sys.path.insert(0, str(Path(__file__).parent.parent.parent))
from scripts.ci.check_test_touches import source_to_test_path


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
