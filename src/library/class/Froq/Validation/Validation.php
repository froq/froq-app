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

namespace Froq\Validation;

/**
 * @package    Froq
 * @subpackage Froq\Validation
 * @object     Froq\Validation\Validation
 * @author     Kerem Güneş <k-gun@mail.com>
 */
final class Validation
{
   /**
    * Types.
    * @const string
    */
   const TYPE_INT           = 'int',
         TYPE_FLOAT         = 'float',
         TYPE_STRING        = 'string',
         TYPE_NUMERIC       = 'numeric',
         TYPE_BOOL          = 'bool',
         TYPE_ENUM          = 'enum',
         TYPE_EMAIL         = 'email',
         TYPE_DATE          = 'date',
         TYPE_DATETIME      = 'datetime';

   // @todo Replace invalid characters.
   const ENCODING           = ['ascii', 'unicode'];

   /**
    * Rules.
    * @var array
    */
   private $rules = [];

   /**
    * Fails.
    * @var array
    */
   private $fails = [];

   /**
    * Constructor.
    *
    * @param array|null $rules
    */
   final public function __construct(array $rules = null)
   {
      if (!empty($rules)) {
         $this->setRules($rules);
      }
   }

   /**
    * Validate.
    *
    * @param  array &$data  This will overwrite sanitizing input data.
    * @param  array &$fails Shortcut instead of to call self::getFails().
    * @return bool
    */
   final public function validate(array &$data, &$fails = []): bool
   {
      // no data to validate
      if (empty($data)) {
         return false;
      }

      // drop undefined post data
      $keys = array_keys(array_intersect_key($this->rules, $data));
      foreach ($data as $key => $value) {
         if (!in_array($key, $keys)) {
            unset($data[$key]);
         }
      }

      foreach ($this->rules as $rule) {
         $fieldName = $rule->fieldName;
         $fieldValue = (string) $data[$fieldName];

         // real check here sanitizing/overwriting input data
         if (!$rule->ok($fieldValue)) {
            $fails[$fieldName] = $rule->fail;
         }

         // overwrite
         $data[$fieldName] = $fieldValue;
      }

      // store for later
      $this->setFails($fails);

      return empty($fails);
   }

   /**
    * Set rules.
    *
    * @note   This method could be used in "service::init" method
    * in order to set (overwrite) its values after getting from db etc.
    * @param  array $rules
    * @return self
    */
   final public function setRules(array $rules): self
   {
      foreach ($rules as $fieldName => $fieldOptions) {
         $this->rules[$fieldName] = new ValidationRule($fieldName, $fieldOptions);
      }

      return $this;
   }

   /**
    * Get rules.
    *
    * @return array
    */
   final public function getRules(): array
   {
      return $this->rules;
   }

   /**
    * Set fails.
    *
    * @param  array $fails
    * @return self
    */
   final public function setFails(array $fails = null): self
   {
      if (!empty($fails)) {
         foreach ($fails as $fieldName => $fail) {
            $this->fails[$fieldName] = $fail;
         }
      }

      return $this;
   }

   /**
    * Get fails.
    *
    * @return array
    */
   final public function getFails(): array
   {
      return $this->fails;
   }

   /**
    * Map (input) data array.
    *
    * @param  array    $data
    * @param  callable $callback
    * @return array
    */
   final public static function mapData(array $data, callable $callback): array
   {
      foreach ($data as $key => &$value) {
         $value = $callback($value);
      }

      return $data;
   }
}