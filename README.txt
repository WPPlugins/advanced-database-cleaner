=== Advanced Database Cleaner ===
Contributors: symptote
Donate Link: https://www.paypal.com/us/cgi-bin/webscr?cmd=_flow&SESSION=Azq_P8u-Dy9AZSB3bmJFsrGWImHWiCoHtSE8KuC2SNdGQpreQxG8dl2tVZK&dispatch=5885d80a13c0db1f8e263663d3faee8d0b9dcb01a9b6dc564e45f62871326a5e
Tags: plugin, plugins, plugin wordpress, wordpress, database cleaner, clean database, database clean, database, clean, cleaner, delete orphan data, orphan data, delete revisions, revision, delete draft, draft, delete trash, trash, delete spam, spam, delete auto draft, auto draft, delete postmeta, postmeta, delete commentmeta, commentmeta, delete relationships, relationships, delete transient feed, transient feed, optimize database, database optimize, database optimizer, optimize, optimizer, reset database, database reset, reset, admin, widget, schedule, scheduler, schedule clean-up, schedule optimize, multisite, multi-site, network
Requires at least: 3.0.0
Tested up to: 4.3
Stable tag: 1.3.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Clean up your database by deleting unused data such as 'revision', optimize your database, or reset it to its initial state, etc.

== Description ==

Advanced Database Cleaner is a must-have plugin that cleans and optimizes your WP database.

= Main Features =
* Removes unused data such as "revision", "draft", "auto draft", "moderated comments", "spam comments", "trash comments", "orphan postmeta", "orphan commentmeta", "orphan relationships", "dashboard transient feed" [optional]
* Optimizes your Wordpress database. This will help you reducing storage space and improving efficiency when accessing your tables [optional]
* Resets your database back to its original state. This will help you making a fresh installation and skip the "5 minutes wordpress installation steps" [optional]
* Allows you choose what data should be cleaned and what data should not [optional]
* Schedules the clean-up to run automatically hourly, twice a day, daily, weekly or monthly [optional]
* Schedules the optimization of database to run automatically hourly, twice a day, daily, weekly or monthly [optional]
* Supports multisite installation

= Multisite Support =
* The plugin supports multisite wordpress installations
* You can 'Network Activate' the plugin or activate it in each site separately
* The main site can clean and optimize all tables for all sites in the network
* Other sites in the network can only clean and optimize their own tables in the database
* The reset of the database can be executed from the main site only (we believe that your database is precious)

== Installation ==

This section describes how to install the plugin and get it working.

= Single site installation =
* After extraction, upload the Plugin to your `/wp-content/plugins/` directory
* Go to "Dashboard" &raquo; "Plugins" and choose 'Activate'
* The plugin page can be accessed via "Dashboard" &raquo; "Settings" &raquo; "Database Cleaner"

= Multisite installation =
* Login to your primary site and go to "My Sites" &raquo; "Network Admin" &raquo; "Plugins"
* Install the plugin as usual for multisite
* 'Network Activate' the plugin in "My Sites" &raquo; "Network Admin" &raquo; "Plugins" or let each site activate it separately

== Screenshots ==

1. Example of dirty database
2. Example of cleaned database
3. Example of dirty tables
4. Example of optimized tables
5. Reset database" admin page screenshot

== Changelog ==

= 1.3.0 =
* Some code optimizations
* New feature: Support multisite. You can now clean and optimize your database in multisite installation.

= 1.2.3 =
* Some optimizations and style modifications
* New feature: Adding the scheduler. You can now schedule the clean-up and optimization of your database.

= 1.2.2 =
* Some optimizations and style modifications

= 1.2.1 =
* Some optimizations and style modifications
* "Clean database" tab shows now only elements that should be cleaned instead of listing all elements.
* "Clean database" tab shows now an icon that indicates the state of your database.
* "Optimize database" tab shows now only tables that should be optimized instead of listing all tables.
* "Optimize database" tab shows now an icon that indicates the state of your tables.
* "Reset database" shows now a warning before resetting the database.

= 1.2.0 =
* Some optimizations and style modifications
* New feature: Adding "Reset database"

= 1.1.1 =
* Some optimizations and style modifications
* Adding "Donate link"

= 1.1.0 =
* Some optimizations and style modifications
* New feature: Adding "Optimize Database"

= 1.0.0 =
* First release: Hello world!
