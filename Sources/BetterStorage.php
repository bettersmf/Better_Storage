<?php

if (!defined('SMF'))
	die('No direct access.');

/**
 * Better Storage shared backend helpers.
 * Phase 1: local/S3 configuration and signed S3 connectivity checks.
 * Designed for reuse by Better Gallery and Better Downloads.
 */

function betterstorage_s3_normalize_prefix($prefix)
{
	$prefix = trim(str_replace('\\', '/', (string) $prefix), '/ ');
	return $prefix === '' ? '' : $prefix . '/';
}

function betterstorage_s3_host($bucket, $region, $endpoint = '')
{
	$bucket = trim((string) $bucket);
	$region = trim((string) $region) ?: 'us-east-1';
	$endpoint = trim((string) $endpoint);

	if ($endpoint !== '')
	{
		$endpoint = preg_replace('~^https?://~i', '', rtrim($endpoint, '/'));
		return $bucket !== '' ? $bucket . '.' . $endpoint : $endpoint;
	}

	return $bucket . '.s3.' . $region . '.amazonaws.com';
}

function betterstorage_s3_signing_key($secret, $date, $region, $service = 's3')
{
	$k_date = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
	$k_region = hash_hmac('sha256', $region, $k_date, true);
	$k_service = hash_hmac('sha256', $service, $k_region, true);
	return hash_hmac('sha256', 'aws4_request', $k_service, true);
}

function betterstorage_s3_request($method, $bucket, $region, $access_key, $secret_key, $path = '/', $query = '', $endpoint = '', $body = '', $extra_headers = array())
{
	$method = strtoupper((string) $method);
	$region = trim((string) $region) ?: 'us-east-1';
	$bucket = trim((string) $bucket);
	$access_key = trim((string) $access_key);
	$secret_key = trim((string) $secret_key);

	$missing = array();
	if ($bucket === '')
		$missing[] = 'bucket name';
	if ($access_key === '')
		$missing[] = 'access key ID';
	if ($secret_key === '')
		$missing[] = 'secret access key';

	if (!empty($missing))
		return array('ok' => false, 'status' => 0, 'error' => 'Missing S3 setting(s): ' . implode(', ', $missing) . '.', 'body' => '');

	$host = betterstorage_s3_host($bucket, $region, $endpoint);

	if (!function_exists('curl_init'))
		return array('ok' => false, 'status' => 0, 'error' => 'PHP cURL is required for Amazon S3.', 'body' => '');

	$path = '/' . ltrim((string) $path, '/');
	$encoded_path = implode('/', array_map('rawurlencode', explode('/', $path)));
	if ($encoded_path === '')
		$encoded_path = '/';
	elseif ($encoded_path[0] !== '/')
		$encoded_path = '/' . $encoded_path;
	$encoded_path = str_replace('%2F', '/', $encoded_path);

	$amz_date = gmdate('Ymd\\THis\\Z');
	$date_stamp = gmdate('Ymd');
	$payload_hash = hash('sha256', (string) $body);

	$headers = array(
		'host' => $host,
		'x-amz-content-sha256' => $payload_hash,
		'x-amz-date' => $amz_date,
	);
	foreach ($extra_headers as $name => $value)
		$headers[strtolower(trim((string) $name))] = trim((string) $value);
	ksort($headers);

	$canonical_headers = '';
	foreach ($headers as $name => $value)
		$canonical_headers .= $name . ':' . preg_replace('/\\s+/', ' ', $value) . "\n";
	$signed_headers = implode(';', array_keys($headers));

	$canonical_request = $method . "\n" . $encoded_path . "\n" . (string) $query . "\n" . $canonical_headers . "\n" . $signed_headers . "\n" . $payload_hash;
	$scope = $date_stamp . '/' . $region . '/s3/aws4_request';
	$string_to_sign = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash('sha256', $canonical_request);
	$signature = hash_hmac('sha256', $string_to_sign, betterstorage_s3_signing_key($secret_key, $date_stamp, $region));
	$authorization = 'AWS4-HMAC-SHA256 Credential=' . trim((string) $access_key) . '/' . $scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;

	$url = 'https://' . $host . $encoded_path . ($query !== '' ? '?' . $query : '');
	$curl_headers = array('Authorization: ' . $authorization);
	foreach ($headers as $name => $value)
		$curl_headers[] = $name . ': ' . $value;

	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_HTTPHEADER => $curl_headers,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER => true,
		CURLOPT_CONNECTTIMEOUT => 8,
		CURLOPT_TIMEOUT => 20,
		CURLOPT_FOLLOWLOCATION => false,
	));
	if ($method !== 'GET' && $method !== 'HEAD' && $body !== '')
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
	if ($method === 'HEAD')
		curl_setopt($ch, CURLOPT_NOBODY, true);

	$response = curl_exec($ch);
	$curl_error = curl_error($ch);
	$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$header_size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close($ch);

	$response_headers = is_string($response) ? substr($response, 0, $header_size) : '';
	$response_body = is_string($response) ? substr($response, $header_size) : '';
	$ok = $curl_error === '' && $status >= 200 && $status < 300;

	$error = '';
	if (!$ok)
	{
		if ($curl_error !== '')
			$error = $curl_error;
		elseif (preg_match('~<Code>([^<]+)</Code>~', $response_body, $m))
			$error = 'AWS ' . $m[1] . ' (HTTP ' . $status . ')';
		else
			$error = 'Amazon S3 returned HTTP ' . $status . '.';
	}

	return array('ok' => $ok, 'status' => $status, 'error' => $error, 'body' => $response_body, 'headers' => $response_headers);
}

function betterstorage_s3_test($config)
{
	$config = is_array($config) ? $config : array();
	$result = betterstorage_s3_request(
		'HEAD',
		$config['bucket'] ?? '',
		$config['region'] ?? 'us-east-1',
		$config['access_key'] ?? '',
		$config['secret_key'] ?? '',
		'/',
		'',
		$config['endpoint'] ?? ''
	);

	if (!empty($result['ok']))
		$result['message'] = 'Connection successful. Better Storage can reach and authenticate with this S3 bucket.';
	else
		$result['message'] = $result['error'];

	return $result;
}


/** Build the complete object key used inside an S3 bucket. */
function betterstorage_s3_object_key($prefix, $relative)
{
    $prefix = betterstorage_s3_normalize_prefix($prefix);
    $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
    return $prefix . $relative;
}

/** Upload a local file to S3 without loading the entire file into PHP memory. */
function betterstorage_s3_put_file($config, $relative_key, $local_path, $content_type = 'application/octet-stream')
{
    $config = is_array($config) ? $config : array();
    if (!is_file($local_path) || !is_readable($local_path))
        return array('ok'=>false, 'status'=>0, 'error'=>'The local file could not be read for S3 upload.');

    $bucket = trim((string) ($config['bucket'] ?? ''));
    $region = trim((string) ($config['region'] ?? 'us-east-1')) ?: 'us-east-1';
    $access = trim((string) ($config['access_key'] ?? ''));
    $secret = trim((string) ($config['secret_key'] ?? ''));
    $endpoint = trim((string) ($config['endpoint'] ?? ''));
    if ($bucket === '' || $access === '' || $secret === '')
        return array('ok'=>false, 'status'=>0, 'error'=>'S3 is not fully configured.');
    if (!function_exists('curl_init'))
        return array('ok'=>false, 'status'=>0, 'error'=>'PHP cURL is required for Amazon S3.');

    $object_key = betterstorage_s3_object_key($config['prefix'] ?? '', $relative_key);
    $path = '/' . ltrim($object_key, '/');
    $encoded_path = implode('/', array_map('rawurlencode', explode('/', $path)));
    $encoded_path = str_replace('%2F', '/', $encoded_path);
    if ($encoded_path === '' || $encoded_path[0] !== '/') $encoded_path = '/' . $encoded_path;
    $host = betterstorage_s3_host($bucket, $region, $endpoint);
    $amz_date = gmdate('Ymd\\THis\\Z');
    $date_stamp = gmdate('Ymd');
    $payload_hash = hash_file('sha256', $local_path);
    $headers = array(
        'content-type' => (string) $content_type,
        'host' => $host,
        'x-amz-content-sha256' => $payload_hash,
        'x-amz-date' => $amz_date,
    );
    ksort($headers);
    $canonical_headers='';
    foreach ($headers as $name=>$value) $canonical_headers .= $name . ':' . preg_replace('/\\s+/', ' ', trim($value)) . "\n";
    $signed_headers=implode(';', array_keys($headers));
    $canonical_request="PUT\n".$encoded_path."\n\n".$canonical_headers."\n".$signed_headers."\n".$payload_hash;
    $scope=$date_stamp.'/'.$region.'/s3/aws4_request';
    $string_to_sign="AWS4-HMAC-SHA256\n".$amz_date."\n".$scope."\n".hash('sha256',$canonical_request);
    $signature=hash_hmac('sha256',$string_to_sign,betterstorage_s3_signing_key($secret,$date_stamp,$region));
    $authorization='AWS4-HMAC-SHA256 Credential='.$access.'/'.$scope.', SignedHeaders='.$signed_headers.', Signature='.$signature;
    $url='https://'.$host.$encoded_path;
    $curl_headers=array('Authorization: '.$authorization);
    foreach($headers as $name=>$value) $curl_headers[]=$name.': '.$value;
    $fp=fopen($local_path,'rb');
    if (!$fp) return array('ok'=>false,'status'=>0,'error'=>'The local file could not be opened for S3 upload.');
    $ch=curl_init($url);
    curl_setopt_array($ch,array(
        CURLOPT_UPLOAD=>true,
        CURLOPT_INFILE=>$fp,
        CURLOPT_INFILESIZE=>(int) filesize($local_path),
        CURLOPT_HTTPHEADER=>$curl_headers,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HEADER=>true,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>0,
        CURLOPT_FOLLOWLOCATION=>false,
    ));
    $response=curl_exec($ch);
    $error=curl_error($ch);
    $status=(int) curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch); fclose($fp);
    $ok=$error==='' && $status>=200 && $status<300;
    return array('ok'=>$ok,'status'=>$status,'error'=>$ok?'':($error!==''?$error:'Amazon S3 returned HTTP '.$status.'.'),'key'=>$object_key,'response'=>$response);
}

function betterstorage_s3_delete($config, $relative_key)
{
    $key = betterstorage_s3_object_key($config['prefix'] ?? '', $relative_key);
    return betterstorage_s3_request('DELETE', $config['bucket'] ?? '', $config['region'] ?? 'us-east-1', $config['access_key'] ?? '', $config['secret_key'] ?? '', '/' . $key, '', $config['endpoint'] ?? '');
}

function betterstorage_s3_object_size($config, $relative_key)
{
    $key = betterstorage_s3_object_key($config['prefix'] ?? '', $relative_key);
    $result = betterstorage_s3_request('HEAD', $config['bucket'] ?? '', $config['region'] ?? 'us-east-1', $config['access_key'] ?? '', $config['secret_key'] ?? '', '/' . $key, '', $config['endpoint'] ?? '');
    if (empty($result['ok']))
        return -1;
    if (!empty($result['headers']) && preg_match('~(?:^|\r?\n)content-length:\s*(\d+)~i', $result['headers'], $m))
        return (int) $m[1];
    return 0;
}

/** Create a short-lived signed GET URL. */
function betterstorage_s3_presign_get($config, $relative_key, $expires = 300, $download_name = '')
{
    $bucket=trim((string)($config['bucket']??'')); $region=trim((string)($config['region']??'us-east-1'))?:'us-east-1';
    $access=trim((string)($config['access_key']??'')); $secret=trim((string)($config['secret_key']??'')); $endpoint=trim((string)($config['endpoint']??''));
    if($bucket===''||$access===''||$secret==='') return '';
    $expires=max(60,min(3600,(int)$expires));
    $object_key=betterstorage_s3_object_key($config['prefix']??'', $relative_key);
    $path='/' . ltrim($object_key,'/');
    $encoded_path=implode('/',array_map('rawurlencode',explode('/',$path))); $encoded_path=str_replace('%2F','/',$encoded_path);
    if($encoded_path===''||$encoded_path[0]!=='/') $encoded_path='/'.$encoded_path;
    $host=betterstorage_s3_host($bucket,$region,$endpoint);
    $amz_date=gmdate('Ymd\\THis\\Z'); $date_stamp=gmdate('Ymd'); $scope=$date_stamp.'/'.$region.'/s3/aws4_request';
    $query=array(
        'X-Amz-Algorithm'=>'AWS4-HMAC-SHA256',
        'X-Amz-Credential'=>$access.'/'.$scope,
        'X-Amz-Date'=>$amz_date,
        'X-Amz-Expires'=>(string)$expires,
        'X-Amz-SignedHeaders'=>'host',
    );
    if($download_name!=='') $query['response-content-disposition']='attachment; filename="'.str_replace(array('"','\\'), '_', basename($download_name)).'"';
    ksort($query);
    $parts=array(); foreach($query as $k=>$v) $parts[]=rawurlencode($k).'='.rawurlencode($v); $canonical_query=implode('&',$parts);
    $canonical_headers='host:'.$host."\n";
    $canonical_request="GET\n".$encoded_path."\n".$canonical_query."\n".$canonical_headers."\nhost\nUNSIGNED-PAYLOAD";
    $string_to_sign="AWS4-HMAC-SHA256\n".$amz_date."\n".$scope."\n".hash('sha256',$canonical_request);
    $signature=hash_hmac('sha256',$string_to_sign,betterstorage_s3_signing_key($secret,$date_stamp,$region));
    return 'https://'.$host.$encoded_path.'?'.$canonical_query.'&X-Amz-Signature='.$signature;
}

function betterstorage_s3_download_to_file($config, $relative_key, $dest)
{
    $url=betterstorage_s3_presign_get($config,$relative_key,300);
    if($url==='') return false;
    $fp=@fopen($dest,'wb'); if(!$fp) return false;
    $ch=curl_init($url); curl_setopt_array($ch,array(CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>0));
    $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch); fclose($fp);
    if(!$ok||$status<200||$status>=300||!is_file($dest)){@unlink($dest); return false;} return true;
}

/** Better Storage 0.1.0-dev8 standalone service/admin layer. */
function betterstorage_load_language()
{
	loadLanguage('BetterStorage');
}

function betterstorage_integrate_admin_areas(&$admin_areas)
{
	global $txt;
	betterstorage_load_language();
	$admin_areas['config']['areas']['betterstorage'] = array(
		'label' => $txt['betterstorage_title'],
		'file' => 'BetterStorage.php',
		'function' => 'betterstorage_admin_dispatch',
		'permission' => array('admin_forum'),
		'icon' => 'better_storage.png',
		'subsections' => array(
			'dashboard' => array($txt['betterstorage_dashboard']),
			'settings' => array($txt['betterstorage_settings']),
		),
	);
}

function betterstorage_is_installed()
{
	// If this API is loaded, the Better Storage runtime is present. The install marker
	// is retained for diagnostics, but integrations should not fail solely because an
	// early development upgrade did not populate that setting.
	return true;
}

function betterstorage_config()
{
	global $modSettings;
	return array(
		'backend' => !empty($modSettings['betterstorage_backend']) && $modSettings['betterstorage_backend'] === 's3' ? 's3' : 'local',
		'region' => !empty($modSettings['betterstorage_s3_region']) ? (string) $modSettings['betterstorage_s3_region'] : 'us-east-1',
		'bucket' => !empty($modSettings['betterstorage_s3_bucket']) ? (string) $modSettings['betterstorage_s3_bucket'] : '',
		'prefix' => isset($modSettings['betterstorage_s3_prefix']) ? trim((string) $modSettings['betterstorage_s3_prefix'], '/ ') : '',
		'access_key' => !empty($modSettings['betterstorage_s3_access_key']) ? (string) $modSettings['betterstorage_s3_access_key'] : '',
		'secret_key' => !empty($modSettings['betterstorage_s3_secret_key']) ? (string) $modSettings['betterstorage_s3_secret_key'] : '',
		'endpoint' => !empty($modSettings['betterstorage_s3_endpoint']) ? (string) $modSettings['betterstorage_s3_endpoint'] : '',
		'private' => !isset($modSettings['betterstorage_s3_private']) || !empty($modSettings['betterstorage_s3_private']),
	);
}

function betterstorage_source_backend($source)
{
	global $modSettings;
	$known = array('gallery', 'downloads', 'attachments', 'pm', 'chat');
	$source = in_array($source, $known, true) ? $source : 'attachments';
	$key = 'betterstorage_backend_' . $source;

	if (isset($modSettings[$key]))
		return $modSettings[$key] === 's3' ? 's3' : 'local';

	// Upgrade-safe compatibility: Gallery was the first Better Storage consumer and
	// should inherit the legacy global backend until the per-source route is saved.
	if ($source === 'gallery' && !empty($modSettings['betterstorage_backend']) && $modSettings['betterstorage_backend'] === 's3')
		return 's3';

	return 'local';
}

function betterstorage_source_prefix($source)
{
	global $modSettings;
	$defaults = array(
		'gallery' => 'better-gallery',
		'downloads' => 'better-downloads',
		'attachments' => 'forum-attachments',
		'pm' => 'pm-attachments',
		'chat' => 'better-chat',
	);
	$source = isset($defaults[$source]) ? $source : 'attachments';
	$key = 'betterstorage_prefix_' . $source;
	return isset($modSettings[$key]) && trim((string) $modSettings[$key]) !== '' ? trim((string) $modSettings[$key], '/ ') : $defaults[$source];
}

function betterstorage_source_config($source)
{
	$config = betterstorage_config();
	$config['backend'] = betterstorage_source_backend($source);
	$root = betterstorage_s3_normalize_prefix($config['prefix']);
	$config['prefix'] = trim($root . betterstorage_source_prefix($source), '/');
	return $config;
}

function betterstorage_format_bytes($bytes)
{
	$bytes = max(0, (float) $bytes);
	$units = array('B', 'KB', 'MB', 'GB', 'TB');
	$i = 0;
	while ($bytes >= 1024 && $i < count($units) - 1)
	{
		$bytes /= 1024;
		$i++;
	}
	return ($i === 0 ? (string) (int) $bytes : number_format($bytes, 2)) . ' ' . $units[$i];
}

function betterstorage_object_role_from_key($object_key)
{
	$key = strtolower(str_replace('\\', '/', (string) $object_key));
	$base = basename($key);
	if (strpos($base, 'thumb_') === 0 || strpos($key, '/thumbnails/') !== false || strpos($key, '/previews/') !== false || preg_match('~_thumb\.[a-z0-9]+$~i', $base))
		return 'derived';
	return 'primary';
}

function betterstorage_register_object($source, $backend, $object_key, $size = 0, $mime = '', $owner_id = 0, $reference_id = 0, $object_role = '')
{
	global $smcFunc;
	$source = substr(preg_replace('~[^a-z0-9_-]+~i', '-', strtolower((string) $source)), 0, 32);
	$backend = $backend === 's3' ? 's3' : 'local';
	$object_key = substr((string) $object_key, 0, 768);
	if ($object_key === '') return false;
	$object_role = $object_role === 'derived' ? 'derived' : ($object_role === 'primary' ? 'primary' : betterstorage_object_role_from_key($object_key));

	$smcFunc['db_insert']('replace', '{db_prefix}better_storage_objects',
		array('source'=>'string-32','backend'=>'string-16','object_key'=>'string-768','file_size'=>'int','mime_type'=>'string-128','id_owner'=>'int','id_reference'=>'int','object_role'=>'string-16','created_at'=>'int'),
		array($source,$backend,$object_key,max(0,(int)$size),substr((string)$mime,0,128),max(0,(int)$owner_id),max(0,(int)$reference_id),$object_role,time()),
		array('source','backend','object_key')
	);
	return true;
}

function betterstorage_unregister_object($source, $backend, $object_key)
{
	global $smcFunc;
	$smcFunc['db_query']('', 'DELETE FROM {db_prefix}better_storage_objects WHERE source={string:source} AND backend={string:backend} AND object_key={string:key}', array(
		'source'=>(string)$source, 'backend'=>$backend === 's3' ? 's3' : 'local', 'key'=>(string)$object_key,
	));
}

function betterstorage_registry_stats()
{
	global $smcFunc;
	$empty = array('objects'=>0,'primary'=>0,'derived'=>0,'bytes'=>0,'primary_bytes'=>0,'derived_bytes'=>0);
	$stats = array('local'=>$empty,'s3'=>$empty,'sources'=>array());
	$request = $smcFunc['db_query']('', '
		SELECT source, backend, object_role, COUNT(*) AS objects, COALESCE(SUM(file_size),0) AS bytes
		FROM {db_prefix}better_storage_objects
		GROUP BY source, backend, object_role', array());
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		$b = $row['backend'] === 's3' ? 's3' : 'local';
		$s = (string) $row['source'];
		$role = $row['object_role'] === 'derived' ? 'derived' : 'primary';
		if (!isset($stats['sources'][$s])) $stats['sources'][$s] = array('local'=>$empty,'s3'=>$empty);
		$objects = (int) $row['objects'];
		$bytes = (int) $row['bytes'];
		$stats[$b]['objects'] += $objects;
		$stats[$b][$role] += $objects;
		$stats[$b]['bytes'] += $bytes;
		$stats[$b][$role . '_bytes'] += $bytes;
		$stats['sources'][$s][$b]['objects'] += $objects;
		$stats['sources'][$s][$b][$role] += $objects;
		$stats['sources'][$s][$b]['bytes'] += $bytes;
		$stats['sources'][$s][$b][$role . '_bytes'] += $bytes;
	}
	$smcFunc['db_free_result']($request);
	return $stats;
}

function betterstorage_admin_dispatch()
{
	global $context, $txt;
	isAllowedTo('admin_forum');
	betterstorage_load_language();
	loadTemplate('BetterStorage');
	$sa = !empty($_REQUEST['sa']) ? (string) $_REQUEST['sa'] : 'dashboard';
	$map = array('dashboard'=>'betterstorage_admin_dashboard','settings'=>'betterstorage_admin_settings','save'=>'betterstorage_admin_save','test'=>'betterstorage_admin_test');
	if (!isset($map[$sa])) $sa = 'dashboard';
	$context['page_title'] = $txt['betterstorage_title'];
	$context[$context['admin_menu_name']]['tab_data'] = array(
		'title'=>$txt['betterstorage_title'],
		'description'=>$txt['betterstorage_desc'],
		'tabs'=>array('dashboard'=>array('description'=>$txt['betterstorage_dashboard']),'settings'=>array('description'=>$txt['betterstorage_settings'])),
	);
	call_helper($map[$sa]);
}

function betterstorage_admin_dashboard()
{
	global $context;
	$config = betterstorage_config();
	$stats = betterstorage_registry_stats();
	$context['betterstorage_config'] = $config;
	$context['betterstorage_stats'] = $stats;
	$context['betterstorage_sources'] = array(
		'gallery'=>betterstorage_source_prefix('gallery'),
		'downloads'=>betterstorage_source_prefix('downloads'),
		'attachments'=>betterstorage_source_prefix('attachments'),
		'pm'=>betterstorage_source_prefix('pm'),
		'chat'=>betterstorage_source_prefix('chat'),
	);
	$context['betterstorage_routes'] = array(
		'gallery'=>betterstorage_source_backend('gallery'),
		'downloads'=>betterstorage_source_backend('downloads'),
		'attachments'=>betterstorage_source_backend('attachments'),
		'pm'=>betterstorage_source_backend('pm'),
		'chat'=>betterstorage_source_backend('chat'),
	);
	$context['sub_template'] = 'betterstorage_dashboard';
}

function betterstorage_form_state()
{
	global $modSettings;
	$c = betterstorage_config();
	$form = array(
		'backend'=>$c['backend'], 'region'=>$c['region'], 'bucket'=>$c['bucket'], 'prefix'=>$c['prefix'],
		'access_key'=>$c['access_key'], 'endpoint'=>$c['endpoint'], 'private'=>$c['private'], 'has_secret'=>$c['secret_key'] !== '',
		'prefix_gallery'=>betterstorage_source_prefix('gallery'), 'prefix_downloads'=>betterstorage_source_prefix('downloads'),
		'prefix_attachments'=>betterstorage_source_prefix('attachments'), 'prefix_pm'=>betterstorage_source_prefix('pm'), 'prefix_chat'=>betterstorage_source_prefix('chat'),
		'backend_gallery'=>betterstorage_source_backend('gallery'), 'backend_downloads'=>betterstorage_source_backend('downloads'),
		'backend_attachments'=>betterstorage_source_backend('attachments'), 'backend_pm'=>betterstorage_source_backend('pm'), 'backend_chat'=>betterstorage_source_backend('chat'),
	);
	if (!empty($_SESSION['betterstorage_pending']) && is_array($_SESSION['betterstorage_pending']))
	{
		foreach ($_SESSION['betterstorage_pending'] as $k=>$v)
			if ($k !== 'secret_key') $form[$k]=$v;
		if (!empty($_SESSION['betterstorage_pending']['secret_key'])) $form['has_secret']=true;
	}
	return $form;
}

function betterstorage_admin_settings()
{
	global $context;
	$context['betterstorage_form'] = betterstorage_form_state();
	$context['betterstorage_test'] = !empty($_SESSION['betterstorage_test']) ? $_SESSION['betterstorage_test'] : null;
	unset($_SESSION['betterstorage_test']);
	$context['sub_template'] = 'betterstorage_settings';
}

function betterstorage_collect_post($preserve_saved_secret = true)
{
	global $modSettings;
	$gallery_backend = (!empty($_POST['backend_gallery']) && $_POST['backend_gallery'] === 's3') ? 's3' : 'local';
	$state = array(
		// Keep the legacy global key synced to Gallery for backward compatibility with early adapters.
		'backend'=>$gallery_backend,
		'backend_gallery'=>$gallery_backend,
		'backend_downloads'=>(!empty($_POST['backend_downloads']) && $_POST['backend_downloads']==='s3')?'s3':'local',
		'backend_attachments'=>(!empty($_POST['backend_attachments']) && $_POST['backend_attachments']==='s3')?'s3':'local',
		'backend_pm'=>(!empty($_POST['backend_pm']) && $_POST['backend_pm']==='s3')?'s3':'local',
		'backend_chat'=>(!empty($_POST['backend_chat']) && $_POST['backend_chat']==='s3')?'s3':'local',
		'region'=>trim((string)($_POST['region']??'us-east-1')) ?: 'us-east-1',
		'bucket'=>trim((string)($_POST['bucket']??'')),
		'prefix'=>trim((string)($_POST['prefix']??''),'/ '),
		'access_key'=>trim((string)($_POST['access_key']??'')),
		'secret_key'=>trim((string)($_POST['secret_key']??'')),
		'endpoint'=>trim((string)($_POST['endpoint']??'')),
		'private'=>!empty($_POST['private'])?1:0,
		'prefix_gallery'=>trim((string)($_POST['prefix_gallery']??'better-gallery'),'/ '),
		'prefix_downloads'=>trim((string)($_POST['prefix_downloads']??'better-downloads'),'/ '),
		'prefix_attachments'=>trim((string)($_POST['prefix_attachments']??'forum-attachments'),'/ '),
		'prefix_pm'=>trim((string)($_POST['prefix_pm']??'pm-attachments'),'/ '),
		'prefix_chat'=>trim((string)($_POST['prefix_chat']??'better-chat'),'/ '),
	);
	if ($preserve_saved_secret && $state['secret_key']==='')
	{
		if (!empty($_SESSION['betterstorage_pending']['secret_key'])) $state['secret_key']=(string)$_SESSION['betterstorage_pending']['secret_key'];
		elseif (!empty($modSettings['betterstorage_s3_secret_key'])) $state['secret_key']=(string)$modSettings['betterstorage_s3_secret_key'];
	}
	return $state;
}

function betterstorage_admin_save()
{
	checkSession('post');
	$s = betterstorage_collect_post(true);
	updateSettings(array(
		'betterstorage_backend'=>$s['backend'], 'betterstorage_backend_gallery'=>$s['backend_gallery'], 'betterstorage_backend_downloads'=>$s['backend_downloads'],
		'betterstorage_backend_attachments'=>$s['backend_attachments'], 'betterstorage_backend_pm'=>$s['backend_pm'], 'betterstorage_backend_chat'=>$s['backend_chat'],
		'betterstorage_s3_region'=>$s['region'], 'betterstorage_s3_bucket'=>$s['bucket'],
		'betterstorage_s3_prefix'=>$s['prefix'], 'betterstorage_s3_access_key'=>$s['access_key'], 'betterstorage_s3_endpoint'=>$s['endpoint'],
		'betterstorage_s3_private'=>$s['private'], 'betterstorage_prefix_gallery'=>$s['prefix_gallery'], 'betterstorage_prefix_downloads'=>$s['prefix_downloads'],
		'betterstorage_prefix_attachments'=>$s['prefix_attachments'], 'betterstorage_prefix_pm'=>$s['prefix_pm'], 'betterstorage_prefix_chat'=>$s['prefix_chat'],
	));
	if ($s['secret_key'] !== '') updateSettings(array('betterstorage_s3_secret_key'=>$s['secret_key']));
	unset($_SESSION['betterstorage_pending']);
	$_SESSION['betterstorage_test']=array('ok'=>true,'message'=>'Better Storage settings saved. Per-source storage routing is active.');
	redirectexit('action=admin;area=betterstorage;sa=settings');
}

function betterstorage_admin_test()
{
	checkSession('post');
	$s = betterstorage_collect_post(true);
	$_SESSION['betterstorage_pending']=$s;
	$r = betterstorage_s3_test(array('bucket'=>$s['bucket'],'region'=>$s['region'],'access_key'=>$s['access_key'],'secret_key'=>$s['secret_key'],'endpoint'=>$s['endpoint']));
	$_SESSION['betterstorage_test']=array('ok'=>!empty($r['ok']),'message'=>!empty($r['ok'])?'Amazon S3 connection successful. Settings have not been saved yet — click Save to keep them.':(string)($r['message']??'S3 connection failed.'));
	redirectexit('action=admin;area=betterstorage;sa=settings');
}


/**
 * Return metadata for a forum attachment that Better Storage manages.
 */
function betterstorage_attachment_storage_row($attach_id)
{
	global $smcFunc;
	$attach_id = (int) $attach_id;
	if ($attach_id <= 0)
		return array();

	$request = $smcFunc['db_query']('', '
		SELECT id_attach, id_folder, filename, file_hash, fileext, size, mime_type, attachment_type,
			better_storage_backend, better_storage_key
		FROM {db_prefix}attachments
		WHERE id_attach = {int:id_attach}
		LIMIT 1',
		array('id_attach' => $attach_id)
	);
	$row = $smcFunc['db_fetch_assoc']($request);
	$smcFunc['db_free_result']($request);
	return !empty($row) ? $row : array();
}

/**
 * Move newly assigned SMF forum attachments to the selected Better Storage backend.
 * Existing attachments are deliberately left where they are until a migration tool is run.
 */
function betterstorage_integrate_assign_attachments(&$attachIDs, &$msgID)
{
	global $smcFunc, $sourcedir;

	if (empty($attachIDs))
		return;

	$route = betterstorage_source_backend('attachments');
	$config = betterstorage_source_config('attachments');
	if ($route === 's3' && (empty($config['bucket']) || empty($config['access_key']) || empty($config['secret_key'])))
	{
		log_error('[Better Storage] Forum attachments are routed to Amazon S3, but the S3 connection is incomplete.', 'general');
		return;
	}

	require_once($sourcedir . '/Subs-Attachments.php');
	$ids = array_values(array_unique(array_map('intval', (array) $attachIDs)));
	if (empty($ids))
		return;

	// Include thumbnails created by SMF for the attachments being assigned.
	$request = $smcFunc['db_query']('', '
		SELECT a.id_attach, a.id_folder, a.filename, a.file_hash, a.fileext, a.size, a.mime_type,
			a.attachment_type, a.id_thumb, a.better_storage_backend, a.better_storage_key,
			thumb.id_attach AS thumb_id, thumb.id_folder AS thumb_folder, thumb.filename AS thumb_filename,
			thumb.file_hash AS thumb_file_hash, thumb.fileext AS thumb_fileext, thumb.size AS thumb_size,
			thumb.mime_type AS thumb_mime, thumb.better_storage_backend AS thumb_storage_backend,
			thumb.better_storage_key AS thumb_storage_key
		FROM {db_prefix}attachments AS a
		LEFT JOIN {db_prefix}attachments AS thumb ON (thumb.id_attach = a.id_thumb)
		WHERE a.id_attach IN ({array_int:attach_ids})',
		array('attach_ids' => $ids)
	);

	$rows = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
	{
		$rows[] = array(
			'id_attach' => (int) $row['id_attach'],
			'id_folder' => (int) $row['id_folder'],
			'filename' => (string) $row['filename'],
			'file_hash' => (string) $row['file_hash'],
			'fileext' => (string) $row['fileext'],
			'size' => (int) $row['size'],
			'mime_type' => (string) $row['mime_type'],
			'attachment_type' => (int) $row['attachment_type'],
			'better_storage_backend' => (string) $row['better_storage_backend'],
			'better_storage_key' => (string) $row['better_storage_key'],
		);
		if (!empty($row['thumb_id']))
		{
			$rows[] = array(
				'id_attach' => (int) $row['thumb_id'],
				'id_folder' => (int) $row['thumb_folder'],
				'filename' => (string) $row['thumb_filename'],
				'file_hash' => (string) $row['thumb_file_hash'],
				'fileext' => (string) $row['thumb_fileext'],
				'size' => (int) $row['thumb_size'],
				'mime_type' => (string) $row['thumb_mime'],
				'attachment_type' => 3,
				'better_storage_backend' => (string) $row['thumb_storage_backend'],
				'better_storage_key' => (string) $row['thumb_storage_key'],
			);
		}
	}
	$smcFunc['db_free_result']($request);

	$seen = array();
	foreach ($rows as $row)
	{
		$id_attach = (int) $row['id_attach'];
		if ($id_attach <= 0 || isset($seen[$id_attach]) || $row['better_storage_backend'] === 's3')
			continue;
		$seen[$id_attach] = true;

		$local = getAttachmentFilename($row['filename'], $id_attach, (int) $row['id_folder'], false, $row['file_hash']);

		if ($route !== 's3')
		{
			betterstorage_register_object('attachments', 'local', 'attachment/' . $id_attach, (int) $row['size'], (string) $row['mime_type'], 0, $id_attach, !empty($row['attachment_type']) && (int) $row['attachment_type'] === 3 ? 'derived' : 'primary');
			continue;
		}

		if (!is_file($local))
		{
			log_error('[Better Storage] Could not move forum attachment #' . $id_attach . ' to S3 because its local file was not found.', 'general');
			continue;
		}

		$ext = preg_replace('~[^a-z0-9]+~i', '', (string) $row['fileext']);
		$relative = 'attachments/' . $id_attach . '/' . $row['file_hash'] . ($ext !== '' ? '.' . strtolower($ext) : '.dat');
		$mime = trim((string) $row['mime_type']) !== '' ? (string) $row['mime_type'] : 'application/octet-stream';
		$put = betterstorage_s3_put_file($config, $relative, $local, $mime);
		if (empty($put['ok']))
		{
			log_error('[Better Storage] S3 upload failed for forum attachment #' . $id_attach . ': ' . (!empty($put['error']) ? $put['error'] : 'Unknown error'), 'general');
			continue;
		}

		$smcFunc['db_query']('', '
			UPDATE {db_prefix}attachments
			SET better_storage_backend = {string:backend}, better_storage_key = {string:object_key}
			WHERE id_attach = {int:id_attach}',
			array('backend' => 's3', 'object_key' => $relative, 'id_attach' => $id_attach)
		);

		betterstorage_register_object('attachments', 's3', $relative, (int) $row['size'], $mime, 0, $id_attach, !empty($row['attachment_type']) && (int) $row['attachment_type'] === 3 ? 'derived' : 'primary');
		@unlink($local);
		cache_put_data('attachment_lookup_id-' . $id_attach, null, 0);
	}
}

/** Ensure the Better Storage runtime is loaded for SMF attachment download requests. */
function betterstorage_pre_download_request()
{
	// Intentionally empty. Registering this hook loads BetterStorage.php before
	// ShowAttachments.php reaches the small storage adapter shim.
}

/**
 * Serve an S3-backed forum attachment only after SMF has completed its normal permission checks.
 * Called from a tiny compatibility shim in ShowAttachments.php.
 */
function betterstorage_serve_forum_attachment($attach_id)
{
	global $smcFunc;
	$attach_id = (int) $attach_id;
	if ($attach_id <= 0)
		return false;

	$row = betterstorage_attachment_storage_row($attach_id);
	if (empty($row) || $row['better_storage_backend'] !== 's3' || empty($row['better_storage_key']))
		return false;

	$config = betterstorage_source_config('attachments');
	$url = betterstorage_s3_presign_get(
		$config,
		$row['better_storage_key'],
		300,
		(isset($_REQUEST['preview']) || isset($_REQUEST['image']) || (int) $row['attachment_type'] === 3) ? '' : $row['filename']
	);
	if ($url === '')
	{
		log_error('[Better Storage] Could not create an S3 download URL for forum attachment #' . $attach_id . '.', 'general');
		return false;
	}

	// Match SMF's normal download-count behaviour: thumbs and previews do not count,
	// and resumed downloads only count when starting from byte zero.
	$range_start = 0;
	if (!empty($_SERVER['HTTP_RANGE']) && preg_match('~bytes=(\d+)~i', $_SERVER['HTTP_RANGE'], $m))
		$range_start = (int) $m[1];
	if ((int) $row['attachment_type'] !== 3 && empty($_REQUEST['preview']) && $range_start === 0)
	{
		$smcFunc['db_query']('', '
			UPDATE {db_prefix}attachments
			SET downloads = downloads + 1
			WHERE id_attach = {int:id_attach}',
			array('id_attach' => $attach_id)
		);
	}

	while (@ob_get_level() > 0)
		@ob_end_clean();
	header_remove('content-encoding');
	header('Location: ' . $url, true, 302);
	exit;
}

/**
 * Delete S3 objects after SMF removes their attachment rows.
 */
function betterstorage_integrate_remove_attachments($attach_ids)
{
	global $smcFunc;
	$ids = array_values(array_unique(array_filter(array_map('intval', (array) $attach_ids))));
	if (empty($ids))
		return;

	$request = $smcFunc['db_query']('', '
		SELECT backend, object_key
		FROM {db_prefix}better_storage_objects
		WHERE source = {string:source}
			AND id_reference IN ({array_int:ids})',
		array('source' => 'attachments', 'ids' => $ids)
	);
	$objects = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
		$objects[] = array('backend' => (string) $row['backend'], 'key' => (string) $row['object_key']);
	$smcFunc['db_free_result']($request);

	if (empty($objects))
		return;

	$config = betterstorage_source_config('attachments');
	foreach ($objects as $object)
	{
		if ($object['backend'] === 's3')
		{
			$result = betterstorage_s3_delete($config, $object['key']);
			if (empty($result['ok']) && (int) ($result['status'] ?? 0) !== 404)
				log_error('[Better Storage] Could not delete forum attachment object from S3: ' . $object['key'] . '. ' . (!empty($result['error']) ? $result['error'] : ''), 'general');
		}
		betterstorage_unregister_object('attachments', $object['backend'], $object['key']);
	}
}
