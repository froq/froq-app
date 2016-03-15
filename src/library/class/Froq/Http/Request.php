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

namespace Froq\Http;

use Froq\Http\Uri\Uri;
use Froq\Http\Request\Params;
use Froq\Http\{Client, Headers, Cookies};
use Froq\Util\Traits\GetterTrait as Getter;

/**
 * @package    Froq
 * @subpackage Froq\Http
 * @object     Froq\Http\Request
 * @author     Kerem Güneş <k-gun@mail.com>
 */
final class Request
{
   /**
    * Getter.
    * @object Froq\Util\Traits\GetterTrait
    */
   use Getter;

   /**
    * Methods.
    * @conts string
    */
   const METHOD_GET    = 'GET',
         METHOD_POST   = 'POST',
         METHOD_PUT    = 'PUT',
         METHOD_PATCH  = 'PATCH',
         METHOD_DELETE = 'DELETE';

   /**
    * HTTP Version.
    * @var string
    */
   private $httpVersion;

   /**
    * Request scheme.
    * @var string
    */
   private $scheme;

   /**
    * Request method.
    * @var string
    */
   private $method;

   /**
    * Request URI.
    * @var string
    */
   private $uri;

   /**
    * Parsed body data.
    * @var array
    */
   private $body = [];

   /**
    * Raw body data.
    * @var string
    */
   private $bodyRaw = ''; // @wait

   /**
    * Request time/time float.
    * @var int/float
    */
   private $time;

   /**
    * Request time.
    * @var int
    */
   private $timeFloat;

   /**
    * Client object.
    * @var Froq\Http\Client
    */
   private $client;

   /**
    * Params object (not stack).
    * @var Froq\Http\Request\Params
    */
   private $params;

   /**
    * Header stack.
    * @var Froq\Http\Headers
    */
   private $headers;

   /**
    * Cookie stack.
    * @var Froq\Http\Cookies
    */
   private $cookies;

   /**
    * Constructor.
    */
   final public function __construct()
   {
      // set http version (not really)
      $this->httpVersion = $_SERVER['SERVER_PROTOCOL'];

      // set method
      $this->method = strtoupper($_SERVER['REQUEST_METHOD']);

      // set scheme
      if (isset($_SERVER['REQUEST_SCHEME'])) {
         $this->scheme = strtolower($_SERVER['REQUEST_SCHEME']);
      } elseif ($_SERVER['SERVER_PORT'] == '443') {
         $this->scheme = 'https';
      } else {
         $this->scheme = 'http';
      }

      // set uri
      $this->uri = new Uri($this->scheme .'://'.
         $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI']);

      // fix dotted get keys
      $_GET = $this->loadGlobalVar('GET');

      // set/parse body for overwrite methods
      switch ($this->method) {
         case self::METHOD_PUT:
         case self::METHOD_POST:
         case self::METHOD_PATCH:
            // act as post
            $_POST = $this->loadGlobalVar('POST');
            $this->body = $_POST;
            break;
      }

      // fix dotted cookie keys
      $_COOKIE = $this->loadGlobalVar('COOKIE');

      // set times
      $this->time = (int) $_SERVER['REQUEST_TIME'];
      $this->timeFloat = (float) $_SERVER['REQUEST_TIME_FLOAT'];

      // set client that contains ip & language etc.
      $this->client = new Client();

      // set params
      $this->params = new Params();

      $headers = [];
      foreach (getallheaders() as $key => $value) {
         // convert keys like User-Agent -> user_agent
         $key = str_replace('-', '_', strtolower($key));
         $headers[$key] = $value;
      }

      // set headers/cookies as an object that iterable/traversable
      $this->headers = new Headers($headers);
      $this->cookies = new Cookies($_COOKIE);
   }

   /**
    * Detect GET method.
    *
    * @return bool
    */
   final public function isGet(): bool
   {
      return ($this->method == self::METHOD_GET);
   }

   /**
    * Detect POST method.
    *
    * @return bool
    */
   final public function isPost(): bool
   {
      return ($this->method == self::METHOD_POST);
   }

   /**
    * Detect PUT method.
    *
    * @return bool
    */
   final public function isPut(): bool
   {
      return ($this->method == self::METHOD_POST);
   }

   /**
    * Detect PATCH method.
    *
    * @return bool
    */
   final public function isPatch(): bool
   {
      return ($this->method == self::METHOD_PATCH);
   }

   /**
    * Detect DELETE method.
    *
    * @return bool
    */
   final public function isDelete(): bool
   {
      return ($this->method == self::METHOD_DELETE);
   }

   /**
    * Fix dotted param keys.
    *
    * SORRY RASMUS, SORRY ZEEV..
    * @see https://github.com/php/php-src/blob/master/main/php_variables.c#L93
    *
    * @param  string $name
    * @return array
    */
   final private function loadGlobalVar(string $name): array
   {
      $source = '';
      $return = [];

      switch ($name) {
         case 'GET':
            $source = dig($_SERVER, 'QUERY_STRING', '');
            break;
         case 'POST':
            $source = file_get_contents('php://input');
            break;
         case 'COOKIE':
            $source = implode('&', array_map('trim', explode(';', dig($_SERVER, 'HTTP_COOKIE', ''))));
            break;
      }

      // no var source?
      if (empty($source)) {
         return $return;
      }

      // hex keys
      $source = preg_replace_callback('~(^|(?<=&))[^=[&]+~', function($m) {
         return bin2hex(urldecode($m[0]));
      }, $source);

      // parse
      parse_str($source, $source);

      foreach($source as $key => $value) {
         // prevent strict_types error
         $key = hex2bin("{$key}");

         // not array
         if (strpos($key, '[') === false) {
            $return[$key] = $value;
            continue;
         }

         // handle arrays
         parse_str("{$key}={$value}", $value);

         $return = array_merge_recursive($return, $value);
      }

      return $return;
   }
}