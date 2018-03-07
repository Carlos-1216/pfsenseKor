<?php
/*
 * pkg_mgr_install.php
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
2018.03.07
한글화 번역 
*/

##|+PRIV
##|*IDENT=page-system-packagemanager-installpackage
##|*NAME=System: Package Manager: Install Package
##|*DESCR=Allow access to the 'System: Package Manager: Install Package' page.
##|*MATCH=pkg_mgr_install.php*
##|-PRIV

ini_set('max_execution_time', '0');

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("pkg-utils.inc");

$sendto = "output";
$start_polling = false;
$firmwareupdate = false;
$guitimeout = 90;	// Seconds to wait before reloading the page after reboot
$guiretry = 20;		// Seconds to try again if $guitimeout was not long enough
//---------------------------------------------------------------------------------------------------------------------
// After an installation or removal has been started (mwexec(/usr/local/sbin/pfSense-upgrade-GUI.sh . . . )) AJAX calls
// are made to get status.
// The log file is read and the newest progress record retrieved. The data is formatted
// as JSON before being returned to the AJAX caller (at the bottom of this file)
//
// Arguments received here:
//		logfilename = Passed to installation script to tell it how to name the log file we will parse
//		next_log_line = Send log file entries that come after this line number
//
// JSON items returned
//		log:
//		exitcode:
//		data:{current:, total}
//		notice:
//
// Todo:
//		Respect next_log_line and append log to output window rather than writing it

$pidfile = $g['varrun_path'] . '/' . $g['product_name'] . '-upgrade.pid';
$repos = pkg_list_repos();

if ($_REQUEST['ajax']) {
	$response = "";
	$code = 0;

	// If this is an ajax call to get the installed and newest versions, call that function,
	// JSON encode the result, print it and exit
	if ($_REQUEST['getversion']) {
		$firmwareversions = get_system_pkg_version(true, false);
		print(json_encode($firmwareversions));
		exit;
	}

	// Check to see if our process is still running
	$running = "running";

	// When we do a reinstallall, it is technically possible that we might catch the system in-between
	// packages, hence the de-bounce here
	for ($idx=0;$idx<5 && !isvalidpid($pidfile); $idx++) {
		usleep(200000);
	}

	if (!isvalidpid($pidfile)) {
		$running = "stopped";
		// The log files may not be complete when the process terminates so we need wait until we see the
		// exit status (__RC=x)
		waitfor_string_in_file($_REQUEST['logfilename'] . '.txt', "__RC=", 10);
		filter_configure();
		send_event("서비스 재시작 패키지");
	}

	$pidarray = array('pid' => $running);

	// Process log file -----------------------------------------------------------------------------------------------
	$logfile = @fopen($_REQUEST['logfilename'] . '.txt', "r");

	if ($logfile != FALSE) {
		$resparray = array();
		$statusarray = array();
		$code = array();
		$notice = array('notice' => "");

		// Log file is read a line at a time so that we can detect/modify certain entries
		while (($logline = fgets($logfile)) !== false) {
			// Check for return codes and replace with suitable strings
			if (strpos($logline, "__RC=") !== false) {
				$code = explode(" ", $logline);

				$rc = str_replace("__RC=", "", $code[0]);

				if (count($code) > 1 &&
				    strpos($code[1], "REBOOT_AFTER") !== false) {
					$statusarray['reboot_needed'] = "yes";
				} else {
					$statusarray['reboot_needed'] = "no";
				}

				if ($rc == 0) {
					$logline = gettext("성공") . "\n";
				} else {
					$logline = gettext("실패") . "\n";
				}

				$response .= $logline;
				$statusarray['exitstatus'] = $rc;
			} else {
				$response .= htmlspecialchars($logline);
			}
		}

		fclose($logfile);
		$resparray['log'] = $response;
	} else {
		$resparray['log'] = "not_ready";
		print(json_encode($resparray));
		exit;
	}

	// Process progress file ------------------------------------------------------------------------------------------
	$progress = "";
	$progarray = array();

	$JSONfile = @fopen($_REQUEST['logfilename'] . '.json', "r");

	if ($JSONfile != FALSE) {
		while (($logline = fgets($JSONfile)) !== false) {
			if (!feof($JSONfile) && (strpos($logline, 'INFO_PROGRESS_TICK') !== false)) {
				if (strpos($logline, '}}') !== false) {
					$progress = $logline;
				}
			}
		}

		fclose($JSONfile);

		if (strlen($progress) > 0) {
			$progarray = json_decode($progress, true);
		}
	}

	//
	$ui_notice = "/tmp/package_ui_notice";

	if (file_exists($ui_notice)) {
		$notice['notice'] = file_get_contents($ui_notice);
	}

	// Glob all the arrays we have made together, and convert to JSON
	print(json_encode($resparray + $pidarray + $statusarray + $progarray + $notice));

	exit;
}

function waitfor_string_in_file($filename, $string, $timeout) {
	$start = $now = time();

	while (($now - $start) < $timeout) {
		$testfile = @fopen($filename, "r");

		if ($testfile != FALSE) {
			while (($line = fgets($testfile)) !== false) {
				if (strpos($line, $string) !== false) {
					fclose($testfile);
					return(true);
				}
			}

			fclose($testfile);
		}

	usleep(100000);
	$now = time();
	}

	return(false);
}

$pkgmode = '';

if (!empty($_REQUEST['mode'])) {
	$valid_modes = array(
		'reinstallall',
		'reinstallpkg',
		'delete',
		'installed'
	);

	if (!in_array($_REQUEST['mode'], $valid_modes)) {
		header("Location: pkg_mgr_installed.php");
		return;
	}

	$pkgmode = $_REQUEST['mode'];
}

// After a successful installation/removal/update the page is reloaded so that any menu changes show up
// immediately. These values passed as POST arguments tell the page the state it was in before the reload.
$confirmed = isset($_POST['confirmed']) && $_POST['confirmed'] == 'true';
$completed = isset($_POST['completed']) && $_POST['completed'] == 'true';
$reboot_needed = isset($_POST['reboot_needed']) && $_POST['reboot_needed'] == "yes";

if (!empty($_REQUEST['id'])) {
	if ($_REQUEST['id'] != "firmware") {
		header("Location: pkg_mgr_installed.php");
		return;
	}

	$firmwareupdate = true;

	// If the user changes the firmware branch to sync to, switch to the newly selected repo
	// and save their choice
	if ($_REQUEST['refrbranch']) {
		foreach ($repos as $repo) {
			if ($repo['name'] == $_POST['fwbranch']) {
				$config['system']['pkg_repo_conf_path'] = $repo['path'];
				pkg_switch_repo($repo['path']);
				write_config(gettext("저장된 펌웨어 분기 설정입니다."));
				break;
			}
		}
	}
} elseif (!$completed && empty($_REQUEST['pkg']) && $pkgmode != 'reinstallall') {
	header("Location: pkg_mgr_installed.php");
	return;
}

$pkgname = '';
if (!empty($_REQUEST['pkg'])) {
	$pkgname = $_REQUEST['pkg'];

	if (!pkg_valid_name($pkgname)) {
		header("Location: pkg_mgr_installed.php");
		return;
	}
}

$tab_array = array();

if ($firmwareupdate) {
	$pgtitle = array(gettext("시스템"), gettext("업데이트"), gettext("시스템 업데이트"));
	$pglinks = array("", "@self", "@self");
	$tab_array[] = array(gettext("시스템 업데이트"), true, "pkg_mgr_install.php?id=firmware");
	$tab_array[] = array(gettext("업데이트 설정"), false, "system_update_settings.php");
} else {
	$pgtitle = array(gettext("시스템"), gettext("패키지 매니저"), gettext("패키지 인스톨러"));
	$pglinks = array("", "pkg_mgr_installed.php", "@self");
	$tab_array[] = array(gettext("설치된 패키지"), false, "pkg_mgr_installed.php");
	$tab_array[] = array(gettext("사용 가능한 패키지"), false, "pkg_mgr.php");
	$tab_array[] = array(gettext("패키지 "), true, "");
}

// Create an array of repo names and descriptions to populate the "Branch" selector
function build_repo_list() {
	global $repos;

	$list = array();

	foreach ($repos as $repo) {
		$list[$repo['name']] = $repo['descr'];
	}

	return($list);
}

function get_repo_name($path) {
	global $repos;

	foreach ($repos as $repo) {
		if ($repo['path'] == $path) {
			return $repo['name'];
		}
	}

	/* Default */
	return $repos[0]['name'];
}

include("head.inc");
?>

<div id="final" class="alert" role="alert" style="display: none;"></div>

<?php
display_top_tabs($tab_array);

if ($input_errors) {
	print_input_errors($input_errors);
}

?>
<form action="pkg_mgr_install.php" method="post" class="form-horizontal">
<?php

if (!$confirmed && !$completed &&
    ($firmwareupdate || $pkgmode == 'reinstallall' || !empty($pkgname))):
	switch ($pkgmode) {
		case 'reinstallpkg':
			$pkgtxt = sprintf(gettext('패키지 %s을(를) 재설치하는 데 필요한 확인사항입니다.'), $pkgname);
			break;
		case 'delete':
			$pkgtxt = sprintf(gettext('패키지 %s을(를) 삭제하는 데 필요한 확인사항입니다.'), $pkgname);
			break;
		case 'installed':
		default:
			$pkgtxt = sprintf(gettext('패키지 %s을(를) 설치하는 데 필요한 확인사항입니다.'), $pkgname);
			break;
	}

?>
	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title">
<?php
			if ($pkgmode == 'reinstallall'):
?>
				<?=gettext("모든 패키지를 다시 설치하는 데 필요한 확인사항입니다.");?>
<?php
			elseif ($_REQUEST['from'] && $_REQUEST['to']):
?>
				<?=sprintf(gettext('%1$s을(를)%2$s에서%3$s(으)로 업그레이드하는 데 필요한 확인 사항입니다.'), $pkgname, htmlspecialchars($_REQUEST['from']), htmlspecialchars($_REQUEST['to']))?>
<?php
			elseif ($firmwareupdate):
?>
				<?=sprintf(gettext('%s시스템을 업데이트하는 데 필요한 확인사항입니다.'), $g['product_name'])?>
<?php
			else:
?>
				<?=$pkgtxt;?>
<?php
			endif;
?>
			</h2>
		</div>

		<div class="panel-body">
			<div class="content">
				<input type="hidden" name="mode" value="<?=$pkgmode;?>" />
<?php
	// Draw a selector to allow the user to select a different firmware branch
	// If the selection is changed, the page will be reloaded and the new choice displayed.
	if ($firmwareupdate):

		// Check to see if any new repositories have become available. This data is cached and
		// refreshed evrey 24 hours
		update_repos();
		$repopath = "/usr/local/share/{$g['product_name']}/pkg/repos";
		$helpfilename = "{$repopath}/{$g['product_name']}-repo-custom.help";
		$repos = pkg_list_repos();

		$group = new Form_Group("Branch");

		$field = new Form_Select(
			'fwbranch',
			'*Branch',
			get_repo_name($config['system']['pkg_repo_conf_path']),
			build_repo_list()
		);

		if (file_exists($helpfilename)) {
			$field->setHelp(file_get_contents($helpfilename));
		} else {
			$field->setHelp('Please select the branch from which to update the system firmware. %1$s' .
							'Use of the development version is at your own risk!', '<br />');
		}

		$group->add($field);
		print($group);
?>
				<div class="form-group">
					<label class="col-sm-2 control-label">
						<?=gettext("현재 기본 시스템")?>
					</label>
					<div class="col-sm-10" id="installed_version">
					</div>
				</div>

				<div class="form-group">
					<label class="col-sm-2 control-label">
						<?=gettext("최신 기본 시스템")?>
					</label>
					<div class="col-sm-10" id="version">
					</div>
				</div>

				<div class="form-group" id="confirm">
					<label class="col-sm-2 control-label" id="confirmlabel">
						<?=gettext("검색중")?>
					</label>
					<div class="col-sm-10">
						<input type="hidden" name="id" value="firmware" />
						<input type="hidden" name="confirmed" id="confirmed" value="true" />
						<button type="submit" class="btn btn-success" name="pkgconfirm" id="pkgconfirm" value="<?=gettext("확인")?>" style="display: none">
							<i class="fa fa-check icon-embed-btn"></i>
							<?=gettext("확인")?>
						</button>
						<span id="uptodate"><i class="fa fa-cog fa-spin fa-lg text-warning"></i></span>
					</div>
				</div>
<?php
	else:
?>
				<input type="hidden" name="pkg" value="<?=$pkgname;?>" />
				<input type="hidden" name="confirmed" value="true" />
				<button type="submit" class="btn btn-success" name="pkgconfirm" id="pkgconfirm" value="<?=gettext("확인")?>">
					<i class="fa fa-check icon-embed-btn"></i>
					<?=gettext("확인")?>
				</button>
<?php
	endif;
?>
			</div>
		</div>
	</div>
<?php
endif;
?>
	<div id="unable" style="display: none">
		<?=print_info_box(gettext("검색이 불가능한 시스템 버전입니다."), 'danger')?>
	</div>
<?php

if ($_POST) {
	if ($firmwareupdate) {
		$logfilename = $g['cf_conf_path'] . '/upgrade_log';
	} else {
		$logfilename = $g['cf_conf_path'] . '/pkg_log_' . $pkgname;
	}
}

$pkgname_bold = '<b>' . $pkgname . '</b>';

if ($firmwareupdate) {
	$panel_heading_txt = gettext("시스템 업데이트중");
	$pkg_success_txt = gettext('시스템 업데이트가 성공적으로 완료되었습니다.');
	$pkg_fail_txt = gettext('시스템 업데이트를 실패하였습니다.');
	$pkg_wait_txt = gettext('시스템 업데이트가 완료 될 때까지 기다려주십시오.');
} else if ($pkgmode == 'delete') {
	$panel_heading_txt = gettext("패키지 제거");
	$pkg_success_txt = sprintf(gettext('%1$s가 성공적으로 삭제되었습니다.'), $pkgname_bold);
	$pkg_fail_txt = sprintf(gettext('%1$s삭제를 실패하였습니다.'), $pkgname_bold);
	$pkg_wait_txt = sprintf(gettext('%1$s가 삭제 될 때까지 기다려주십시오.'), $pkgname_bold);
} else if ($pkgmode == 'reinstallall') {
	$panel_heading_txt = gettext("패키지 재설치");
	$pkg_success_txt = gettext('모든 패키지 재설치가 성공적으로 완료되었습니다.');
	$pkg_fail_txt = gettext('모든 패키지 재설치를 실패하였습니다.');
	$pkg_wait_txt = gettext('재설치가 완료 될 때까지 기다려주십시오.');
} else if ($pkgmode == 'reinstallpkg') {
	$panel_heading_txt = gettext("패키지 재설치");
	$pkg_success_txt = sprintf(gettext('%1$s 재설치가 성공적으로 완료되었습니다.'), $pkgname_bold);
	$pkg_fail_txt = sprintf(gettext('%1$s 재설치를 실패하였습니다.'), $pkgname_bold);
	$pkg_wait_txt = sprintf(gettext('%1$s의 재설치가 완료 될 때까지 기다려주십시오.'), $pkgname_bold);
} else {
	$panel_heading_txt = gettext("패키지 설치");
	$pkg_success_txt = sprintf(gettext('%1$s 설치가 성공적으로 완료되었습니다.'), $pkgname_bold);
	$pkg_fail_txt = sprintf(gettext('%1$s 설치를 실패하였습니다.'), $pkgname_bold);
	$pkg_wait_txt = sprintf(gettext('%1$s의 설치가 완료 될 때까지 기다려주십시오.'), $pkgname_bold);
}

if ($confirmed):
	// XXX: What if the user navigates away from this page and then comes back via his/her "Back" button?

	if (isvalidpid($pidfile)) {
		$start_polling = true;
	}
?>
	<input type="hidden" name="id" value="<?=$_REQUEST['id']?>" />
	<input type="hidden" name="mode" value="<?=$pkgmode?>" />
	<input type="hidden" name="pkg" value="<?=$pkgname?>" />
	<input type="hidden" name="completed" value="true" />
	<input type="hidden" name="confirmed" value="true" />
	<input type="hidden" id="reboot_needed" name="reboot_needed" value="no" />

	<div id="countdown" class="text-center"></div>

	<div class="progress" style="display: none;">
		<div id="progressbar" class="progress-bar progress-bar-striped" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width: 1%"></div>
	</div>
	<br />
	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title" id="status"><?=$panel_heading_txt?></h2>
		</div>

		<div class="panel-body">
			<textarea rows="15" class="form-control" id="output" name="output"><?=($completed ? $_POST['output'] : gettext("업데이트 시스템이 초기화되는 동안 기다려주십시오."))?></textarea>
		</div>
	</div>


	<!-- Modal used to display installation notices -->
	<div id="notice" name="notice" class="modal fade" role="dialog">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-body" id="noticebody" name="noticebody" style="background-color:#1e3f75; color:white;">
				</div>
				<div class="modal-footer" style="background-color:#1e3f75; color:white;">
					<button type="button" id="modalbtn" name="modalbtn" class="btn btn-xs btn-success" data-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">Accept</span>
					</button>
				</div>
			</div>
		</div>
	</div>
<?php
endif;
?>
</form>

<?php

ob_flush();

if ($confirmed && !$completed) {
	/* Write out configuration to create a backup prior to pkg install. */
	write_config(gettext("패키지 설치 전 복원 지점 만들기."));

	$progbar = true;
	$upgrade_script = "/usr/local/sbin/{$g['product_name']}-upgrade -y -l {$logfilename}.txt -p {$g['tmp_path']}/{$g['product_name']}-upgrade.sock";

	// Remove the log file before starting

	unlink_if_exists($logfilename . ".txt");

	switch ($pkgmode) {
		case 'delete':
			mwexec_bg("{$upgrade_script} -r {$pkgname}");
			$start_polling = true;
			break;

		case 'reinstallall':
			if (is_array($config['installedpackages']) && is_array($config['installedpackages']['package'])) {
				$progbar = false; // We don't show the progress bar for reinstallall. It would be far too confusing
				mwexec_bg("{$upgrade_script} -i ALL_PACKAGES -f");
				$start_polling = true;
			}

			break;
		case 'reinstallpkg':
			mwexec_bg("{$upgrade_script} -i {$pkgname} -f");
			$start_polling = true;
			break;

		case 'installed':
		default:
			if ($firmwareupdate) {
				mwexec_bg("{$upgrade_script}");
			} else {
				mwexec_bg("{$upgrade_script} -i {$pkgname}");
			}
			$start_polling = true;
			break;
	}
}

$uptodatemsg = gettext("최신 정보를 얻을 수 있습니다.");
$newerversionmsg = gettext("최신 버전을 실행하는 중입니다.");
$confirmlabel = gettext("Confirm Update");
$sysmessage = gettext("상태");

// $completed just means that we are refreshing the page to update any new menu items
// that were installed
if ($completed):
	unlink_if_exists($logfilename . ".json");

	// If this was a firmware update and a reboot was initiated, display the "Rebooting" message
	// and start the countdown timer
	if ($firmwareupdate && $reboot_needed):

?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	time = "<?=$guitimeout?>";
	startCountdown();
});
//]]>
</script>
<?php
	endif;
endif;

?>

<script type="text/javascript">
//<![CDATA[
// Update the progress indicator
// transition = true allows the bar to move at default speed, false = instantaneous
function setProgress(barName, percent, transition) {
	$('.progress').show()
	if (!transition) {
		$('#' + barName).css('transition', 'width 0s ease-in-out');
	}

	$('#' + barName).css('width', percent + '%').attr('aria-valuenow', percent);
}

// Display a success banner
function show_success() {
	$('#final').removeClass("alert-info").addClass("alert-success");
	if ("<?=$pkgmode?>" != "reinstallall") {
		$('#final').html("<?=$pkg_success_txt?>");
	} else {
		$('#final').html("<?=gettext('모든 패키지를 성공적으로 재설치했습니다.')?>");
	}

	$('#final').show();
}

// Display a failure banner
function show_failure() {
	$('#final').removeClass("alert-info");
	$('#final').addClass("alert-danger");
	if ("<?=$pkgmode?>" != "reinstallall") {
		$('#final').html("<?=$pkg_fail_txt?>");
	} else {
		$('#final').html("<?=gettext('패키지 재설치를 실패하였습니다.')?>");
	}
	$('#final').show();
}

// Ask the user to wait a bit
function show_info() {
	$('#final').addClass("alert-info");
	if ("<?=$pkgmode?>" != "reinstallall") {
		$('#final').html("<p><?=$pkg_wait_txt?>" + "</p><p>" +
			"<?=gettext("이 작업은 몇분 정도 걸릴 수 있습니다.")?>" + "</p>");
	} else {
		$('#final').html("<p><?=gettext('모든 패키지 재설치가 완료될 때까지 기다리십시오.')?>" + "</p><p>" +
			"<?=gettext("이 작업은 몇분 정도 걸릴 수 있습니다.")?>" + "</p>");
	}
	$('#final').show();
}

function get_firmware_versions()
{
	var ajaxVersionRequest;

	// Retrieve the version information
	ajaxVersionRequest = $.ajax({
			url: "pkg_mgr_install.php",
			type: "post",
			data: {
					ajax: "ajax",
					getversion: "yes"
			}
		});

	// Deal with the results of the above ajax call
	ajaxVersionRequest.done(function (response, textStatus, jqXHR) {
		var json = new Object;

		json = jQuery.parseJSON(response);

		if (json) {
			$('#installed_version').text(json.installed_version);
			$('#version').text(json.version);

			// If the installed and latest versions are the same, print an "Up to date" message
			if (json.pkg_version_compare == '=') {
				$('#confirmlabel').text("<?=$sysmessage?>");
				$('#uptodate').html('<span class="text-success">' + '<?=$uptodatemsg?>' + "</span>");
			} else if (json.pkg_version_compare == '>') {
				$('#confirmlabel').text("<?=$sysmessage?>");
				$('#uptodate').html('<span class="text-success">' + '<?=$newerversionmsg?>' + "</span>");
			} else { // If they differ display the "Confirm" button
				$('#uptodate').hide();
				$('#confirmlabel').text( "<?=$confirmlabel?>");
				$('#pkgconfirm').show();
			}
		} else {
			$('#uptodate').html('<span class="text-danger">' + 'Unable to check for updates' + "</span>");
		}
	});
}

function getLogsStatus() {
	var ajaxRequest;
	var repeat;
	var progress;

	repeat = true;

	ajaxRequest = $.ajax({
			url: "pkg_mgr_install.php",
			type: "post",
			data: { ajax: "ajax",
					logfilename: "<?=$logfilename?>",
					next_log_line: "0"
			}
		});

	// Deal with the results of the above ajax call
	ajaxRequest.done(function (response, textStatus, jqXHR) {
		var json = new Object;

		json = jQuery.parseJSON(response);

//		alert("JSON data: " + JSON.stringify(json));

		if (json.log != "not_ready") {
			// Write the log file to the "output" textarea
			$('#output').html(json.log);
			scrollToBottom();

			// Update the progress bar
			progress = 0;

			if ("<?=$progbar?>") {
				if (json.data) {
					/*
					 * XXX: There appears to be a bug in pkg that can cause "total"
					 * to be reported as zero
					 *
					 * https://github.com/freebsd/pkg/issues/1336
					 */
					if (json.data.total > 0) {
						setProgress('progressbar', ((json.data.current * 100) / json.data.total), true);
					}

					progress = json.data.total - json.data.current
					if (progress < 0) {
						progress = 0;
					}

				}
			}
			// Now we need to determine if the installation/removal was successful, and tell the user. Not as easy as it sounds :)
			if ((json.pid == "stopped") && (progress == 0) && (json.exitstatus == 0)) {
				show_success();
				repeat = false;

				// The package has been installed/removed successfully but any menu changes that result will not be visible
				// Reloading the page will cause the menu items to be visible and setting reboot_needed will tell the page
				// that the firewall needs to be rebooted if required.

				if (json.reboot_needed == "yes") {
					$('#reboot_needed').val("yes");
				}

				// Display any UI notice the package installer may have created
				if (json.notice.length > 0) {
					var modalheader = "<div align=\"center\" style=\"font-size:24px;\"><strong>NOTICE</strong></div><br>";

					$('#noticebody').html(modalheader + json.notice);
					$('#notice').modal('show');
				} else {
					$('form').submit();
				}
			}

			if ((json.pid == "stopped") && ((progress != 0) || (json.exitstatus != 0))) {
				show_failure();
				repeat = false;
			}
			// ToDo: There are more end conditions we need to catch
		}

		// And maybe do it again
		if (repeat)
			setTimeout(getLogsStatus, 500);
	});
}

function scrollToBottom() {
	$('#output').scrollTop($('#output')[0].scrollHeight);
}

var time = 0;

function checkonline() {
	$.ajax({
		url : "/index.php", // or other resource
		type : "HEAD"
	})
	.done(function() {
		window.location="/index.php";
	});
}

function startCountdown() {
	setInterval(function() {
		if (time == "<?=$guitimeout?>") {
			$('#countdown').html('<h4><?=sprintf(gettext('%1$s페이지를 재부팅하면 %2$s초 안에 자동으로 다시 로드됩니다.'), "<br />", "<span id=\"secs\"></span>");?></h4>');
		}

		if (time > 0) {
			$('#secs').html(time);
			time--;
		} else {
			time = "<?=$guiretry?>";
			$('#countdown').html('<h4><?=sprintf(gettext('%1$s 가 아직 준비되지 않았습니다. 다른 %2$s 초에서 재시도 중입니다.'), "<br />", "<span id=\"secs\"></span>");?></h4>');
			$('#secs').html(time);
			checkonline();
		}
	}, 1000);
}

events.push(function() {
	if ("<?=$start_polling?>") {
		setTimeout(getLogsStatus, 3000);
		show_info();
	}

	// If we are just re-drawing the page after a successful install/remove/reinstall,
	// we only need to re-populate the progress indicator and the status banner
	if ("<?=$completed?>") {
		setProgress('progressbar', 100, false);
		$('#progressbar').addClass("progress-bar-success");
		show_success();
		setTimeout(scrollToBottom, 200);
	}

	if ("<?=$firmwareupdate?>") {
		get_firmware_versions();
	}

	// If the user changes the firmware branch selection, submit the form to record that choice
	$('#fwbranch').on('change', function() {
		$('#confirmed').val("false");

		$('<input>').attr({
			type: 'hidden',
			name: 'refrbranch',
			value: 'true'
		}).appendTo('form');

		$('form').submit();
	});

	$('#modalbtn').click(function() {
		$('form').submit();
	});
});

//]]>
</script>

<?php
include('foot.inc');
