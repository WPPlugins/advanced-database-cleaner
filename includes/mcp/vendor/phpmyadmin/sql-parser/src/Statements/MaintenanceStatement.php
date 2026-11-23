<?php

declare (strict_types=1);
namespace ADBC\Vendor\PhpMyAdmin\SqlParser\Statements;

use ADBC\Vendor\PhpMyAdmin\SqlParser\Components\Expression;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Parser;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Parsers\OptionsArrays;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Statement;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Token;
use ADBC\Vendor\PhpMyAdmin\SqlParser\TokensList;
/**
 * Maintenance statement.
 *
 * They follow the syntax:
 *     STMT [some options] tbl_name [, tbl_name] ... [some more options]
 */
class MaintenanceStatement extends Statement
{
    /**
     * Tables maintained.
     *
     * @var Expression[]|null
     */
    public array|null $tables = null;
    /**
     * Function called after the token was processed.
     *
     * Parses the additional options from the end.
     *
     * @param Parser     $parser the instance that requests parsing
     * @param TokensList $list   the list of tokens to be parsed
     * @param Token      $token  the token that is being parsed
     */
    public function after(Parser $parser, TokensList $list, Token $token): void
    {
        // [some options] is going to be parsed first.
        //
        // There is a parser specified in `Parser::KEYWORD_PARSERS`
        // which parses the name of the tables.
        //
        // Finally, we parse here [some more options] and that's all.
        ++$list->idx;
        $this->options->merge(OptionsArrays::parse($parser, $list, static::$statementOptions));
    }
}
