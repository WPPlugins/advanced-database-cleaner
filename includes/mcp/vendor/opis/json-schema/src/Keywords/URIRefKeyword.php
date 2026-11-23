<?php

/* ============================================================================
 * Copyright 2020 Zindex Software
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * ============================================================================ */
namespace ADBC\Vendor\Opis\JsonSchema\Keywords;

use ADBC\Vendor\Opis\JsonSchema\Errors\ValidationError;
use ADBC\Vendor\Opis\JsonSchema\Exceptions\UnresolvedReferenceException;
use ADBC\Vendor\Opis\JsonSchema\Schema;
use ADBC\Vendor\Opis\JsonSchema\Uri;
use ADBC\Vendor\Opis\JsonSchema\ValidationContext;
use ADBC\Vendor\Opis\JsonSchema\Variables;
class URIRefKeyword extends AbstractRefKeyword
{
    protected Uri $uri;
    /** @var bool|null|Schema */
    protected $resolved = \false;
    public function __construct(Uri $uri, ?Variables $mapper, ?Variables $globals, ?array $slots = null, string $keyword = '$ref')
    {
        parent::__construct($mapper, $globals, $slots, $keyword);
        $this->uri = $uri;
    }
    protected function doValidate(ValidationContext $context, Schema $schema): ?ValidationError
    {
        if ($this->resolved === \false) {
            $this->resolved = $context->loader()->loadSchemaById($this->uri);
        }
        if ($this->resolved === null) {
            throw new UnresolvedReferenceException((string) $this->uri, $schema, $context);
        }
        $this->setLastRefSchema($this->resolved);
        return $this->resolved->validate($this->createContext($context, $schema));
    }
}
