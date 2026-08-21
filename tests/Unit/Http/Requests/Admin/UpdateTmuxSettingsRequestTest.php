<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Admin;

use App\Http\Requests\Admin\UpdateTmuxSettingsRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UpdateTmuxSettingsRequestTest extends TestCase
{
    #[DataProvider('validThreadValuesProvider')]
    public function test_thread_value_is_valid_at_each_boundary(string $field, string $value): void
    {
        $validator = $this->validator($this->validPayload([$field => $value]));

        $this->assertFalse($validator->fails(), implode(PHP_EOL, $validator->errors()->all()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validThreadValuesProvider(): iterable
    {
        foreach (self::maximums() as $field => $maximum) {
            yield $field.' minimum' => [$field, '1'];
            yield $field.' maximum' => [$field, (string) $maximum];
        }
    }

    #[DataProvider('fixNamesTimeoutProvider')]
    public function test_fix_names_timeout_requires_an_integer_of_at_least_sixty(mixed $value, bool $valid): void
    {
        $validator = $this->validator($this->validPayload(['fix_names_timeout' => $value]));

        $this->assertSame($valid, ! $validator->fails(), implode(PHP_EOL, $validator->errors()->all()));
        $this->assertSame(! $valid, $validator->errors()->has('fix_names_timeout'));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function fixNamesTimeoutProvider(): iterable
    {
        yield 'floor' => ['60', true];
        yield 'default' => ['1200', true];
        yield 'large' => ['86400', true];
        yield 'below floor' => ['59', false];
        yield 'zero' => ['0', false];
        yield 'missing' => [null, false];
        yield 'non-integer' => ['soon', false];
        yield 'decimal' => ['60.5', false];
    }

    #[DataProvider('invalidThreadValuesProvider')]
    public function test_thread_value_is_rejected_when_required_integer_or_bounds_rules_fail(string $field, mixed $value): void
    {
        $validator = $this->validator($this->validPayload([$field => $value]));

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($field));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidThreadValuesProvider(): iterable
    {
        foreach (self::maximums() as $field => $maximum) {
            yield $field.' missing' => [$field, null];
            yield $field.' zero' => [$field, '0'];
            yield $field.' negative' => [$field, '-1'];
            yield $field.' non-integer' => [$field, 'abc'];
            yield $field.' decimal' => [$field, '1.5'];
            yield $field.' over maximum' => [$field, (string) ($maximum + 1)];
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace(
            [...array_fill_keys(array_keys(self::maximums()), '1'), 'fix_names_timeout' => '1200'],
            $overrides,
        );
    }

    /**
     * @return array<string, int>
     */
    private static function maximums(): array
    {
        return [
            'binarythreads' => 99,
            'backfillthreads' => 99,
            'releasethreads' => 99,
            'postthreads' => 99,
            'nfothreads' => 16,
            'postthreadsnon' => 99,
            'postthreadsamazon' => 99,
            'fixnamethreads' => 16,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validator(array $payload): Validator
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $factory = new Factory($translator);

        return $factory->make($payload, (new UpdateTmuxSettingsRequest)->rules());
    }
}
