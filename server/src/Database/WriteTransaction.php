<?php

declare(strict_types=1);

namespace Matsvep\Database;

use PDO;
use Throwable;

final class WriteTransaction
{
    /** @template T
     *  @param callable():T $action
     *  @return T */
    public static function run(PDO $db, callable $action): mixed
    {
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        try {
            $result = $action();
            if ($ownsTransaction) {
                $db->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
}
