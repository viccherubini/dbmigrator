Clone it into your projects directory (or whever you store source, mine is in ~/projects/):
git clone git://github.com/leftnode/dbmigrator.git

Link it to your $PATH and PHP Include Paths:
cd /usr/local/bin/
sudo ln -s ~/projects/dbmigrator/dbmigrator

cd /usr/share/php
sudo ln -s ~/projects/dbmigrator dbmigrator

Create a new mysql database named 'dbmigrator' and leave it empty.

Go to ~/projects/dbmigrator
Copy the dbmigrator.config.template.php file to dbmigrator.config.php

Open it up in your editor
Change the DB_HOST, DB_NAME, DB_USER, and DB_PASSWORD constants to match your mysql server. Use 'dbmigrator' as the DB_NAME constant since thats the database you just created.

Create a few new migration scripts (doesn't matter what directory you're in, but to make things easy, be in the ~/projects/dbmigrator
$ dbmigrator create create-table-a create-table-b create-table-c

They will be created in the ~/projects/dbmigrator/sql/ directory as <timestamp>-create-table-a to <timestamp>-create-table-c

They are each small PHP classes that are immediately instantiated when included in a script. Open each up in your editor. Notice because they are idenitifed as CREATE TABLE scripts, the DROP TABLE code is already in the tear_down() method.

In the set_up() method for each, add the DDL to create tables a, b, and c:

CREATE TABLE `a` (`a_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE = InnoDB CHARACTER SET utf8 COLLATE utf8_unicode_ci;
CREATE TABLE `b` (`b_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE = InnoDB CHARACTER SET utf8 COLLATE utf8_unicode_ci;
CREATE TABLE `c` (`c_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE = MYISAM CHARACTER SET armscii8 COLLATE armscii8_bin;

Save all of the files and go back to the shell. Update your database to the latest version:

$ dbmigrator update

Navigate to phpmyadmin or however you see your database and notice the tables (and a special table, _schema_changelog) are all there.

Now roll all of those changes back:

$ dbmigrator rollback

Navigating back to the database, it is empty except _schema_changelog (which has 0 rows now).

Create a snapshot of the first three migrations. A snapshot is a grouping of migrations. A snapshot automatically includes all migrations not currently in a snapshot. Since this is the first snapshot, it will include all migrations. The next snapshot you make will contain all migrations made between this snapshot and that one.

$ dbmigrator snapshot default-schema

Now update to that snapshot:

$ dbmigrator update default-schema

Now rollback that snapshot:

$ dbmigrator rollback default-schema

Create a few more migrations to insert some default data:

$ dbmigrator create data1 data2 data3

Open them up and set the following for each of their set_up() and tear_down() methods:

INSERT INTO a(NULL); -- set_up() for data1
TRUNCATE TABLE a;    -- tear_down() for data1

INSERT INTO b(NULL); -- set_up() for data2
TRUNCATE TABLE b;    -- tear_down() for data2

INSERT INTO c(NULL); -- set_up() for data3
TRUNCATE TABLE c;    -- tear_down() for data3

Now make a snapshot of those migrations:

$ dbmigrator snapshot default-data

And update to that snapshot:

$ dbmigrator update default-data

And finally, to rollback that snapshot:

$ dbmigrator rollback default-data

I'll add error handling and other stuff as time goes on, but it's pretty powerful to start.
