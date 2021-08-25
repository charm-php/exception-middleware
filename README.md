charm/exception-middleware
==========================

A simple middleware implementation that catches exceptions thrown later in the
exception stack and renders a nice error message.

Configuration
-------------

```
$middleware = new ExceptionMiddleware([
    'error_handler' => function(\Throwable $e) {
        if ($e->getCode() === 404) {
            return render_page_not_found();
        }
        // If error handler returns null, the ExceptionMiddleware will render a response.
        return null;
    }
]);
```
