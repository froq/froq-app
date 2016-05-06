<?php
/**
 * Copyright (c) 2016 Kerem Güneş
 *    <k-gun@mail.com>
 *
 * GNU General Public License v3.0
 *    <http://www.gnu.org/licenses/gpl-3.0.txt>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Froq;

use Froq\Util\Traits\SingleTrait as Single;
use Froq\Util\Traits\GetterTrait as Getter;
use Froq\Logger\Logger;
use Froq\Config\Config;
use Froq\Event\Events;
use Froq\Http\{Http, Request, Response};
use Froq\Service\ServiceAdapter;
use Froq\Session\Session;
use Froq\Database\Database;

/**
 * @package Froq
 * @object  Froq\App
 * @author  Kerem Güneş <k-gun@mail.com>
 */
final class App
{
    /**
     * Single.
     * @object Froq\Util\Traits\SingleTrait
     */
    use Single;

    /**
     * Getter.
     * @object Froq\Util\Traits\GetterTrait
     */
    use Getter;

    /**
     * App envs.
     * @const string
     */
    const ENV_DEV        = 'dev',
          ENV_STAGE      = 'stage',
          ENV_PRODUCTION = 'production';

    /**
     * App env.
     * @const string
     */
    private $env;

    /**
     * App root.
     * @const string
     */
    private $root = '/';

    /**
     * Logger object.
     * @var Froq\Logger\Logger
     */
    private $logger;

    /**
     * Config object.
     * @var Froq\Config\Config
     */
    private $config;

    /**
     * Events object.
     * @var Froq\Events\Events
     */
    private $events;

    /**
     * Request object.
     * @var Froq\Htt\Request
     */
    private $request;

    /**
     * Response object.
     * @var Froq\Htt\Response
     */
    private $response;

    /**
     * Service object.
     * @var Froq\Service\Service
     */
    private $service;

    /**
     * Session object.
     * @var Froq\Session\Session
     */
    private $session;

    /**
     * Database object.
     * @var Froq\Database\Database
     */
    private $db;

    /**
     * Constructor.
     * @param array $cfg
     */
    final private function __construct(array $cfg)
    {
        if (!defined('APP_DIR')) {
            throw new AppException('Application directory is not defined!');
        }

        $this->logger = new Logger();

        // set default config first
        $this->setConfig($cfg);

        // set app as global (@see app() function)
        set_global('app', $this);

        // load core app globals
        if (is_file($file = APP_DIR .'/app/global/def.php')) require_once($file);
        if (is_file($file = APP_DIR .'/app/global/fun.php')) require_once($file);

        // set handlers
        set_error_handler(require(__dir__ .'/handler/error.php'));
        set_exception_handler(require(__dir__ .'/handler/exception.php'));
        register_shutdown_function(require(__dir__ .'/handler/shutdown.php'));

        $this->events = new Events();
        $this->request = new Request();
        $this->response = new Response();

        $this->db = new Database();
    }

    /**
     * Destructor.
     */
    final public function __destruct()
    {
        restore_error_handler();
        restore_exception_handler();
    }

    /**
     * Run.
     * @return void
     */
    final public function run()
    {
        // security & performans checks
        if ($halt = $this->haltCheck()) {
            $this->halt($halt);
        }

        // set defaults
        $this->setDefaults();

        // start output buffer
        $this->startOutputBuffer();

        $this->service = (new ServiceAdapter($this))->getService();

        // create session if service uses session
        if ($this->service->usesSession()) {
            $this->session = Session::init($this->config['app.session.cookie']);
        }

        $output = '';
        if (!$this->service->isAllowedRequestMethod($this->request->method->getName())) {
            // set fail stuff (bad request)
            $this->response->setStatus(405);
            $this->response->setContentType('none');
        } else {
            $output = $this->service->run();
        }

        // end output buffer
        $this->endOutputBuffer($output);
    }

    /**
     * Start output buffer.
     * @return void
     */
    final public function startOutputBuffer()
    {
        ini_set('implicit_flush', '1');

        $gzipOptions = $this->config->get('app.gzip');
        if (!empty($gzipOptions)) {
            if (!headers_sent()) {
                ini_set('zlib.output_compression', '0');
            }

            // detect client gzip status
            if (isset($this->request->headers['accept_encoding'])
                && (false !== strpos($this->request->headers['accept_encoding'], 'gzip'))) {
                $this->response->setGzipOptions($gzipOptions);
            }
        }

        // start!
        ob_start();
    }

    /**
     * End output buffer.
     * @param  any $output
     * @return void
     */
    final public function endOutputBuffer($output = null)
    {
        // handle redirections
        $statusCode = $this->response->status->getCode();
        if ($statusCode >= 300 && $statusCode <= 399) {
            // clean & turn off output buffering
            while (ob_get_level()) {
                ob_end_clean();
            }
            // no content!
            $this->response->setContentType('none');
        }
        // handle outputs
        else {
            // print'ed service methods return "null"
            if ($output === null) {
                $output = '';
                while (ob_get_level()) {
                    $output .= ob_get_clean();
                }
            }

            // use user output handler if provided
            if ($this->events->has('app.endOutputBufferBefore')) {
                $output = $this->events->fire('app.endOutputBufferBefore', $output);
            }

            // set response body
            $this->response->setBody($output);
        }

        // send response cookies, headers and body
        $this->response->sendHeaders();
        $this->response->sendCookies();
        $this->response->send();
    }


    /**
     * Set env.
     * @param  string $env
     * @return self
     */
    final public function setEnv(string $env): self
    {
        $this->env = $env;

        return $this;
    }

    /**
     * Get env.
     * @return string
     */
    final public function getEnv(): string
    {
        return $this->env;
    }

    /**
     * Set root.
     * @param  string $root
     * @return self
     */
    final public function setRoot(string $root): self
    {
        $this->root = $root;

        return $this;
    }

    /**
     * Get root.
     * @return string
     */
    final public function getRoot(): string
    {
        return $this->root;
    }

    /**
     * Set config.
     * @param  array $config
     * @return self
     */
    final public function setConfig(array $config): self
    {
        if ($this->config) {
            $config += $this->config->getData();
        }
        $this->config = new Config($config);

        // set/reset log options
        if ($logOpts = $this->config['app.logger']) {
            isset($logOpts['level']) && $this->logger->setLevel($logOpts['level']);
            isset($logOpts['directory']) && $this->logger->setDirectory($logOpts['directory']);
            isset($logOpts['filenameFormat']) && $this->logger->setFilenameFormat($logOpts['filenameFormat']);
        }

        return $this;
    }

    /**
     * Get config.
     * @return Froq\Config\Config
     */
    final public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Set defaults.
     * @return self
     */
    final public function setDefaults(): self
    {
        $cfg = ['locale'   => $this->config['app.locale'],
                'encoding' => $this->config['app.encoding'],
                'timezone' => $this->config['app.timezone']];

        // timezone
        date_default_timezone_set($cfg['timezone']);
        // default charset
        ini_set('default_charset', $cfg['encoding']);
        // multibyte
        mb_internal_encoding($cfg['encoding']);

        // locale stuff
        $locale = sprintf('%s.%s', $cfg['locale'], $cfg['encoding']);
        setlocale(LC_TIME, $locale);
        setlocale(LC_NUMERIC, $locale);
        setlocale(LC_MONETARY, $locale);

        return $this;
    }

    /**
     * Get dir.
     * @return string
     */
    final public function getDir(): string
    {
        return APP_DIR;
    }

    /**
     * Get load time.
     * @return string
     */
    final public function getLoadTime(): string
    {
        return sprintf('%.10f', (microtime(true) - APP_START_TIME));
    }

    /**
     * Halt app run.
     * @param  string $status
     * @return void
     */
    final private function halt(string $status)
    {
        header(sprintf('%s %s', Http::detectVersion(), $status));
        header('Connection: close');
        header('Content-Type: none');
        header('Content-Length: 0');
        header_remove('X-Powered-By');
        exit(1);
    }

    /**
     * Halt check for security & safety.
     * @return string
     */
    final private function haltCheck(): string
    {
        // check client host
        $hosts = $this->config['app.hosts'];
        if (!isset($_SERVER['HTTP_HOST']) || !in_array($_SERVER['HTTP_HOST'], $hosts)) {
            return '400 Bad Request';
        }

        // check request count
        @list($maxRequest, $allowEmptyUserAgent, $allowFileExtensionSniff)
            = $this->config['app.security'];

        if (isset($maxRequest) && count($_REQUEST) > $maxRequest) {
            return '429 Too Many Requests';
        }

        // check user agent
        if (isset($allowEmptyUserAgent) && $allowEmptyUserAgent === false
            && (!isset($_SERVER['HTTP_USER_AGENT']) || !trim($_SERVER['HTTP_USER_AGENT']))) {
            return '400 Bad Request';
        }

        // check file extension
        if (isset($allowFileExtensionSniff) && $allowFileExtensionSniff === false
            && preg_match('~\.(p[hyl]p?|rb|cgi|cf[mc]|p(pl|lx|erl)|aspx?)$~i',
                    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
            return '400 Bad Request';
        }

        // check service load
        $loadAvg = $this->config['app.loadAvg'];
        if ($loadAvg && sys_getloadavg()[0] > $loadAvg) {
            return '503 Service Unavailable';
        }

        return '';
    }
}