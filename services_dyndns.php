<?php
/*
 * services_dyndns.php
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
2018.02.20
한글화 번역 시작
*/

##|+PRIV
##|*IDENT=page-services-dynamicdnsclients
##|*NAME=Services: Dynamic DNS clients
##|*DESCR=Allow access to the 'Services: Dynamic DNS clients' page.
##|*MATCH=services_dyndns.php*
##|-PRIV

require_once("guiconfig.inc");

if (!is_array($config['dyndnses']['dyndns'])) {
	$config['dyndnses']['dyndns'] = array();
}

$a_dyndns = &$config['dyndnses']['dyndns'];
global $dyndns_split_domain_types;

if ($_POST['act'] == "del") {
	$conf = $a_dyndns[$_POST['id']];
	if (in_array($conf['type'], $dyndns_split_domain_types)) {
		$hostname = $conf['host'] . "." . $conf['domainname'];
	} else {
		$hostname = $conf['host'];
	}
	@unlink("{$g['conf_path']}/dyndns_{$conf['interface']}{$conf['type']}" . escapeshellarg($hostname) . "{$conf['id']}.cache");
	unset($a_dyndns[$_POST['id']]);

	write_config(gettext("동적 DNS 클라이언트가 삭제되었습니다."));
	services_dyndns_configure();

	header("Location: services_dyndns.php");
	exit;
} else if ($_POST['act'] == "toggle") {
	if ($a_dyndns[$_POST['id']]) {
		if (isset($a_dyndns[$_POST['id']]['enable'])) {
			unset($a_dyndns[$_POST['id']]['enable']);
			$wc_msg = gettext('동적 DNS 클라이언트를 비활성화하였습니다.');
		} else {
			$a_dyndns[$_POST['id']]['enable'] = true;
			$wc_msg = gettext('동적 DNS 클라이언트를 활성화하였습니다.');
		}
		write_config($wc_msg);
		services_dyndns_configure();

		header("Location: services_dyndns.php");
		exit;
	}
}

$pgtitle = array(gettext("서비스"), gettext("동적 DNS"), gettext("동적 DNS 클라이언트"));
$pglinks = array("", "@self", "@self");
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$tab_array = array();
$tab_array[] = array(gettext("Dynamic DNS Clients"), true, "services_dyndns.php");
$tab_array[] = array(gettext("RFC 2136 Clients"), false, "services_rfc2136.php");
$tab_array[] = array(gettext("Check IP Services"), false, "services_checkip.php");
display_top_tabs($tab_array);
?>
<form action="services_dyndns.php" method="post" name="iform" id="iform">
	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><?=gettext(' DNS 클라이언트')?></h2></div>
		<div class="panel-body">
			<div class="table-responsive">
				<table class="table table-striped table-hover table-condensed table-rowdblclickedit">
					<thead>
						<tr>
							<th><?=gettext("인터페이스")?></th>
							<th><?=gettext("서비스")?></th>
							<th><?=gettext("호스트이름")?></th>
							<th><?=gettext("캐시된 IP")?></th>
							<th><?=gettext("발신지")?></th>
							<th><?=gettext("행동")?></th>
						</tr>
					</thead>
					<tbody>
<?php
$i = 0;
foreach ($a_dyndns as $dyndns):
	if (in_array($dyndns['type'], $dyndns_split_domain_types)) {
		$hostname = $dyndns['host'] . "." . $dyndns['domainname'];
	} else {
		$hostname = $dyndns['host'];
	}
?>
						<tr<?=!isset($dyndns['enable'])?' class="disabled"':''?>>
							<td>
<?php
	$iflist = get_configured_interface_with_descr();
	foreach ($iflist as $if => $ifdesc) {
		if ($dyndns['interface'] == $if) {
			print($ifdesc);

			break;
		}
	}

	$groupslist = return_gateway_groups_array();
	foreach ($groupslist as $if => $group) {
		if ($dyndns['interface'] == $if) {
			print($if);
			break;
		}
	}
?>
							</td>
							<td>
<?php
	$types = explode(",", DYNDNS_PROVIDER_DESCRIPTIONS);
	$vals = explode(" ", DYNDNS_PROVIDER_VALUES);

	for ($j = 0; $j < count($vals); $j++) {
		if ($vals[$j] == $dyndns['type']) {
			print(htmlspecialchars($types[$j]));

			break;
		}
	}
?>
							</td>
							<td>
<?php
	print(insert_word_breaks_in_domain_name(htmlspecialchars($hostname)));
?>
							</td>
							<td>
<?php
	$filename = "{$g['conf_path']}/dyndns_{$dyndns['interface']}{$dyndns['type']}" . escapeshellarg($hostname) . "{$dyndns['id']}.cache";
	$filename_v6 = "{$g['conf_path']}/dyndns_{$dyndns['interface']}{$dyndns['type']}" . escapeshellarg($hostname) . "{$dyndns['id']}_v6.cache";
	if (file_exists($filename)) {
		$ipaddr = dyndnsCheckIP($dyndns['interface']);
		$cached_ip_s = explode("|", file_get_contents($filename));
		$cached_ip = $cached_ip_s[0];

		if ($ipaddr != $cached_ip) {
			print('<span class="text-danger">');
		} else {
			print('<span class="text-success">');
		}

		print(htmlspecialchars($cached_ip));
		print('</span>');
	} else if (file_exists($filename_v6)) {
		$ipv6addr = get_interface_ipv6($dyndns['interface']);
		$cached_ipv6_s = explode("|", file_get_contents($filename_v6));
		$cached_ipv6 = $cached_ipv6_s[0];

		if ($ipv6addr != $cached_ipv6) {
			print('<span class="text-danger">');
		} else {
			print('<span class="text-success">');
		}

		print(htmlspecialchars($cached_ipv6));
		print('</span>');
	} else {
		print('N/A');
	}
?>
							</td>
							<td>
<?php
	print(htmlspecialchars($dyndns['descr']));
?>
							</td>
							<td>
								<a class="fa fa-pencil" title="<?=gettext('서비스 편집')?>" href="services_dyndns_edit.php?id=<?=$i?>"></a>
<?php if (isset($dyndns['enable'])) {
?>
								<a class="fa fa-ban" title="<?=gettext('서비스 비활성화')?>" href="?act=toggle&amp;id=<?=$i?>" usepost></a>
<?php } else {
?>
								<a class="fa fa-check-square-o" title="<?=gettext('서비스 활성화')?>" href="?act=toggle&amp;id=<?=$i?>" usepost></a>
<?php }
?>
								<a class="fa fa-trash" title="<?=gettext('서비스 ')?>"	href="services_dyndns.php?act=del&amp;id=<?=$i?>" usepost></a>
							</td>
						</tr>
<?php
	$i++;
	endforeach;
?>
					</tbody>
			  </table>
			</div>
		</div>
	</div>
</form>

<nav class="action-buttons">
	<a href="services_dyndns_edit.php" class="btn btn-sm btn-success btn-sm">
		<i class="fa fa-plus icon-embed-btn"></i>
		<?=gettext('추가')?>
	</a>
</nav>

<div>
	<?=sprintf(gettext('%1$s이곳%2$s에 나타나는 IP주소는 동적 DNS공급자를 사용하여 최신 상태를 유지합니다. '), '<span class="text-success">', '</span>')?>
	<?=gettext('편집 페이지에서 IP주소에 대한 업데이트를 강제 적용할 수 있습니다.')?>
</div>

<?php
include("foot.inc");