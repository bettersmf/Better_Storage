<?php
if (!defined('SMF')) die('No direct access.');
global $sourcedir,$smcFunc;
require_once($sourcedir.'/Subs.php');
db_extend('packages');
add_integration_function('integrate_admin_areas','betterstorage_integrate_admin_areas',true,'$sourcedir/BetterStorage.php');
add_integration_function('integrate_assign_attachments','betterstorage_integrate_assign_attachments',true,'$sourcedir/BetterStorage.php');
add_integration_function('integrate_remove_attachments','betterstorage_integrate_remove_attachments',true,'$sourcedir/BetterStorage.php');
add_integration_function('integrate_pre_download_request','betterstorage_pre_download_request',true,'$sourcedir/BetterStorage.php');
// Mark Better Storage active on every install/upgrade, including upgrades from early dev builds.
updateSettings(array('betterstorage_installed'=>1));
$columns=array(
 array('name'=>'source','type'=>'varchar','size'=>32,'default'=>''),
 array('name'=>'backend','type'=>'varchar','size'=>16,'default'=>'local'),
 array('name'=>'object_key','type'=>'varchar','size'=>768,'default'=>''),
 array('name'=>'file_size','type'=>'bigint','size'=>20,'unsigned'=>true,'default'=>0),
 array('name'=>'mime_type','type'=>'varchar','size'=>128,'default'=>''),
 array('name'=>'id_owner','type'=>'mediumint','size'=>8,'unsigned'=>true,'default'=>0),
 array('name'=>'created_at','type'=>'int','size'=>10,'unsigned'=>true,'default'=>0),
);
$indexes=array(array('type'=>'primary','columns'=>array('source','backend','object_key')),array('type'=>'index','columns'=>array('source','backend')));
$smcFunc['db_create_table']('{db_prefix}better_storage_objects',$columns,$indexes,array(),'ignore');

// Storage reference used by native SMF adapters (forum attachments first).
$smcFunc['db_add_column']('{db_prefix}better_storage_objects', array('name'=>'id_reference','type'=>'int','size'=>10,'unsigned'=>true,'default'=>0), array(), 'ignore');
$smcFunc['db_add_column']('{db_prefix}better_storage_objects', array('name'=>'object_role','type'=>'varchar','size'=>16,'default'=>'primary'), array(), 'ignore');

// Classify obvious derived assets already in the registry. This changes reporting only; no files are moved.
$smcFunc['db_query']('', '
	UPDATE {db_prefix}better_storage_objects
	SET object_role = {string:derived}
	WHERE object_key LIKE {string:thumb_prefix}
		OR object_key LIKE {string:thumb_path}
		OR object_key LIKE {string:thumbnail_path}
		OR object_key LIKE {string:preview_path}
		OR object_key LIKE {string:thumb_suffix}', array(
	'derived' => 'derived',
	'thumb_prefix' => 'thumb\_%',
	'thumb_path' => '%/thumb\_%',
	'thumbnail_path' => '%/thumbnails/%',
	'preview_path' => '%/previews/%',
	'thumb_suffix' => '%\_thumb.%',
));
$smcFunc['db_add_column']('{db_prefix}attachments', array('name'=>'better_storage_backend','type'=>'varchar','size'=>16,'default'=>'local'), array(), 'ignore');
$smcFunc['db_add_column']('{db_prefix}attachments', array('name'=>'better_storage_key','type'=>'varchar','size'=>512,'default'=>''), array(), 'ignore');
updateSettings(array('betterstorage_integration_attachments'=>1));
$defaults=array('betterstorage_backend'=>'local','betterstorage_s3_region'=>'us-east-1','betterstorage_s3_private'=>1,'betterstorage_prefix_gallery'=>'better-gallery','betterstorage_prefix_downloads'=>'better-downloads','betterstorage_prefix_attachments'=>'forum-attachments','betterstorage_prefix_pm'=>'pm-attachments','betterstorage_prefix_chat'=>'better-chat');
foreach($defaults as $k=>$v) if (!isset($GLOBALS['modSettings'][$k])) updateSettings(array($k=>$v));

// Per-source routing. Preserve Gallery's proven legacy route, while new integrations
// begin on Local server until the administrator explicitly routes them to S3.
$current_backend = !empty($GLOBALS['modSettings']['betterstorage_backend']) && $GLOBALS['modSettings']['betterstorage_backend'] === 's3' ? 's3' : 'local';
$route_defaults = array(
	'betterstorage_backend_gallery' => $current_backend,
	'betterstorage_backend_downloads' => 'local',
	'betterstorage_backend_attachments' => 'local',
	'betterstorage_backend_pm' => 'local',
	'betterstorage_backend_chat' => 'local',
);
foreach ($route_defaults as $k => $v)
	if (!isset($GLOBALS['modSettings'][$k]))
		updateSettings(array($k => $v));
