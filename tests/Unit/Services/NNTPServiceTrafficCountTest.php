<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NNTP\NntpProvider;
use App\Services\NNTP\NNTPService;
use App\Services\NNTP\NntpTrafficMeter;
use App\Services\YencService;
use DariusIII\NetNntp\Error as NntpError;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class NNTPServiceTrafficCountTest extends TestCase
{
    /** @var resource */
    private mixed $reader;

    /** @var resource */
    private mixed $writer;

    private int $written = 0;

    protected function setUp(): void
    {
        parent::setUp();
        ProductionTables::fromAuthority()->create('nntp_provider_traffic');
        [$this->reader, $this->writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    }

    protected function tearDown(): void
    {
        fclose($this->reader);
        fclose($this->writer);
        parent::tearDown();
    }

    public function test_every_byte_read_from_the_provider_is_counted_without_changing_what_is_parsed(): void
    {
        $probe = new NNTPServiceTrafficProbe($this->reader);
        $probe->useProvider(new NntpProvider(1, 'probe-provider', '127.0.0.1', 119, false, '', '', 1, 1, true));

        $this->serve("200 probe ready\r\n");
        $this->assertSame(200, $probe->status());

        $this->serve("215 list follows\r\nfirst line\r\n..dot-stuffed line\r\n.\r\n");
        $this->assertSame(215, $probe->status());
        $this->assertSame(['first line', '.dot-stuffed line'], $probe->text());

        $overview = "1\tFirst subject\tposter@example.test\r\n2\tSecond subject\tposter@example.test";
        $compressed = gzcompress($overview);
        $this->assertStringNotContainsString(".\r\n", $compressed, 'fixture must not hit the duplicate-append path');
        $this->serve("224 overview follows\r\n");
        $this->assertSame(224, $probe->status());
        $this->serve($compressed.".\r\n");
        $this->assertSame(explode("\r\n", $overview), $probe->compressedText());

        $data = 'article body bytes . = and more';
        $this->serve("222 0 <article@example.test> body\r\n".app(YencService::class)->encode($data, 'file.bin')."\r\n.\r\n");
        $this->assertSame($data, $probe->message('alt.test', 'article@example.test'));

        app(NntpTrafficMeter::class)->flush();

        $this->assertSame($this->written, (int) DB::table('nntp_provider_traffic')
            ->where('provider', 'probe-provider')->value('bytes_received'));
    }

    private function serve(string $bytes): void
    {
        $this->assertSame(strlen($bytes), fwrite($this->writer, $bytes));
        $this->written += strlen($bytes);
    }
}

final class NNTPServiceTrafficProbe extends NNTPService
{
    /** @param resource $socket */
    public function __construct(mixed $socket)
    {
        $this->_echo = false;
        $this->_socket = $socket;
        $this->_yencService = app(YencService::class);
        $this->_selectedGroupSummary = ['group' => 'alt.test'];
    }

    public function status(): mixed
    {
        return $this->_getStatusResponse();
    }

    public function text(): mixed
    {
        $this->_compressionEnabled = false;

        return $this->_getTextResponse();
    }

    public function compressedText(): mixed
    {
        $this->_compressionEnabled = true;
        $this->_currentStatusResponse = [224, 'overview follows [COMPRESS=GZIP]'];

        return $this->_getTextResponse();
    }

    public function message(string $group, string $messageId): mixed
    {
        $result = $this->_getMessage($group, $messageId);

        return $result instanceof NntpError ? $result->getMessage() : $result;
    }

    public function __destruct() {}
}
