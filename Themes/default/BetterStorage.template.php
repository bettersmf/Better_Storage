<?php

function template_betterstorage_dashboard()
{
	global $context, $scripturl, $modSettings;

	$c = $context['betterstorage_config'];
	$s = $context['betterstorage_stats'];

	$totalObjects = (int) $s['local']['objects'] + (int) $s['s3']['objects'];
	$totalPrimary = (int) $s['local']['primary'] + (int) $s['s3']['primary'];
	$totalDerived = (int) $s['local']['derived'] + (int) $s['s3']['derived'];
	$totalBytes = (int) $s['local']['bytes'] + (int) $s['s3']['bytes'];

	echo '
	<div class="cat_bar"><h3 class="catbg">Better Storage Overview</h3></div>
	<div class="windowbg">
		<style>
			.bs_overview_cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:12px}
			.bs_overview_card{padding:14px 16px}
			.bs_overview_label{font-weight:700;margin-bottom:8px}
			.bs_overview_main{display:flex;align-items:baseline;gap:7px;margin-bottom:10px}
			.bs_overview_number{font-size:1.65em;font-weight:700;line-height:1}
			.bs_overview_main_label{font-size:.95em}
			.bs_overview_meta{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
			.bs_metric{display:inline-flex;align-items:center;gap:4px;padding:3px 7px;border:1px solid rgba(128,128,128,.28);border-radius:999px;font-size:.88em;white-space:nowrap}
			.bs_metric strong{font-size:1.05em}
			.bs_overview_size{font-size:.9em;opacity:.85}
			.bs_connection{margin:4px 0 14px}
			.bs_stored_metrics{display:flex;align-items:center;flex-wrap:wrap;gap:5px}
			.bs_stored_metric{display:inline-flex;align-items:center;gap:3px;white-space:nowrap}
			.bs_stored_sep{opacity:.45}
			.bs_route_note{margin-top:10px}
		</style>
		<div class="bs_overview_cards">';

	$cards = array(
		'Local server' => array((int) $s['local']['primary'], (int) $s['local']['derived'], (int) $s['local']['objects'], (int) $s['local']['bytes']),
		'Amazon S3' => array((int) $s['s3']['primary'], (int) $s['s3']['derived'], (int) $s['s3']['objects'], (int) $s['s3']['bytes']),
		'Combined' => array($totalPrimary, $totalDerived, $totalObjects, $totalBytes),
	);

	foreach ($cards as $label => $v)
	{
		echo '
			<div class="roundframe bs_overview_card">
				<div class="bs_overview_label">', htmlspecialchars($label, ENT_QUOTES, 'UTF-8'), '</div>
				<div class="bs_overview_main">
					<span class="bs_overview_number">', $v[0], '</span>
					<span class="bs_overview_main_label">primary ', $v[0] === 1 ? 'file' : 'files', '</span>
				</div>
				<div class="bs_overview_meta">
					<span class="bs_metric"><strong>', $v[2], '</strong> ', $v[2] === 1 ? 'object' : 'objects', '</span>';

		if ($v[1] > 0)
			echo '
					<span class="bs_metric"><strong>', $v[1], '</strong> derived</span>';

		echo '
				</div>
				<div class="bs_overview_size">', betterstorage_format_bytes($v[3]), ' stored</div>
			</div>';
	}

	echo '
		</div>';

	if ($c['bucket'] !== '')
		echo '
		<div class="bs_connection smalltext"><strong>S3:</strong> Configured &nbsp;&middot;&nbsp; <strong>Bucket:</strong> ', htmlspecialchars($c['bucket'], ENT_QUOTES, 'UTF-8'), ' &nbsp;&middot;&nbsp; <strong>Region:</strong> ', htmlspecialchars($c['region'], ENT_QUOTES, 'UTF-8'), '</div>';
	else
		echo '
		<div class="bs_connection smalltext"><strong>S3:</strong> Not configured</div>';

	echo '
		<div class="title_bar"><h4 class="titlebg">Storage routing</h4></div>
		<table class="table_grid">
			<thead><tr class="title_bar"><th>Source</th><th>Backend</th><th>Prefix</th><th>Status</th><th>Stored</th></tr></thead>
			<tbody>';

	$labels = array(
		'gallery' => 'Better Gallery',
		'downloads' => 'Better Downloads',
		'attachments' => 'Forum attachments',
		'pm' => 'PM attachments',
		'chat' => 'Better Chat',
	);

	foreach ($context['betterstorage_sources'] as $k => $prefix)
	{
		$connected = !empty($modSettings['betterstorage_integration_' . $k]);
		$route = !empty($context['betterstorage_routes'][$k]) && $context['betterstorage_routes'][$k] === 's3' ? 'Amazon S3' : 'Local server';
		$empty = array('objects' => 0, 'primary' => 0, 'derived' => 0, 'bytes' => 0, 'primary_bytes' => 0, 'derived_bytes' => 0);
		$src = !empty($s['sources'][$k]) ? $s['sources'][$k] : array('local' => $empty, 's3' => $empty);
		$primary = (int) $src['local']['primary'] + (int) $src['s3']['primary'];
		$derived = (int) $src['local']['derived'] + (int) $src['s3']['derived'];
		$objects = (int) $src['local']['objects'] + (int) $src['s3']['objects'];

		echo '
				<tr>
					<td><strong>', htmlspecialchars($labels[$k], ENT_QUOTES, 'UTF-8'), '</strong></td>
					<td>', $route, '</td>
					<td><code>', htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'), '/</code></td>
					<td>', $connected ? '<strong>Connected</strong>' : 'Ready for integration', '</td>
					<td><div class="bs_stored_metrics">
						<span class="bs_stored_metric"><strong>', $primary, '</strong>&nbsp;primary</span>';

		if ($derived > 0)
			echo '<span class="bs_stored_sep">&middot;</span><span class="bs_stored_metric"><strong>', $derived, '</strong>&nbsp;derived</span>';

		echo '<span class="bs_stored_sep">&middot;</span><span class="bs_stored_metric"><strong>', $objects, '</strong>&nbsp;', $objects === 1 ? 'object' : 'objects', '</span>
					</div></td>
				</tr>';
	}

	echo '
			</tbody>
		</table>
		<p class="information bs_route_note"><strong>Primary</strong> = user uploads. <strong>Derived</strong> = generated thumbnails/previews. <strong>Objects</strong> = physical stored files. Route changes affect new files only.</p>
	</div>';
}
function template_betterstorage_settings()
{
	global $context,$scripturl;
	$f=$context['betterstorage_form'];
	if (!empty($context['betterstorage_test'])) { $m=$context['betterstorage_test']; echo '<div class="',(!empty($m['ok'])?'infobox':'errorbox'),'">',htmlspecialchars($m['message'],ENT_QUOTES,'UTF-8'),'</div>'; }
	echo '<form action="',$scripturl,'?action=admin;area=betterstorage;sa=save" method="post">';
	echo '<div class="cat_bar"><h3 class="catbg">Amazon S3 Connection</h3></div><div class="windowbg"><p class="information">Configure Amazon S3 once here. Individual SMF features choose whether to use this S3 connection or Local server in Storage Routing below.</p><dl class="settings">';
	input('region','AWS region',$f['region']); input('bucket','S3 bucket',$f['bucket']); input('prefix','Shared root prefix (optional)',$f['prefix']); input('access_key','Access key ID',$f['access_key']);
	echo '<dt><label for="secret_key">Secret access key</label></dt><dd><input type="password" id="secret_key" name="secret_key" value="" autocomplete="new-password"><br><span class="smalltext">',($f['has_secret']?'A secret key is already stored. Leave blank to keep it.':'Enter the IAM secret access key.'),'</span></dd>';
	input('endpoint','Custom S3 endpoint (optional)',$f['endpoint']);
	echo '<dt>Private objects</dt><dd><input type="checkbox" name="private" value="1" ',(!empty($f['private'])?'checked':''),'></dd></dl><div class="buttonlist righttext"><button class="button" formaction="',$scripturl,'?action=admin;area=betterstorage;sa=test" type="submit">Test S3 Connection</button></div></div>';

	echo '<div class="cat_bar"><h3 class="catbg">Storage Routing</h3></div><div class="windowbg"><p class="information">Choose where new files from each integration are stored. Existing files stay where they already are until a migration tool moves them.</p><table class="table_grid"><thead><tr class="title_bar"><th>Source</th><th>Backend</th><th>Prefix / folder</th></tr></thead><tbody>';
	$route_rows=array(
		array('gallery','Better Gallery'), array('downloads','Better Downloads'), array('attachments','Forum attachments'), array('pm','PM attachments'), array('chat','Better Chat')
	);
	foreach($route_rows as $r)
	{
		$k=$r[0]; $label=$r[1];
		echo '<tr><td><strong>',htmlspecialchars($label,ENT_QUOTES,'UTF-8'),'</strong></td><td><select name="backend_',$k,'"><option value="local" ',($f['backend_'.$k]==='local'?'selected':''),'>Local server</option><option value="s3" ',($f['backend_'.$k]==='s3'?'selected':''),'>Amazon S3</option></select></td><td><input type="text" name="prefix_',$k,'" value="',htmlspecialchars((string)$f['prefix_'.$k],ENT_QUOTES,'UTF-8'),'" style="width:95%;max-width:340px"></td></tr>';
	}
	echo '</tbody></table><div class="buttonlist righttext"><button class="button" type="submit">Save</button></div>',
		'<input type="hidden" name="', htmlspecialchars($context['session_var'], ENT_QUOTES, 'UTF-8'), '" value="', htmlspecialchars($context['session_id'], ENT_QUOTES, 'UTF-8'), '">',
		'</div></form>';
}
function input($n,$l,$v){ echo '<dt><label for="',$n,'">',htmlspecialchars($l,ENT_QUOTES,'UTF-8'),'</label></dt><dd><input type="text" id="',$n,'" name="',$n,'" value="',htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'),'" style="width:95%;max-width:520px"></dd>'; }
function select($n,$l,$opts,$v){ echo '<dt><label for="',$n,'">',htmlspecialchars($l,ENT_QUOTES,'UTF-8'),'</label></dt><dd><select id="',$n,'" name="',$n,'">'; foreach($opts as $k=>$x) echo '<option value="',$k,'" ',($v===$k?'selected':''),'>',htmlspecialchars($x,ENT_QUOTES,'UTF-8'),'</option>'; echo '</select></dd>'; }
