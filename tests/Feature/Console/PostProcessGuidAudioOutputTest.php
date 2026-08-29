<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AudioProcessing\Contracts\AudioProcessingOrchestratorInterface;
use App\Services\AudioProcessing\DTO\AudioProcessingBatchResult;
use App\Services\AudioProcessing\DTO\AudioProcessingResult;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PostProcessGuidAudioOutputTest extends TestCase
{
    public function test_audio_command_emits_per_release_progress_and_a_batch_summary(): void
    {
        $results = [
            new AudioProcessingResult(
                101,
                'abcdef1234567890',
                ProcessingOutcome::Completed,
                previewCreated: true,
                elapsedSeconds: 1.234,
            ),
            new AudioProcessingResult(
                102,
                '1234567890abcdef',
                ProcessingOutcome::NoUsefulArtifacts,
                reason: 'No decodable audio stream was found.',
                elapsedSeconds: 2.5,
            ),
            new AudioProcessingResult(
                103,
                'fedcba0987654321',
                ProcessingOutcome::DeclinedToVideoPath,
                reason: 'Video stream detected.',
                elapsedSeconds: 0.125,
            ),
            new AudioProcessingResult(
                104,
                '0a1b2c3d4e5f6789',
                ProcessingOutcome::Failed,
                reason: "NNTP connection\nlost.",
                elapsedSeconds: 10.0,
            ),
        ];

        $this->mock(AudioProcessingOrchestratorInterface::class, function (MockInterface $mock) use ($results): void {
            $mock->shouldReceive('start')
                ->once()
                ->with('a', Mockery::type('string'), '', Mockery::type('callable'))
                ->andReturnUsing(static function (
                    string $guidChar,
                    string $workerToken,
                    string $groupId,
                    callable $onReleaseSettled,
                ) use ($results): AudioProcessingBatchResult {
                    foreach ($results as $result) {
                        $onReleaseSettled($result);
                    }

                    return new AudioProcessingBatchResult($results);
                });
            $mock->shouldReceive('finish')->once();
        });

        $status = Artisan::call('postprocess:guid', [
            'type' => 'aud',
            'guid' => 'a',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Audio release #101 abcdef123456: completed; elapsed=1.234s', $output);
        $this->assertStringContainsString(
            'Audio release #102 1234567890ab: no-useful-artifacts; reason=No decodable audio stream was found.; elapsed=2.500s',
            $output,
        );
        $this->assertStringContainsString(
            'Audio release #103 fedcba098765: declined-to-video; reason=Video stream detected.; elapsed=0.125s',
            $output,
        );
        $this->assertStringContainsString(
            'Audio release #104 0a1b2c3d4e5f: error; reason=NNTP connection lost.; elapsed=10.000s',
            $output,
        );
        $this->assertStringContainsString('Audio batch a finished: picked=4 previews=1 declined=1', $output);
        $this->assertStringContainsString(
            'outcomes={"completed":1,"no-useful-artifacts":1,"declined-to-video-path":1,"failed":1}',
            $output,
        );
        $this->assertStringContainsString(
            'reasons={"No decodable audio stream was found.":1,"Video stream detected.":1,"NNTP connection\nlost.":1}',
            $output,
        );
    }
}
