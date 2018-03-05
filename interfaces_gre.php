<?php
/*
 * interfaces_gre.php
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2004-2018 Rubicon Communications, LLC (Netgate)
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
2018.03.02
한글화 번역 시작
*/

##|+PRIV
##|*IDENT=page-interfaces-gre
##|*NAME=Interfaces: GRE
##|*DESCR=Allow access to the 'Interfaces: GRE' page.
##|*MATCH=interfaces_gre.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");

if (!is_array($config['gres']['gre'])) {
	$config['gres']['gre'] = array();
}

$a_gres = &$config['gres']['gre'] ;

function gre_inuse($num) {
	global $config, $a_gres;

	$iflist = get_configured_interface_list(true);
	foreach ($iflist as $if) {
		if ($config['interfaces'][$if]['if'] == $a_gres[$num]['greif']) {
			return true;
		}
	}

	return false;
}

if ($_POST['act'] == "del") {
	if (!isset($_POST['id'])) {
		$input_errors[] = gettext("잘못된 파라미터입니다.");
	} else if (empty($a_gres[$_POST['id']])) {
		$input_errors[] = gettext("잘못된 색인입니다.");
	/* check if still in use */
	} else if (gre_inuse($_POST['id'])) {
		$input_errors[] = gettext("해당 GRE 터널을 사용 중이므로 삭제할 수 없습니다.");
	} else {
		pfSense_interface_destroy($a_gres[$_POST['id']]['greif']);
		unset($a_gres[$_POST['id']]);

		write_config();

		header("Location: interfaces_gre.php");
		exit;
	}
}

$pgtitle = array(gettext("Interfaces"), gettext("GREs"));
$shortcut_section = "interfaces";
include("head.inc");
if ($input_errors) {
	print_input_errors($input_errors);
}

$tab_array = array();
$tab_array[] = array(gettext("Interface Assignments"), false, "interfaces_assign.php");
$tab_array[] = array(gettext("Interface Groups"), false, "interfaces_groups.php");
$tab_array[] = array(gettext("Wireless"), false, "interfaces_wireless.php");
$tab_array[] = array(gettext("VLANs"), false, "interfaces_vlan.php");
$tab_array[] = array(gettext("QinQs"), false, "interfaces_qinq.php");
$tab_array[] = array(gettext("PPPs"), false, "interfaces_ppps.php");
$tab_array[] = array(gettext("GREs"), true, "interfaces_gre.php");
$tab_array[] = array(gettext("GIFs"), false, "interfaces_gif.php");
$tab_array[] = array(gettext("Bridges"), false, "interfaces_bridge.php");
$tab_array[] = array(gettext("LAGGs"), false, "interfaces_lagg.php");
display_top_tabs($tab_array);
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('GRE 인터페이스')?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-condensed table-rowdblclickedit">
				<thead>
					<tr>
						<th><?=gettext("인터페이스"); ?></th>
						<th><?=gettext("Tunnel to &hellip;"); ?></th>
						<th><?=gettext("Description"); ?></th>
						<th><?=gettext("Actions"); ?></th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($a_gres as $i => $gre):
	if (substr($gre['if'], 0, 4) == "_vip") {
		$if = convert_real_interface_to_friendly_descr(get_real_interface($gre['if']));
	} else {
		$if = $gre['if'];
	}
?>
					<tr>
						<td>
							<?=htmlspecialchars(convert_friendly_interface_to_friendly_descr($if))?>
						</td>
						<td>
							<?=htmlspecialchars($gre['remote-addr'])?>
						</td>
						<td>
							<?=htmlspecialchars($gre['descr'])?>
						</td>
						<td>
							<a class="fa fa-pencil"	title="<?=gettext('GRE 인터페이스 편집')?>"	href="interfaces_gre_edit.php?id=<?=$i?>"></a>
							<a class="fa fa-trash"	title="<?=gettext('GRE 인터페이스 삭제')?>"	href="interfaces_gre.php?act=del&amp;id=<?=$i?>" usepost></a>
						</td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<nav class="action-buttons">
	<a href="interfaces_gre_edit.php" class="btn btn-success btn-sm">
		<i class="fa fa-plus icon-embed-btn"></i>
		<?=gettext("Add")?>
	</a>
</nav>
<?php
include("foot.inc");