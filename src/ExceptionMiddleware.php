<?php
namespace Charm\Middleware;

use Charm\Interop\InjectedResponseFactory;
use Charm\Interop\InjectedStreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * A simple middleware that captures exceptions thrown in later middlewares or the request handler,
 * and renders the exception with a stack trace.
 */
class ExceptionMiddleware implements MiddlewareInterface {
    const DEFAULT_OPTIONS = [
        /**
         * Closure(\Throwable $e): ?ResponseInterface
         *
         * This closure may return a Response to override the error page. If it returns
         * null, the built in error page is shown.
         */
        'error_handler' => null,
    ];

    use InjectedResponseFactory;
    use InjectedStreamFactory;

    protected array $options;

    public function __construct(array $options = []) {
        $this->options = $options + self::DEFAULT_OPTIONS;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface {
        try {
            return $next->handle($request);
        } catch (Throwable $e) {
            if (!empty($this->options['error_handler'])) {
                $result =  ($this->options['error_handler'])($e);
                if ($result !== null) {
                    return $result;
                }
            }

            if (key_exists($e->getCode(), \Charm\Error\HttpCodes::PHRASES)) {
                $phrase = \Charm\Error\HttpCodes::PHRASES[$e->getCode()];
                $code = $e->getCode();
            } else {
                $phrase = "Internal Server Error";
                $code = 500;
            }

            $className = get_class($e);
            $filename = $e->getFile();
            $lineNumber = $e->getLine();
            $stackTrace = $e->getTraceAsString();
            $errorCode = $e->getCode();
            $message = htmlspecialchars($e->getMessage());
            $quotedMessage = htmlspecialchars(json_encode($e->getMessage()));
            $errorCodeOrDots = $errorCode !== 0 ? ", $errorCode" : '';

            $stackTrace = preg_replace_callback('|^#(?<num>\d+) (?<path>[^(]+)\((?<line>\d+)\): (?<call>[^\n\r]*)|m', function($match) {
                return <<<EOT
                    <div class="trace">
                        <div class="num">{$match['num']}</div>
                        <div class="path">{$match['path']}</div>
                        <div class="line">{$match['line']}</div>
                        <div class="call"><span class='call-line'>{$match['line']}</span>{$match['call']}</div>
                    </div>
                    EOT;
                return '<span class="trace-number">'.$match[0].'</span>';
            }, $stackTrace);

            $body = <<<EOT
            <!DOCTYPE html>
            <html>
                <head>
                    <title>{$message}</title>
                </head>
                <body>
                <style scoped>
                html, body {
                    font-family: sans-serif;
                    margin: 0;
                    padding: 0;
                }
                .code-thing {
                    position: absolute;
                    top: 0.5em;
                    right: 1.3em;
                    font-size: 4em;
                    color: #fff;
                    font-style: italic;
                }
                .code-thing::before {
                    content: "code";
                    font-size: 0.2em;
                }
                .phrase-thing {
                    position: absolute;
                    top: 0.5em;
                    left: 1em;
                    font-size: 2em;
                    color: #fff;
                    font-style: italic;
                }
                h1 {
                    background-color: #aa2244;
                    padding: 2.5em 1em 1em 1em;
                    margin: 0;
                    color: white;
                }
                h1 small {
                    position: absolute;
                    margin-top: -1.3em;
                    font-size: 0.5em;
                    font-weight: normal;
                    color: rgba(255,255,255,0.8);
                }
                h1 small strong {
                    color: #fff;
                }
                p {
                    background-color: #ccc;
                    margin: 0;
                    padding: 1em 2em;
                }
                .stackTrace {
                    margin: 0.4em 2em;
                }
                .trace {
                    border: 1px solid #eee;
                    margin: 1em 0 0 0;
                    padding: 0.5em 0;
                    line-height: 1em;
                    padding-left: 5em;
                    background-color: #f8f8f8;
                    border-radius: 0.5em;
                }
                .trace:hover {
                    background-color: #f0f0f0;
                }
                .trace .num {
                    font-size: 2em;
                    color: #ccc;
                    position: absolute;
                    margin-top: 0.5em;
                    margin-left: -2.2em;
                    width: 1.5em;
                    text-align: right;
                }
                .trace .path {
                    display: inline;
                    font-family: monospace;
                    font-weight: bold;
                }
                .trace .path::before {
                    content: "file: ";
                    font-family: sans-serif;
                    color: #aa2244;
                    font-size: 0.8em;
                    font-weight: normal;
                }
                .trace .line {
                    display: inline;
                    font-family: monospace;
                    font-weight: bold;
                }
                .trace .line::before {
                    content: "line: ";
                    font-family: sans-serif;
                    color: #aa2244;
                    font-size: 0.8em;
                    font-weight: normal;
                }
                .trace .call {
                    margin: 0.5em 0.3em 0.5em 0;
                    padding: 0.4em;
                    border: 1px dashed #cca;
                    font-family: monospace;
                    background-color: #eee;
                }
                .trace:hover .call {
                    background-color: #ffffff;
                }
                .trace .call .call-line {
                    color: rgba(0, 0, 0, 0.6);
                    font-weight: bold;
                }
                .trace .call .call-line::after {
                    content: "";
                    padding-right: 1em;
                    font-weight: normal;
                }
                .trace * {
                    margin: 0;
                    padding: 0;
                }
                .trace.first {
                    background-color: #eee;
                    border: 2px solid #aaa;
                }
                </style>
                    <div class="code-thing">{$errorCode}</div>
                    <div class="phrase-thing">{$code} {$phrase}</div>
                    <h1><small><strong>{$className}</strong> thrown in <strong>{$filename}</strong> on line <strong>{$lineNumber}</strong></small>{$e->getMessage()}</h1>
                    <p>An exception occurred in file <strong>{$filename}</strong> on line <strong>{$lineNumber}</strong></p>
                    <div class="stackTrace">
                        <div class="trace first">
                            <div class="num">⚠️</div>
                            <div class="path">{$filename}</div>
                            <div class="line">{$lineNumber}</div>
                            <div class="call"><span class='call-line'>{$lineNumber}</span><em>throw new {$className}({$quotedMessage}{$errorCodeOrDots});</em></div>
                        </div>
                        {$stackTrace}
                    </div>
                </body>
            </html>
            EOT;

            return $this->responseFactory()
            ->createResponse($code, $phrase)
            ->withAddedHeader('Content-Type', 'text/html; charset=utf-8')
            ->withAddedHeader('Cache-Control', 'no-cache')
            ->withBody($this->streamFactory()->createStream($body));
        }
    }

}
