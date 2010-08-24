# DbMigrator
Versioning databases is a pain in the ass. DbMigrator is here to help ease that pain. Let's see how that'll work.

# Setup
1. Clone this repository into your PHP __include_path__.
2. Copy DbMigrator.Config.Template.php to DbMigrator.Config.php in the DbMigrator directory. This step is only required if you do not have project specific DbMigrator.Config.php files.
3. Link the `dbmigrator` script to your /usr/local/bin path:
	
	`cd /usr/local/bin
	
	ln -s /usr/share/php/DbMigrator/dbmigrator dbmigrator`
	
4. Navigate to the root directory of your project.
	
	`cd ~/Projects/My-Project`
	
5. Create a directory named __build__ with a subdirectory named __db__.

	`mkdir build/db -p`
	
	The directory __build__ will contain your project specific DbMigrator.Config.php file and __db__ will contain all of your build scripts.
6. Copy DbMigrator.Config.Template.php from where you cloned the repository to the local __build__ directory as DbMigrator.Config.php.
	
	`cp /usr/share/php/DbMigrator/DbMigrator.Config.Template.php DbMigrator.Config.php`
	
7. Open up the new DbMigrator.Config.php file and edit the configuration constants. The `MIGRATION_PATH` constant should be set to:
	
	`/full/path/to/project/build/db`
	
8. You're all set up now!

# Migration Script Creation
Using DbMigrator is very simple. Generally, you'll execute everything from the `dbmigrator` command line program within your project directory. By doing so, it will automatically look in `./build/db/` for DbMigrator.Config.php to get local configuration information. If it can not be found there, `dbmigrator` will look for the DbMigrator.Config.php file in the PHP `include_path` in __DbMigrator__.

The `dbmigrator` program has two options: _create_ and _update_.

The _create_ option allows you to create empty migration scripts. Each migration script is a small PHP class that contains two methods, __setUp__ and __tearDown__, each of which returns an empty string. Each method will need to be updated to return a valid SQL query (or queries, if your database driver can support multiple queries executed at once). When updating to a new version of the database, the __setUp__ method is called. When rolling back to a previous version of the database, the __tearDown__ method is called.

You must create at least one script, but can create multiple scripts in one command. The following are valid commands:

	dbmigrator create create-table-users
	dbmigrator create create-table-users create-table-products insert-default-data

The second command will create three empty migration scripts.

The _create_ method also has a few other niceties. If you create a script with the prefix __create-table__ followed by a table name, the __tearDown__ method will automatically have the code to drop that table. Thus calling:

	dbmigrator create create-table-users-to-products

will produce a __tearDown__ method with the query 
	
	DROP TABLE users_to_products;

You can use either dashes or underscores in the table name as well. Calling the following is identical:

	dbmigrator create create-table-users-to-products
	dbmigrator create create-table-users_to_products

This also works if you create a script with the prefix __create-database__.

Finally, you do not need to worry about prefixing your scripts with the latest version. DbMigrator will handle that for you. If you run

	dbmigrator create 1-create-table-users
	
you'll end up with a script named 1-1-create-table-users-<hash>.php (if it were the first script, for example).

# Database Migration Usage
Now that you can easily create new migration scripts, it's time to actually migrate! DbMigrator works similar to a linear delineated timeline version control system (like Subversion). Each new migration script is a new numerical version in the timeline. This makes it easy to update or rollback to any version.

DbMigrator is smart enough to determine if you're updating to a new version or rolling back to a previous version. Thus, if your database is currently at version 52, and you have migration scripts on disk numbering to version 73, calling:

	dbmigrate update 70

will update the database to version 70. Calling

	dbmigrate update 23

will rollback the database to version 23. Finally, calling _update_ with no arguments updates to the HEAD (or latest) version:

	dbmigrate update

