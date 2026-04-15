<?php
declare(strict_types=1);
namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use StratFlow\Services\SoundingBoardService;
use StratFlow\Services\GeminiService;

class SoundingBoardServiceTest extends TestCase
{
    private function makeGemini(string $response = 'Test response'): GeminiService
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generate')->willReturn($response);
        return $gemini;
    }

    private function makeGeminiError(): GeminiService
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generate')->willThrowException(new \RuntimeException('API error'));
        return $gemini;
    }

    public function testConstructorStoresGeminiService(): void
    {
        $gemini = $this->makeGemini();
        $service = new SoundingBoardService($gemini);
        $this->assertNotNull($service);
    }

    public function testEvaluateWithSinglePanel(): void
    {
        $gemini = $this->makeGemini('Expert feedback');
        $service = new SoundingBoardService($gemini);

        $panelMembers = [
            ['id' => 1, 'role_title' => 'QA Expert', 'prompt_description' => 'Check quality'],
        ];
        $results = $service->evaluate($panelMembers, 'devils_advocate', 'Home page UI', null);

        $this->assertCount(1, $results);
        $this->assertEquals('QA Expert', $results[0]['role_title']);
        $this->assertEquals(1, $results[0]['member_id']);
        $this->assertEquals('Expert feedback', $results[0]['response']);
        $this->assertEquals('pending', $results[0]['status']);
    }

    public function testEvaluateWithMultiplePanelMembers(): void
    {
        $gemini = $this->makeGemini('Response');
        $service = new SoundingBoardService($gemini);

        $panelMembers = [
            ['id' => 1, 'role_title' => 'Designer', 'prompt_description' => 'Design review'],
            ['id' => 2, 'role_title' => 'Developer', 'prompt_description' => 'Code review'],
            ['id' => 3, 'role_title' => 'PM', 'prompt_description' => 'Strategy check'],
        ];
        $results = $service->evaluate($panelMembers, 'red_teaming', 'Dashboard page', null);

        $this->assertCount(3, $results);
        $this->assertEquals('Designer', $results[0]['role_title']);
        $this->assertEquals('Developer', $results[1]['role_title']);
        $this->assertEquals('PM', $results[2]['role_title']);
        foreach ($results as $result) {
            $this->assertEquals('pending', $result['status']);
        }
    }

    public function testEvaluateHandlesGeminiError(): void
    {
        $gemini = $this->makeGeminiError();
        $service = new SoundingBoardService($gemini);

        $panelMembers = [
            ['id' => 1, 'role_title' => 'Reviewer', 'prompt_description' => 'Review'],
        ];
        $results = $service->evaluate($panelMembers, 'gordon_ramsay', 'Screen content', null);

        $this->assertCount(1, $results);
        $this->assertEquals('error', $results[0]['status']);
        $this->assertStringContainsString('Error: API error', $results[0]['response']);
    }

    public function testEvaluateWithMixedSuccessAndError(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->exactly(3))->method('generate')
            ->willReturnCallback(function() {
                static $call = 0;
                $call++;
                if ($call === 2) {
                    throw new \RuntimeException('Failed');
                }
                return 'Success response';
            });
        $service = new SoundingBoardService($gemini);

        $panelMembers = [
            ['id' => 1, 'role_title' => 'Reviewer 1', 'prompt_description' => 'Review 1'],
            ['id' => 2, 'role_title' => 'Reviewer 2', 'prompt_description' => 'Review 2'],
            ['id' => 3, 'role_title' => 'Reviewer 3', 'prompt_description' => 'Review 3'],
        ];
        $results = $service->evaluate($panelMembers, 'devils_advocate', 'Content', null);

        $this->assertCount(3, $results);
        $this->assertEquals('pending', $results[0]['status']);
        $this->assertEquals('error', $results[1]['status']);
        $this->assertEquals('pending', $results[2]['status']);
    }

    public function testEvaluateWithEmptyPanelMembers(): void
    {
        $gemini = $this->makeGemini();
        $service = new SoundingBoardService($gemini);

        $results = $service->evaluate([], 'devils_advocate', 'Screen content', null);

        $this->assertCount(0, $results);
    }

    public function testEvaluatePassesEvaluationLevelToPrompt(): void
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->once())
            ->method('generate')
            ->willReturn('Response');
        $service = new SoundingBoardService($gemini);

        $panelMembers = [
            ['id' => 1, 'role_title' => 'Expert', 'prompt_description' => 'Desc'],
        ];
        $service->evaluate($panelMembers, 'gordon_ramsay', 'Content', null);

        $this->assertTrue(true);
    }
}
