<?php
/**
 * MIT License <https://opensource.org/licenses/mit>
 *
 * Copyright (c) 2015 Kerem Güneş
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is furnished
 * to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
declare(strict_types=1);

namespace froq;

use froq\common\traits\SingletonTrait;
use froq\common\objects\{Factory, Registry};
use froq\http\{Request, Response};
use froq\{session\Session, database\Database};
use froq\{config\Config, logger\Logger, event\Events};
use froq\{AppException, Handler, Router, Servicer, mvc\Controller};
use Throwable;

/**
 * App.
 * @package froq
 * @object  froq\App
 * @author  Kerem Güneş <k-gun@mail.com>
 * @since   1.0
 */
final class App
{
    /**
     * Singleton trait.
     * @see froq\common\traits\SingletonTrait
     */
    use SingletonTrait;

    /**
     * Root (provides options like "app.local/v1/book/1" for versioning etc.).
     * @var string
     */
    private string $root = '/';

    /**
     * Dir.
     * @var string
     */
    private string $dir;

    /**
     * Env.
     * @var string
     */
    private string $env;

    /**
     * Config.
     * @var froq\config\Config
     */
    private Config $config;

    /**
     * Logger.
     * @var froq\logger\Logger
     */
    private Logger $logger;

    /**
     * Events.
     * @var froq\events\Events
     */
    private Events $events;

    /**
     * Request.
     * @var froq\http\Request
     */
    private Request $request;

    /**
     * Response.
     * @var froq\http\Response
     */
    private Response $response;

    /**
     * Session.
     * @var froq\session\Session|null
     */
    private Session $session;

    /**
     * Database.
     * @var froq\database\Database|null
     */
    private Database $database;

    /**
     * Router.
     * @var froq\Router
     * @since 4.0
     */
    private Router $router;

    /**
     * Servicer.
     * @var froq\Servicer
     * @since 4.0
     */
    private Servicer $servicer;

    /**
     * Constructor.
     * @throws froq\AppException
     */
    private function __construct()
    {
        // App dir is required (@see skeleton/pub/index.php).
        if (!defined('APP_DIR')) {
            throw new AppException('APP_DIR is not defined');
        }

        [$this->dir, $this->config, $this->logger, $this->events, $this->router, $this->servicer]
            = [APP_DIR, new Config(), new Logger(), new Events(), new Router($this), new Servicer($this)];

        // Register app.
        Registry::set('app', $this, true);

        // Register handlers.
        Handler::registerErrorHandler();
        Handler::registerExceptionHandler();
        Handler::registerShutdownHandler();
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        Handler::unregisterErrorHandler();
        Handler::unregisterExceptionHandler();
    }

    /**
     * Is root.
     * @return bool
     */
    public function isRoot(): bool
    {
        return ($this->root == $this->request->uri()->get('path'));
    }

    /**
     * Root.
     * @return string
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Dir.
     * @return string
     */
    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Env.
     * @return string
     */
    public function env(): string
    {
        return $this->env;
    }

    /**
     * Runtime.
     * @return float
     * @since  4.0 Replaced with loadTime().
     */
    public function runtime(): float
    {
        return round(microtime(true) - APP_START_TIME, 4);
    }

    /**
     * Config.
     * @param  string|array|null $key
     * @param  any|null          $valueDefault
     * @return any|null|froq\config\Config
     * @throws froq\AppException If ket type not valid.
     */
    public function config($key = null, $valueDefault = null)
    {
        // Set is not allowed, so config readonly and set available in cfg.php files only.
        if ($key === null) {
            return $this->config;
        }
        if (is_string($key)) {
            return $this->config->get($key, $valueDefault);
        }
        if (is_array($key)) {
            return $this->config->getAll($key, $valueDefault);
        }

        throw new AppException(sprintf('Only string, array and null keys allowed for %s() '.
            'method, %s given', __method__, gettype($key)));
    }

    /**
     * Logger.
     * @return froq\logger\Logger
     */
    public function logger(): Logger
    {
        return $this->logger;
    }

    /**
     * Events.
     * @return froq\events\Events
     */
    public function events(): Events
    {
        return $this->events;
    }

    /**
     * Request.
     * @return ?froq\http\Request
     */
    public function request(): ?Request
    {
        return $this->request ?? null;
    }

    /**
     * Response.
     * @return ?froq\http\Response
     */
    public function response(): ?Response
    {
        return $this->response ?? null;
    }

    /**
     * Session.
     * @return ?froq\session\Session
     * @since  3.18
     */
    public function session(): ?Session
    {
        return $this->session ?? null;
    }

    /**
     * Database.
     * @return ?froq\database\Database
     * @since  4.0
     */
    public function database(): ?Database
    {
        return $this->database ?? null;
    }

    /**
     * Router.
     * @return froq\Router
     */
    public function router(): Router
    {
        return $this->router;
    }

    /**
     * Servicer.
     * @return froq\Servicer
     */
    public function servicer(): Servicer
    {
        return $this->servicer;
    }

    /**
     * Defines a route with given method(s).
     *
     * @param  string          $route
     * @param  string          $methods
     * @param  string|callable $call
     * @return self
     */
    public function route(string $route, string $methods, $call): self
    {
        $this->router->addRoute($route, $methods, $call);

        return $this;
    }

    /**
     * Defines a route with GET method.
     *
     * @param  string          $route
     * @param  string|callable $call
     * @return self
     */
    public function get(string $route, $call): self
    {
        return $this->route($route, 'GET', $call);
    }

    /**
     * Defines a route with POST method.
     *
     * @param  string          $route
     * @param  string|callable $call
     * @return self
     */
    public function post(string $route, $call): self
    {
        return $this->route($route, 'POST', $call);
    }

    /**
     * Defines a route with PUT method.
     *
     * @param  string          $route
     * @param  string|callable $call
     * @return self
     */
    public function put(string $route, $call): self
    {
        return $this->route($route, 'PUT', $call);
    }

    /**
     * Defines a route with GET method.
     *
     * @param  string          $route
     * @param  string|callable $call
     * @return self
     */
    public function delete(string $route, $call): self
    {
        return $this->route($route, 'DELETE', $call);
    }

    /**
     * Gets or sets a service.
     *
     * @param  string         $name
     * @param  array|callable $service
     * @return ?object
     */
    public function service(string $name, $service = null): ?object
    {
        return !$service
            ? $this->servicer->getService($name)
            : $this->servicer->addService($name, $service);
    }

    /**
     * Run.
     * @param  array<string, any> $options
     * @return void
     * @throws froq\AppException
     */
    public function run(array $options = null): void
    {
        // Apply run options (user options) (@see skeleton/pub/index.php).
        @ ['env' => $env, 'root' => $root, 'configs' => $configs] = $options;

        $env     && $this->env  = $env;
        $root    && $this->root = $root;
        $configs && $this->applyConfigs($configs);

        if ($this->env == '' || $this->root == '') {
            throw new AppException('App env or root cannot be empty');
        }

        // Apply defaults (timezone, locales etc.).
        $this->applyDefaults();

        $this->request = new Request($this);
        $this->response = new Response($this);

        // These options can be emptied by developer to disable session or database
        // with 'null' if app won't be using session and/or database.
        [$session, $database] = $this->config->getAll(['session', 'database']);

        if (isset($session)) {
            $this->session = Factory::initSingle(Session::class, $session);
        }
        if (isset($database)) {
            $this->database = Factory::initSingle(Database::class, $this);
        }

        // @override
        Registry::set('app', $this);

        // Resolve route and dispatch.
        @ [$controller, $action, $actionParams] = $this->router->resolve(
            $uri    = $this->request->uri()->getPath(),
            $method = $this->request->method()->getName(),
        );

        if ($controller == null) {
            throw new AppException('No controller route found for "%s %s" URI',
                [$method, htmlspecialchars($uri)], 404);
        } elseif ($action == null) {
            throw new AppException('No action route found for "%s %s" URI',
                [$method, htmlspecialchars($uri)], 404);
        } elseif (!class_exists($controller)) {
            throw new AppException('No controller class found such "%s"',
                [$controller], 404);
        } elseif (!is_callable($action) && !method_exists($controller, $action)) {
            throw new AppException('No controller action found such "%s::%s"',
                [$controller, $action], 404);
        }

        $this->startOutputBuffer();

        $class = new $controller($this);
        if (is_string($action)) {
            $this->events->fire('app.before');
            $return = $class->call($action, $actionParams);
            $this->events->fire('app.after');
        } elseif (is_callable($action)) {
            $this->events->fire('app.before');
            $return = $class->callCallable($action, $actionParams);
            $this->events->fire('app.after');
        }

        $this->endOutputBuffer($return);
    }

    /**
     * Error.
     * @param  Throwable $error
     * @param  bool      $log
     * @return void
     * @since  4.0
     */
    public function error(Throwable $error, bool $log = true): void
    {
        $log && $this->errorLog($error);

        // Call user error handler if provided.
        $this->events->fire('app.error', $error);

        // Status may change in @default.error().
        try {
            $this->response->setStatusCode(500);
        } catch (Throwable $e) {}

        // Clear outputs (@default.error() will work below for output).
        while (ob_get_level()) {
            ob_end_clean();
        }

        $output = null;
        try {
            $output = (new Controller($this))->forward('@default.error', [$error]);
        } catch (Throwable $e) {}

        // Prepend error top of the output (if ini.display_errors is on).
        if ($output == null || is_string($output)) {
            $outputErrors = ini('display_errors', '', true);
            if ($outputErrors) {
                $output = trim($error ."\n\n". $output);
            }
        }

        $this->endOutputBuffer($output, true);
    }

    /**
     * Error log.
     * @param  any $error
     * @return void
     * @since  4.0
     */
    public function errorLog($error): void
    {
        $this->logger->logFail($error);
    }

    /**
     * Start output buffer.
     * @return void
     */
    private function startOutputBuffer(): void
    {
        ob_start();
        ob_implicit_flush(0);
        ini_set('implicit_flush', 'off');
    }

    /**
     * End output buffer.
     * @param  any       $output
     * @param  bool|null $isError @internal (@see Message.setBody())
     * @return void
     */
    private function endOutputBuffer($output, bool $isError = null): void
    {

        $response = $this->response();
        if ($response == null) {
            throw new AppException('App has no response yet');
        }

        // Handle redirections.
        $code = $response->getStatusCode();
        if ($code >= 300 && $code <= 399) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            $response->setBody(null, ['type' => 'n/a']);
        }
        // Handle outputs & returns.
        else {
            $body = $response->getBody();
            $content = $body->getContent();
            $contentAttributes = $body->getContentAttributes();

            // Pass, output comes from App.error() already.
            if ($isError) {
            }
            // Actions that use echo/print/view()/response.setBody() will return null.
            elseif ($output === null) {
                $output = '';
                while (ob_get_level()) {
                    $output .= ob_get_clean();
                }
            }

            // Returned content from action or set on body.
            $content = $content ?: $output;

            // Call user output handler if provided.
            if ($this->events->has('app.output')) {
                $content = $this->events->fire('app.output', $content);
            }

            $response->setBody($content, $contentAttributes, $isError);
        }

        $exposeAppRuntime = $this->config('exposeAppRuntime');
        if ($exposeAppRuntime === true || $exposeAppRuntime === $this->env) {
            $response->setHeader('X-App-Runtime', sprintf('%.4f', $this->runtime()));
        }

        // The end..
        $response->end();
    }

    /**
     * Apply configs.
     * @param  array $configs
     * @return void
     */
    private function applyConfigs(array $configs): void
    {
        $this->config->update($configs);

        // Set/reset logger options.
        @ [['level' => $level, 'directory' => $directory], $routes, $services]
            = $this->config->getAll(['logger', 'routes', 'services']);

        $level     && $this->logger->setOption('level', $level);
        $directory && $this->logger->setOption('directory', $directory);

        $routes    && $this->router->addRoutes($routes);
        $services  && $this->servicer->addServices($services);
    }

    /**
     * Apply defaults.
     * @return void
     */
    private function applyDefaults(): void
    {
        [$timezone, $encoding, $locales]
            = $this->config->getAll(['timezone', 'encoding', 'locales']);

        if ($timezone != null) {
            date_default_timezone_set($timezone);
        }

        if ($encoding != null) {
            ini_set('default_charset', $encoding);
            ini_set('internal_encoding', $encoding);
        }

        if ($locales != null) {
            foreach ($locales as $name => $value) {
                setlocale($name, $value);
            }
        }
    }
}
