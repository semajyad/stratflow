<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\SuccessController;
use StratFlow\Tests\Support\ControllerTestCase;

#[AllowMockObjectsWithoutExpectations]
final class SuccessControllerTest extends ControllerTestCase
{
    #[Test]
    public function indexRendersSuccessTemplateWithNoData(): void
    {
        $controller = new SuccessController(
            $this->makeGetRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config,
        );

        $controller->index();

        $this->assertSame('success', $this->response->renderedTemplate);
        $this->assertSame([], $this->response->renderedData);
    }
}
