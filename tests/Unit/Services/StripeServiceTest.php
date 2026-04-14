<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\StripeService;

/**
 * StripeServiceTest
 *
 * Unit tests for StripeService — tests pure logic methods that do not
 * require network calls (price ID mapping, mode resolution, validPriceIds).
 * Methods that call Stripe SDK are covered by integration tests.
 */
class StripeServiceTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    private function makeService(array $configOverride = []): StripeService
    {
        $config = array_merge([
            'secret_key'              => 'sk_test_placeholder',
            'webhook_secret'          => 'whsec_placeholder',
            'price_product'           => 'price_product_abc',
            'price_consultancy'       => 'price_consultancy_xyz',
            'price_user_pack'         => 'price_user_pack_def',
            'price_evaluation_board'  => 'price_eval_board_ghi',
        ], $configOverride);
        return new StripeService($config);
    }

    // ===========================
    // VALID PRICE IDS
    // ===========================

    #[Test]
    public function validPriceIdsReturnsAllConfiguredPrices(): void
    {
        $svc    = $this->makeService();
        $prices = $svc->validPriceIds();
        $this->assertContains('price_product_abc', $prices);
        $this->assertContains('price_consultancy_xyz', $prices);
        $this->assertContains('price_user_pack_def', $prices);
        $this->assertContains('price_eval_board_ghi', $prices);
    }

    #[Test]
    public function validPriceIdsExcludesEmptyStrings(): void
    {
        $svc    = $this->makeService(['price_user_pack' => '', 'price_evaluation_board' => '']);
        $prices = $svc->validPriceIds();
        $this->assertNotContains('', $prices);
    }

    #[Test]
    public function validPriceIdsReturnsTwoItemsWithOnlyMandatoryConfig(): void
    {
        $svc    = $this->makeService(['price_user_pack' => '', 'price_evaluation_board' => '']);
        $prices = $svc->validPriceIds();
        $this->assertCount(2, $prices);
    }

    // ===========================
    // PLAN TYPE FOR PRICE
    // ===========================

    #[Test]
    public function planTypeForPriceReturnsProductForProductPriceId(): void
    {
        $svc = $this->makeService();
        $this->assertSame('product', $svc->planTypeForPrice('price_product_abc'));
    }

    #[Test]
    public function planTypeForPriceReturnsConsultancyForConsultancyPriceId(): void
    {
        $svc = $this->makeService();
        $this->assertSame('consultancy', $svc->planTypeForPrice('price_consultancy_xyz'));
    }

    #[Test]
    public function planTypeForPriceReturnsUserPackForUserPackPriceId(): void
    {
        $svc = $this->makeService();
        $this->assertSame('user_pack', $svc->planTypeForPrice('price_user_pack_def'));
    }

    #[Test]
    public function planTypeForPriceReturnsEvaluationBoardForEvalPriceId(): void
    {
        $svc = $this->makeService();
        $this->assertSame('evaluation_board', $svc->planTypeForPrice('price_eval_board_ghi'));
    }

    #[Test]
    public function planTypeForPriceReturnsUnknownForUnrecognisedPriceId(): void
    {
        $svc = $this->makeService();
        $this->assertSame('unknown', $svc->planTypeForPrice('price_unknown_111'));
    }

    // ===========================
    // MODE FOR PRODUCT TYPE
    // ===========================

    #[Test]
    public function modeForProductTypeReturnsPaymentForUserPack(): void
    {
        $svc = $this->makeService();
        $this->assertSame('payment', $svc->modeForProductType('user_pack'));
    }

    #[Test]
    public function modeForProductTypeReturnsPaymentForEvaluationBoard(): void
    {
        $svc = $this->makeService();
        $this->assertSame('payment', $svc->modeForProductType('evaluation_board'));
    }

    #[Test]
    public function modeForProductTypeReturnsSubscriptionForDefaultProductType(): void
    {
        $svc = $this->makeService();
        $this->assertSame('subscription', $svc->modeForProductType('product'));
    }

    #[Test]
    public function modeForProductTypeReturnsSubscriptionForConsultancy(): void
    {
        $svc = $this->makeService();
        $this->assertSame('subscription', $svc->modeForProductType('consultancy'));
    }

    #[Test]
    public function modeForProductTypeReturnsSubscriptionForUnknownType(): void
    {
        $svc = $this->makeService();
        $this->assertSame('subscription', $svc->modeForProductType('anything_else'));
    }

    // ===========================
    // PLAN TYPE + MODE COHERENCE
    // ===========================

    #[Test]
    public function productPriceIdMapsToSubscriptionMode(): void
    {
        $svc  = $this->makeService();
        $type = $svc->planTypeForPrice('price_product_abc');
        $mode = $svc->modeForProductType($type);
        $this->assertSame('subscription', $mode);
    }

    #[Test]
    public function userPackPriceIdMapsToPaymentMode(): void
    {
        $svc  = $this->makeService();
        $type = $svc->planTypeForPrice('price_user_pack_def');
        $mode = $svc->modeForProductType($type);
        $this->assertSame('payment', $mode);
    }

    #[Test]
    public function evaluationBoardPriceIdMapsToPaymentMode(): void
    {
        $svc  = $this->makeService();
        $type = $svc->planTypeForPrice('price_eval_board_ghi');
        $mode = $svc->modeForProductType($type);
        $this->assertSame('payment', $mode);
    }
}
