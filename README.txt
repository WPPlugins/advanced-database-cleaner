=== Advanced Database Cleaner ===
Contributors: symptote
Donate Link: http://www.sigmaplugin.com/donation
Tags: plugin, plugins, plugin wordpress, wordpress, database cleaner, clean database, database clean, database, clean, clean-up, clean up, cleanup, cleaner, delete orphan data, orphan data, delete revisions, delete revision, revision, delete draft, draft, delete trash, trash, delete spam, spam, delete auto draft, auto draft, delete postmeta, postmeta, delete commentmeta, commentmeta, delete relationships, relationships, delete transient feed, transient feed, optimize database, database optimize, database optimizer, optimize, optimizer, reset database, database reset, reset, admin, widget, schedule, scheduler, schedule clean-up, schedule optimize, multisite, multi-site, network, cron, cron job, clean cron, clean scheduled tasks, view cron, view cron job, view scheduled tasks
Requires at least: 3.0.0
Tested up to: 4.3
Stable tag: 1.3.6
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
* View and clean cron (scheduled tasks) [optional]
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

1. Example of database not cleaned
2. Example of cleaned database
3. Example of tables not optimized
4. Example of optimized tables
5. "View & clean scheduled tasks" admin page screenshot
6. "Reset database" admin page screenshot

== Changelog ==

= 1.3.6 =
* Fixing a problem in donate button
* Using _e() and __() for all texts in the plugin

= 1.3.5 =
* New feature: Adding "Clean Cron". You can now clean unnecessary scheduled tasks.
* Updating FAQ

= 1.3.1 =
* Adding FAQ

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

== Frequently Asked Questions ==

= What does mean "clean my database"? =
As you use WordPress, your database accumulates a lot of extra data such as revisions, spam comments, trashed comments, etc. Removing this unnecessary data will reduce your database size, speeds up your backup process and speeds up your site also.

= Is it safe to clean my database? =
Yes, it is. We do not run any code that can break down your site or delete your posts, pages, comments, etc. However, we advise you to make a database backup before cleaning. It is always better to be safe than sorry.

= What does mean "Optimize my database"? =
Optimizing your database will reclaim unused space in your tables, which will reduce storage space and improve efficiency when accessing tables. Optimizing the database can sometimes significantly improve performance, particularly on sites that receive a lot of traffic or have a large amount of content. Optimizing your database is absolutely safe.

= Is it safe to clean the cron (scheduled tasks)? =
A scheduled task enables plugins to execute some actions at specified times, without having to manually execute code at that time. Wordpress itself uses some scheduled tasks to perform some regular actions. However, some scheduled tasks may not be removed even if the responsible plugins are deleted from your wordpress installation. As you know, not all plugins care about the housekeeping of your wordpress. Hence, deleting these unnecessary tasks may help in cleaning your site. It should be noted that cleaning scheduled tasks is safe as long as you know what tasks to clean. If your are not sure, it is better to not clean any task.

= Is this plugin compatible with multisite? =
Yes, it is compatible with multisite.

= What does mean "Revision"? What sql code is used to clean it? =
WordPress stores a record (called "revision") of each saved draft or published update. This system allows you to see what changes were made in each post and page over time. However, this can lead to a lot of unnecessary overhead in your WordPress database, which consumes a lot of space. The sql query used by the plugin to clean all revisions is:
`DELETE FROM posts WHERE post_type = 'revision'`

= What does mean "Draft"? What sql code is used to clean it? =
WordPress allows you to save a post or a page without having to publish it immediately. This way you can work on it as much as you want and publish it only when it is ready. This is called a Draft. Over time, you could have multiple drafts that you will never publish and hence you can clean them. The sql query used by the plugin to clean all drafts is:
`DELETE FROM posts WHERE post_status = 'draft'`

= What does mean "Auto-draft"? What sql code is used to clean it? =
Wordpress automatically saves your post/page while you are editing it. This is called an auto-draft. If you don't hit the publish/update button, then the post/page will be saved as auto-draft and any modification to your post/page will not be visible in your public site. Over time, you could have multiple auto-drafts that you will never publish and hence you can clean them. The sql query used by the plugin to clean all auto-drafts is:
`DELETE FROM posts WHERE post_status = 'auto-draft'`

= What does mean "Moderated comment"? What sql code is used to clean it? =
Moderated comments are comments published by users and which are awaiting for your approval before appearing in your site. In some cases, you will have to clean all these comments. The sql query used by the plugin to clean all moderated comments is:
`DELETE FROM comments WHERE comment_approved = '0'`

= What does mean "Spam comment"? What sql code is used to clean it? =
It is a comment that you (or a plugin) have marked as a spam. The sql query used by the plugin to clean all spam comments is:
`DELETE FROM comments WHERE comment_approved = 'spam'`

= What does mean "Trash comment"? What sql code is used to clean it? =
A trash comment is a comment that you have deleted from your Wordpress and have been moved to the trash. A trash comment is not visible in your site and should be deleted forever. The sql query used by the plugin to clean all trash comments is:
`DELETE FROM comments WHERE comment_approved = 'trash'`

= What does mean "Orphan post meta"? What sql code is used to clean it? =
The post meta data is the information you provide to viewers about each post. This information usually includes the author of the post, when it was written (or posted), and how the author categorized that particular post. In some cases, some post meta data information become orphan and do not belong to any post. They are then called "orphan postmeta" and should be cleaned since they are not useful. The sql query used by the plugin to clean all orphan postmeta is:
`DELETE pm FROM postmeta pm LEFT JOIN posts wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL`

= What does mean "Orphan comment meta"? What sql code is used to clean it? =
The same as "Orphan post meta" with the exception that "orphan comment meta" concern comments and not posts. The sql query used by the plugin to clean all orphan comment meta is:
`DELETE FROM commentmeta WHERE comment_id NOT IN (SELECT comment_id FROM comments)`

= What does mean "Orphan relationships"? What sql code is used to clean it? =
Sometimes the wp_term_relationships table becomes bloated with many orphaned relationships. This happens particularly often if you’re using your site not as a blog but as some other type of content site where posts are deleted periodically. Over time, you could get thousands of term relationships for posts that no longer exist which consumes a lot of database space. The sql query used by the plugin to clean all orphan relationships is:
`DELETE FROM term_relationships WHERE term_taxonomy_id=1 AND object_id NOT IN (SELECT id FROM posts)`

= What does mean "Dashboard transient feed"? What sql code is used to clean it? =
Transient are a way for storing cached data temporarily in your database by given it a name and a timeframe after which it will expire and be deleted. The sql query used by the plugin to clean dashboard transient feed is:
`DELETE FROM options WHERE option_name LIKE '_site_transient_browser_%' OR option_name LIKE '_site_transient_timeout_browser_%' OR option_name LIKE '_transient_feed_%' OR option_name LIKE '_transient_timeout_feed_%'`

= Does this plugin cleans itself after the uninstall? =
Yes, the plugin cleans itself and removes any data used to store its settings.