<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** A logged stack trace alone says where something failed but never what failed. */
class ExceptionLogsCarryTheMessageTest extends TestCase
{
    public function test_no_application_code_logs_an_exception_as_its_stack_trace_alone(): void
    {
        foreach (File::allFiles(app_path()) as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/Log::[a-z]+\(\s*\$[A-Za-z]+->getTraceAsString\(\)\s*\)/',
                $file->getContents(),
                $file->getRelativePathname().' logs an exception as its stack trace alone.',
            );
        }
    }
}
