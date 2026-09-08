<?php

declare(strict_types=1);

namespace Tests\Unit\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryIdentity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RecoveryIdentityTest extends TestCase
{
    public function test_inventory_identity_ignores_iteration_order_but_preserves_message_id_case(): void
    {
        $identity = new RecoveryIdentity;
        $files = [str_repeat('b', 16), str_repeat('a', 16)];
        $first = $identity->publication('media', '<Index@example.invalid>', str_repeat('s', 16), $files);
        $this->assertSame($first, $identity->publication('media', 'Index@example.invalid', str_repeat('s', 16), array_reverse($files)));
        $this->assertNotSame($first, $identity->publication('media', 'index@example.invalid', str_repeat('s', 16), $files));
        $this->assertNotSame($first, $identity->publication('media', 'Index@example.invalid', str_repeat('s', 16), [$files[0]]));
        $this->assertSame(64, strlen($first));
        $this->assertSame(20, strlen($identity->collectionProjection($first)));
        $this->assertSame(16, strlen($identity->binaryProjection($first, 'media', $files[0])));
        $this->assertNotSame($identity->binaryProjection($first, 'media', $files[0]), $identity->binaryProjection($first, 'index', $files[0]));
    }

    public function test_duplicate_file_ids_cannot_manufacture_a_new_publication(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RecoveryIdentity)->publication('media', 'Index@example.invalid', str_repeat('s', 16), [str_repeat('a', 16), str_repeat('a', 16)]);
    }

    public function test_transport_wrapper_normalization_does_not_rewrite_identity(): void
    {
        $identity = new RecoveryIdentity;
        $this->assertSame('Case@example.invalid', $identity->messageId(' <Case@example.invalid> '));
        foreach (['', '<>', "bad\nmessage", str_repeat('a', 256), '<unclosed'] as $invalid) {
            try {
                $identity->messageId($invalid);
                $this->fail('Invalid identity must not enter publication.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
