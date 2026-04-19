<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\PricingController;
use StratFlow\Tests\Support\ControllerTestCase;

#[AllowMockObjectsWithoutExpectations]
final class PricingControllerTest extends ControllerTestCase
{
    #[Test]
    public function indexRendersPricingWithStripePrices(): void
    {
        $this->config['stripe']['price_consultancy'] = 'price_consultancy_xxx';

        $controller = new PricingController(
            $this->makeGetRequest(),
            $this->response,
            $this->auth,
            $this->db,
            $this->config,
        );

        $controller->index();

        $this->assertSame('pricing', $this->response->renderedTemplate);
        $this->assertSame('pk_test_xxx', $this->response->renderedData['stripe_key']);
        $this->assertSame('price_xxx', $this->response->renderedData['price_product']);
        $this->assertSame('price_consultancy_xxx', $this->response->renderedData['price_consultancy']);
    }
}
