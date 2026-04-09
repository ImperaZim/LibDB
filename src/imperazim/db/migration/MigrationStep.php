<?php

declare(strict_types = 1);

namespace imperazim\db\migration;

use imperazim\db\Database;

/**
* Base class for individual migration steps.
*
* Usage:
*   class CreateUsersTable extends MigrationStep {
*       public function up(Database $db): void {
*           $db->createTableIfNotExists(['users' => [...]]);
*       }
*       public function down(Database $db): void {
*           $db->query('DROP TABLE IF EXISTS `users`');
*       }
*   }
*/
abstract class MigrationStep {

    /**
    * Applies the migration.
    *
    * @param Database $db Database instance
    */
    abstract public function up(Database $db): void;

    /**
    * Reverts the migration.
    *
    * @param Database $db Database instance
    */
    abstract public function down(Database $db): void;
}
