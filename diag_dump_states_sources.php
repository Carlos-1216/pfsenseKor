<?php
/*
 * diag_dump_states_source.php
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2004-2018 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2005 Colin Smith
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/*
2018.02.20
한글화 번역 시작
*/

##|+PRIV
##|*IDENT=page-diagnostics-sourcetracking
##|*NAME=Diagnostics: Show Source Tracking
##|*DESCR=Allow access to the 'Diagnostics: Show Source Tracking' page.
##|*MATCH=diag_dump_states_sources.php*
##|-PRIV

require_once("guiconfig.inc");

/* handle AJAX operations */
if ($_POST['action']) {
	if ($_POST['action'] == "remove") {
		if (is_ipaddr($_POST['srcip']) && is_ipaddr($_POST['dstip'])) {
			$retval = mwexec("/sbin/pfctl -K " . escapeshellarg($_POST['srcip']) . " -K " . escapeshellarg($_POST['dstip']));
			echo htmlentities("|{$_POST['srcip']}|{$_POST['dstip']}|{$retval}|");
		} else {
			echo gettext("입력이 올바르지않습니다.");
		}
		exit;
	}
}

/* get our states */
if ($_POST['filter']) {
	exec("/sbin/pfctl -s Sources | grep " . escapeshellarg(htmlspecialchars($_POST['filter'])), $sources);
} else {
	exec("/sbin/pfctl -s Sources", $sources);
}


$pgtitle = array(gettext("진단"), gettext("상태"), gettext("소스 트래킹"));
$pglinks = array("", "diag_dump_states.php", "@self");
include("head.inc");

$tab_array = array();
$tab_array[] = array(gettext("상태"), false, "diag_dump_states.php");
$tab_array[] = array(gettext("소스 트래킹"), true, "diag_dump_states_sources.php");
$tab_array[] = array(gettext("상태 리셋"), false, "diag_resetstate.php");
display_top_tabs($tab_array);

?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	$('a[data-entry]').on('click', function() {
		var el = $(this);
		var data = $(this).data('entry').split('|');

		$.ajax(
			'/diag_dump_states_sources.php',
			{
				type: 'post',
				data: {
					action: 'remove',
					srcip: data[0],
					dstip: data[1]
				},
				success: function() {
					el.parents('tr').remove();
				},
		});
	});
});
//]]>
</script>

<?php

$form = new Form(false);
$section = new Form_Section('Filters');

$section->addInput(new Form_Input(
	'filter',
	'Filter expression',
	'text',
	$_POST['filter']
));

$form->add($section);

$form->addGlobal(new Form_Button(
	'Submit',
	'Filter',
	null,
	'fa-filter'
))->addClass('btn-primary');

print $form;

?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext("해당 소스 추적 항목")?></h2></div>
	<div class="panel-body">
		<table class="table table-striped">
			<thead>
				<tr>
					<th><?=gettext("Source -> Destination")?></th>
					<th><?=gettext("# States")?></th>
					<th><?=gettext("# Connections")?></th>
					<th><?=gettext("Rate")?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
<?php
$row = 0;
if (count($sources) > 0) {
	foreach ($sources as $line) {
		if ($row >= 1000) {
			break;
		}

		// 192.168.20.2 -> 216.252.56.1 ( states 10, connections 0, rate 0.0/0s )

		$source_split = "";
		preg_match("/(.*)\s\(\sstates\s(.*),\sconnections\s(.*),\srate\s(.*)\s\)/", $line, $source_split);
		list($all, $info, $numstates, $numconnections, $rate) = $source_split;

		$source_split = "";
		preg_match("/(.*)\s\<?-\>?\s(.*)/", $info, $source_split);
		list($all, $srcip, $dstip) = $source_split;
?>
				<tr>
					<td><?= $info ?></td>
					<td><?= $numstates ?></td>
					<td><?= $numconnections ?></td>
					<td><?= $rate ?></td>

					<td>
						<a class="btn btn-xs btn-danger" data-entry="<?=$srcip?>|<?=$dstip?>"
							title="<?=sprintf(gettext('%1$s 부터 %2$s 까지의 모든 소스 추적 항목을 삭제합니다.'), $srcip, $dstip);?>"><?=gettext("삭제")?></a>
					</td>
				</tr>
<?php
		$row++;
	}
}
?>
			</tbody>
		</table>
	</div>
</div>
<?php
if ($row == 0) {
	print_info_box(gettext('소스 추적 항목을 발견하지 못했습니다.'), 'warning', false);
}

include("foot.inc");
