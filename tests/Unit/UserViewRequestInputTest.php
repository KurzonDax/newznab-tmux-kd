<?php

namespace Tests\Unit;

use App\Http\Controllers\BasePageController;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class UserViewRequestInputTest extends TestCase
{
    public function test_page_resolution_rejects_non_scalar_and_negative_values(): void
    {
        $controller = $this->controller();

        $this->assertSame(1, $controller->page(Request::create('/browse', 'GET', ['page' => ['2']])));
        $this->assertSame(1, $controller->page(Request::create('/browse', 'GET', ['page' => -4])));
        $this->assertSame(1, $controller->page(Request::create('/browse', 'GET', ['page' => '3.5'])));
        $this->assertSame(3, $controller->page(Request::create('/browse', 'GET', ['page' => '3'])));
    }

    public function test_scalar_input_helpers_normalize_user_query_values(): void
    {
        $controller = $this->controller();

        $this->assertSame('', $controller->scalar(Request::create('/search', 'GET', ['title' => ['bad']]), 'title'));
        $this->assertSame('ubuntu', $controller->scalar(Request::create('/search', 'GET', ['title' => 'ubuntu']), 'title'));
        $this->assertSame(42, $controller->integer(Request::create('/browse', 'GET', ['t' => '42']), 't', 100));
        $this->assertSame(100, $controller->integer(Request::create('/browse', 'GET', ['t' => '42.2']), 't', 100));
        $this->assertSame(100, $controller->integer(Request::create('/browse', 'GET', ['t' => ['42']]), 't', 100));
    }

    public function test_scalar_input_normalizes_an_explicitly_empty_query_after_http_middleware(): void
    {
        $request = Request::create('/admin/anidb-list', 'GET', ['animetitle' => '']);

        (new ConvertEmptyStringsToNull)->handle(
            $request,
            static fn (Request $request): Response => new Response,
        );

        $this->assertNull($request->input('animetitle'));
        $this->assertSame('', $this->controller()->scalar($request, 'animetitle'));
        $this->assertSame('all', $this->controller()->scalar($request, 'animetitle', 'all'));
    }

    private function controller(): object
    {
        return new class extends BasePageController
        {
            public function __construct() {}

            public function page(Request $request): int
            {
                return $this->resolvePage($request);
            }

            public function scalar(Request $request, string $key, string $default = ''): string
            {
                return $this->scalarInput($request, $key, $default);
            }

            public function integer(Request $request, string $key, int $default): int
            {
                return $this->integerInput($request, $key, $default);
            }
        };
    }
}
