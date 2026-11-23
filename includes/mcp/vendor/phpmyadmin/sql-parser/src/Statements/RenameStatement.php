<?php

declare (strict_types=1);
namespace ADBC\Vendor\PhpMyAdmin\SqlParser\Statements;

use ADBC\Vendor\PhpMyAdmin\SqlParser\Components\RenameOperation;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Parser;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Parsers\RenameOperations;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Statement;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Token;
use ADBC\Vendor\PhpMyAdmin\SqlParser\TokensList;
use ADBC\Vendor\PhpMyAdmin\SqlParser\TokenType;
/**
 * `RENAME` statement.
 *
 * RENAME TABLE tbl_name TO new_tbl_name
 *  [, tbl_name2 TO new_tbl_name2] ...
 */
class RenameStatement extends Statement
{
    /**
     * The old and new names of the tables.
     *
     * @var RenameOperation[]|null
     */
    public array|null $renames = null;
    /**
     * Function called before the token is processed.
     *
     * Skips the `TABLE` keyword after `RENAME`.
     *
     * @param Parser     $parser the instance that requests parsing
     * @param TokensList $list   the list of tokens to be parsed
     * @param Token      $token  the token that is being parsed
     */
    public function before(Parser $parser, TokensList $list, Token $token): void
    {
        if ($token->type !== TokenType::Keyword || $token->keyword !== 'RENAME') {
            return;
        }
        // Checking if it is the beginning of the query.
        $list->getNextOfTypeAndValue(TokenType::Keyword, 'TABLE');
    }
    public function build(): string
    {
        return 'RENAME TABLE ' . RenameOperations::buildAll($this->renames);
    }
}
