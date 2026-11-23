<?php

declare (strict_types=1);
namespace ADBC\Vendor\PhpMyAdmin\SqlParser\Statements;

use ADBC\Vendor\PhpMyAdmin\SqlParser\Components\Expression;
use ADBC\Vendor\PhpMyAdmin\SqlParser\Statement;
/**
 * `OPTIMIZE` statement.
 *
 * OPTIMIZE [NO_WRITE_TO_BINLOG | LOCAL] TABLE
 *  tbl_name [, tbl_name] ...
 */
class OptimizeStatement extends Statement
{
    /**
     * Options of this statement.
     *
     * @var array<string, int|array<int, int|string>>
     * @psalm-var array<string, (positive-int|array{positive-int, ('var'|'var='|'expr'|'expr=')})>
     */
    public static array $statementOptions = ['TABLE' => 1, 'NO_WRITE_TO_BINLOG' => 2, 'LOCAL' => 3];
    /**
     * Optimized tables.
     *
     * @var Expression[]|null
     */
    public array|null $tables = null;
}
