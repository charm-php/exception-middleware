<?php
use Charm\Middleware\ExceptionMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ExceptionMiddlewareTest extends TestCase {

    /** @var array<string, mixed> Recorded by the fake factories */
    private array $seen = [];

    protected function setUp(): void {
        $this->seen = [];

        $stream = $this->createStub(StreamInterface::class);
        $streamFactory = $this->createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(function (string $content) use ($stream) {
            $this->seen['body'] = $content;
            return $stream;
        });

        $responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturnCallback(function (int $code, string $phrase = '') {
            $this->seen['status'] = $code;
            $this->seen['phrase'] = $phrase;
            $response = $this->createStub(ResponseInterface::class);
            $response->method('withAddedHeader')->willReturnCallback(function (string $name, $value) use ($response) {
                $this->seen['headers'][$name] = $value;
                return $response;
            });
            $response->method('withBody')->willReturnSelf();
            return $response;
        });

        ExceptionMiddleware::$Psr_Http_Message_ResponseFactoryInterface = fn() => $responseFactory;
        ExceptionMiddleware::$Psr_Http_Message_StreamFactoryInterface = fn() => $streamFactory;
    }

    protected function tearDown(): void {
        ExceptionMiddleware::$Psr_Http_Message_ResponseFactoryInterface = null;
        ExceptionMiddleware::$Psr_Http_Message_StreamFactoryInterface = null;
    }

    private function handlerThrowing(Throwable $e): RequestHandlerInterface {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException($e);
        return $handler;
    }

    private function process(ExceptionMiddleware $middleware, RequestHandlerInterface $handler): ResponseInterface {
        return $middleware->process($this->createStub(ServerRequestInterface::class), $handler);
    }

    public function testPassesThroughResponseWhenNoExceptionIsThrown(): void {
        $expected = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expected);

        $this->assertSame($expected, $this->process(new ExceptionMiddleware(), $handler));
        $this->assertSame([], $this->seen);
    }

    public function testRendersErrorPageForUnknownCode(): void {
        $this->process(new ExceptionMiddleware(), $this->handlerThrowing(new RuntimeException('Boom happened')));

        $this->assertSame(500, $this->seen['status']);
        $this->assertSame('Internal Server Error', $this->seen['phrase']);
        $this->assertSame('text/html; charset=utf-8', $this->seen['headers']['Content-Type']);
        $this->assertSame('no-cache', $this->seen['headers']['Cache-Control']);
        $this->assertStringContainsString('<title>Boom happened</title>', $this->seen['body']);
        $this->assertStringContainsString('RuntimeException', $this->seen['body']);
        $this->assertStringContainsString(basename(__FILE__), $this->seen['body']);
    }

    #[DataProvider('httpCodeProvider')]
    public function testUsesExceptionCodeAsStatusWhenItIsAnHttpCode(int $code, string $phrase): void {
        $this->process(new ExceptionMiddleware(), $this->handlerThrowing(new Exception('x', $code)));

        $this->assertSame($code, $this->seen['status']);
        $this->assertSame($phrase, $this->seen['phrase']);
        $this->assertStringContainsString("$code $phrase", $this->seen['body']);
    }

    public static function httpCodeProvider(): array {
        return [
            'not found' => [404, 'Not Found'],
            'forbidden' => [403, 'Forbidden'],
            'server error' => [500, 'Internal Server Error'],
        ];
    }

    public function testNonHttpExceptionCodeFallsBackTo500(): void {
        $this->process(new ExceptionMiddleware(), $this->handlerThrowing(new Exception('x', 12345)));

        $this->assertSame(500, $this->seen['status']);
    }

    public function testErrorHandlerResponseOverridesErrorPage(): void {
        $custom = $this->createStub(ResponseInterface::class);
        $caught = null;
        $middleware = new ExceptionMiddleware(['error_handler' => function (Throwable $e) use ($custom, &$caught) {
            $caught = $e;
            return $custom;
        }]);
        $exception = new Exception('nope', 404);

        $this->assertSame($custom, $this->process($middleware, $this->handlerThrowing($exception)));
        $this->assertSame($exception, $caught);
        $this->assertArrayNotHasKey('body', $this->seen);
    }

    public function testErrorHandlerReturningNullFallsBackToErrorPage(): void {
        $middleware = new ExceptionMiddleware(['error_handler' => fn(Throwable $e) => null]);

        $this->process($middleware, $this->handlerThrowing(new Exception('fallback', 404)));

        $this->assertSame(404, $this->seen['status']);
        $this->assertStringContainsString('fallback', $this->seen['body']);
    }

    public function testStackTraceIsRendered(): void {
        $this->process(new ExceptionMiddleware(), $this->handlerThrowing($this->makeExceptionDeep()));

        $this->assertStringContainsString('<div class="trace">', $this->seen['body']);
        $this->assertStringContainsString('makeExceptionDeep', $this->seen['body']);
    }

    private function makeExceptionDeep(): Exception {
        return new Exception('deep');
    }
}
