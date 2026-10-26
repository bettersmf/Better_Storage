<?php
if (!defined('SMF')) die('No direct access.');
global $smcFunc;
db_extend('packages');
$smcFunc['db_query']('', 'DROP TABLE IF EXISTS {db_prefix}better_storage_objects', array());
$smcFunc['db_remove_column']('{db_prefix}attachments', 'better_storage_backend');
$smcFunc['db_remove_column']('{db_prefix}attachments', 'better_storage_key');
$keys=array('betterstorage_installed','betterstorage_backend','betterstorage_s3_region','betterstorage_s3_bucket','betterstorage_s3_prefix','betterstorage_s3_access_key','betterstorage_s3_secret_key','betterstorage_s3_endpoint','betterstorage_s3_private','betterstorage_prefix_gallery','betterstorage_prefix_downloads','betterstorage_prefix_attachments','betterstorage_prefix_pm','betterstorage_prefix_chat','betterstorage_integration_gallery','betterstorage_integration_downloads','betterstorage_integration_attachments','betterstorage_integration_pm','betterstorage_integration_chat','betterstorage_backend_gallery','betterstorage_backend_downloads','betterstorage_backend_attachments','betterstorage_backend_pm','betterstorage_backend_chat');
$smcFunc['db_query']('', 'DELETE FROM {db_prefix}settings WHERE variable IN ({array_string:keys})', array('keys'=>$keys));
