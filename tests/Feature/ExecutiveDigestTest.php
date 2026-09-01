<?php

namespace Tests\Feature;

use App\Mail\ExecutiveDigest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ExecutiveDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_is_sent_to_leadership_only(): void
    {
        Mail::fake();

        User::factory()->president()->create();
        User::factory()->superadmin()->create();
        User::factory()->vpOperations()->create();
        User::factory()->regionalManager()->create(); // not a recipient

        $this->artisan('app:send-exec-digest')->assertSuccessful();

        Mail::assertSent(ExecutiveDigest::class, 3);
    }

    public function test_digest_carries_scope_and_metrics(): void
    {
        Mail::fake();

        User::factory()->president()->create();

        $this->artisan('app:send-exec-digest', ['--region' => 'NCR'])->assertSuccessful();

        Mail::assertSent(ExecutiveDigest::class, function (ExecutiveDigest $mail): bool {
            return $mail->scope === 'NCR'
                && isset($mail->summary['ops'], $mail->summary['products'], $mail->summary['regions']);
        });
    }
}
