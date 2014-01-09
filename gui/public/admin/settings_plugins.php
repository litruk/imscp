<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2014 by i-MSCP Team
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * @category    iMSCP
 * @package     iMSCP_Core
 * @subpackage  Admin_Plugin
 * @copyright   2010-2014 by i-MSCP Team
 * @author      Laurent Declercq <l.declercq@nuxwin.com>
 * @link        http://www.i-mscp.net i-MSCP Home Site
 * @license     http://www.gnu.org/licenses/gpl-2.0.html GPL v2
 */

/***********************************************************************************************************************
 * Functions
 */

/**
 * Upload plugin archive into the gui/plugins directory
 *
 * Supported archives: zip tar.gz and tar.bz2
 *
 * @param iMSCP_Plugin_Manager $pluginManager
 * @return bool TRUE on success, FALSE on failure
 */
function admin_pluginManagerUploadPlugin($pluginManager)
{
	$pluginDirectory = $pluginManager->getPluginDirectory();
	$tmpDirectory = GUI_ROOT_DIR . '/data/tmp';
	$ret = false;

	if (isset($_FILES['plugin_archive'])) {
		$beforeMove = function ($tmpDirectory) {
			$tmpFilePath = $_FILES['plugin_archive']['tmp_name'];

			if (!checkMimeType($tmpFilePath, array('application/x-gzip', 'application/x-bzip2', 'application/zip'))) {
				set_page_message(tr('Only tar.gz, tar.bz2 and zip archives are supported.'), 'error');
				return false;
			}

			$pluginArchiveSize = $_FILES['plugin_archive']['size'];
			$maxUploadFileSize = utils_getMaxFileUpload();

			if ($pluginArchiveSize > $maxUploadFileSize) {
				set_page_message(
					tr(
						'Plugin archive exceeds the maximum upload size (%s). Max upload size is: %s.',
						bytesHuman($pluginArchiveSize),
						bytesHuman($maxUploadFileSize)
					),
					'error'
				);
				return false;
			}

			return $tmpDirectory . '/' . $_FILES['plugin_archive']['name'];
		};

		if (($archPath = utils_uploadFile('plugin_archive', array($beforeMove, $tmpDirectory))) !== false) {
			$zipArch = (substr($archPath, -3) === 'zip');

			try {
				if (!$zipArch) {
					$arch = new PharData($archPath);
					$name = $arch->getBasename();

					if (!isset($arch["$name/$name.php"])) {
						throw new iMSCP_Exception(tr('File %s is missing in plugin directory.', "$name.php"));
					}
				} else {
					$arch = new ZipArchive;

					if ($arch->open($archPath) === true) {
						if (($name = $arch->getNameIndex(0, ZIPARCHIVE::FL_UNCHANGED)) !== false) {
							$name = rtrim($name, '/');

							$index = $arch->locateName("$name.php", ZipArchive::FL_NODIR);

							if ($index !== false) {
								if (($stats = $arch->statIndex($index))) {
									if ($stats['name'] != "$name/$name.php") {
										throw new iMSCP_Exception(
											tr('File %s has not been found in plugin directory.', "$name.php")
										);
									}
								} else {
									throw new iMSCP_Exception(tr('Unable to get stats for file %s.', "$name.php"));
								}
							} else {
								throw new iMSCP_Exception(
									tr('File %s has not been found in plugin directory.', "$name.php")
								);
							}
						} else {
							throw new iMSCP_Exception(tr('Unable to find plugin root directory.'));
						}
					} else {
						throw new iMSCP_Exception(tr('Unable to open archive.'));
					}
				}

				if ($pluginManager->isPluginKnown($name) && $pluginManager->isPluginProtected($name)) {
					throw new iMSCP_Exception(tr('You are not allowed to update a protected plugin.'));
				}

				# Backup current plugin directory in temporary directory if exists
				if (is_dir("$pluginDirectory/$name")) {
					if (!@rename("$pluginDirectory/$name", "$tmpDirectory/$name")) {
						throw new iMSCP_Exception(
							tr('Unable to backup %s plugin directory.', "<strong>$name</strong>")
						);
					}
				}

				if (!$zipArch) {
					$arch->extractTo($pluginDirectory, null, true);
				} elseif (!$arch->extractTo($pluginDirectory)) {
					throw new iMSCP_Exception(tr('Unable to extract plugin archive.'));
				}

				$ret = true;
			} catch (Exception $e) {
				if ($e instanceof iMSCP_Exception) {
					set_page_message($e->getMessage(), 'error');
				} else {
					set_page_message(tr('Unable to extract plugin archive: %s', $e->getMessage()), 'error');
				}

				if (isset($name) && is_dir("$tmpDirectory/$name")) {
					// Restore previous plugin directory on error
					if (!@rename("$tmpDirectory/$name", "$pluginDirectory/$name")) {
						set_page_message(tr('Unable to restore %s plugin directory', "<strong>$name</strong>"), 'error');
					}
				}
			}

			@unlink($archPath); // Cleanup
			if (isset($name)) utils_removeDir("$tmpDirectory/$name"); // cleanup
		}
	} else {
		showBadRequestErrorPage();
	}

	return $ret;
}

/**
 * Translate plugin status
 *
 * @param string $rawPluginStatus Raw plugin status
 * @return string Translated plugin status
 */
function admin_pluginManagerTrStatus($rawPluginStatus)
{
	switch ($rawPluginStatus) {
		case 'uninstalled':
			return tr('Uninstalled');
		case 'toinstall':
			return tr('Installation in progress...');
		case 'toenable':
			return tr('Activation in progress...');
		case 'todisable':
			return tr('Deactivation in progress...');
		case 'tochange':
			return tr('Change in progress...');
		case 'toupdate':
			return tr('Update in progress...');
			break;
		case 'enabled':
			return tr('Activated');
		case 'disabled':
			return tr('Deactivated');
		case 'touninstall':
			return tr('Uninstallation in progress...');
		default:
			return tr('Unknown status');
	}
}

/**
 * Generates plugin list
 *
 * @param iMSCP_pTemplate $tpl Template engine instance
 * @param iMSCP_Plugin_Manager $pluginManager
 * @return void
 */
function admin_pluginManagerGeneratePluginList($tpl, $pluginManager)
{
	$pluginList = $pluginManager->getPluginList('Action', false);

	if (empty($pluginList)) {
		$tpl->assign('PLUGINS_BLOCK', '');
		set_page_message(tr('Plugin list is empty.'), 'info');
	} else {
		sort($pluginList);
		$cacheFile = PERSISTENT_PATH . '/protected_plugins.php';
		$protectTooltip = '<span style="color:rgb(96, 0, 14);cursor:pointer;" title="' .
			tr('To unprotect this plugin, you must edit the %s file', $cacheFile) . '">' . tr('Protected') . '</span>';

		foreach ($pluginList as $pluginName) {
			$pluginInfo = $pluginManager->getPluginInfo($pluginName);
			$pluginStatus = $pluginManager->getPluginStatus($pluginName);


			if(is_array($pluginInfo['author'])) {
				$pluginInfo['author'] = implode(' ' . tr('and') . ' ', $pluginInfo['author']);
			}

			$tpl->assign(
				array(
					'PLUGIN_NAME' => tohtml($pluginName),
					'PLUGIN_DESCRIPTION' => tohtml($pluginInfo['desc']),
					'PLUGIN_STATUS' => ($pluginManager->hasPluginError($pluginName))
						? tohtml(tr('Unknown Error')) : tohtml(admin_pluginManagerTrStatus($pluginStatus)),
					'PLUGIN_VERSION' => tohtml($pluginInfo['version']),
					'PLUGIN_AUTHOR' => tohtml($pluginInfo['author']),
					'PLUGIN_MAILTO' => tohtml($pluginInfo['email']),
					'PLUGIN_SITE' => tohtml($pluginInfo['url'])
				)
			);

			if ($pluginManager->hasPluginError($pluginName)) {
				$tpl->assign(
					'PLUGIN_STATUS_DETAILS',
					tr('An unexpected error occurred: %s', '<br /><br />' . $pluginManager->getPluginError($pluginName))
				);
				$tpl->parse('PLUGIN_STATUS_DETAILS_BLOCK', 'plugin_status_details_block');
				$tpl->assign(
					array(
						'PLUGIN_DEACTIVATE_LINK' => '',
						'PLUGIN_ACTIVATE_LINK' => ''
					)
				);
			} else {
				$tpl->assign('PLUGIN_STATUS_DETAILS_BLOCK', '');

				if ($pluginManager->isPluginProtected($pluginName)) {
					$tpl->assign(
						array(
							'PLUGIN_ACTIVATE_LINK' => $protectTooltip,
							'PLUGIN_DEACTIVATE_LINK' => ''
						)
					);
				} elseif ($pluginManager->isPluginUninstalled($pluginName)) { // Uninstalled plugin
					$tpl->assign(
						array(
							'PLUGIN_DEACTIVATE_LINK' => '',
							'ACTIVATE_ACTION' => 'install',
							'TR_ACTIVATE_TOOLTIP' => tr('Install this plugin'),
							'UNINSTALL_ACTION' => 'delete',
							'TR_UNINSTALL_TOOLTIP' => tr('Delete this plugin')
						)
					);

					$tpl->parse('PLUGIN_ACTIVATE_LINK', 'plugin_activate_link');
				} elseif ($pluginManager->isPluginDisabled($pluginName)) { // Disabled plugin
					$tpl->assign(
						array(
							'PLUGIN_DEACTIVATE_LINK' => '',
							'ACTIVATE_ACTION' => 'enable',
							'TR_ACTIVATE_TOOLTIP' => tr('Activate this plugin'),
							'UNINSTALL_ACTION' => $pluginManager->isPluginUninstallable($pluginName)
								? 'uninstall' : 'delete',
							'TR_UNINSTALL_TOOLTIP' => $pluginManager->isPluginUninstallable($pluginName)
								? tr('Uninstall this plugin') : tr('Delete this plugin'),
						)
					);

					$tpl->parse('PLUGIN_ACTIVATE_LINK', 'plugin_activate_link');
				} elseif ($pluginManager->isPluginEnabled($pluginName)) { // Enabled plugin
					$tpl->assign('PLUGIN_ACTIVATE_LINK', '');
					$tpl->parse('PLUGIN_DEACTIVATE_LINK', 'plugin_deactivate_link');
				} else { // Plugin with unknown status - TODO add specific action for such case
					$tpl->assign(
						array(
							'PLUGIN_DEACTIVATE_LINK' => '',
							'PLUGIN_ACTIVATE_LINK' => ''
						)
					);
				}
			}

			$tpl->parse('PLUGIN_BLOCK', '.plugin_block');
		}
	}
}

/**
 * Do the given action for the given plugin
 *
 * @param iMSCP_Plugin_Manager $pluginManager
 * @param string $pluginName Plugin name
 * @param string $action Action (activate|deactivate|delete|protect)
 * @return void
 */
function admin_pluginManagerDoAction($pluginManager, $pluginName, $action)
{
	if ($pluginManager->isPluginKnown($pluginName)) {
		if ($pluginManager->isPluginProtected($pluginName)) {
			set_page_message(tr('Plugin %s is protected.', "<strong>$pluginName</strong>"), 'warning');
		} elseif ($action == 'install' && $pluginManager->isPluginInstalled($pluginName)) {
			set_page_message(tr('Plugin %s is already installed.', "<strong>$pluginName</strong>"), 'warning');
		} elseif ($action == 'enable' && $pluginManager->isPluginEnabled($pluginName)) {
			set_page_message(tr('Plugin %s is already activated.', "<strong>$pluginName</strong>"), 'warning');
		} elseif ($action == 'disable' && $pluginManager->isPluginDisabled($pluginName)) {
			set_page_message(tr('Plugin %s is already deactivated.', "<strong>$pluginName</strong>"), 'warning');
		} elseif ($action == 'uninstall' && $pluginManager->isPluginUninstalled($pluginName)) {
			set_page_message(tr('Plugin %s is already uninstalled.', "<strong>$pluginName</strong>"), 'warning');
		} else {
			$ret = $pluginManager->{"plugin{$action}"}($pluginName);

			if ($ret == iMSCP_Plugin_Manager::ACTION_FAILURE || $ret == iMSCP_Plugin_Manager::ACTION_STOPPED) {
				$submessage = ($ret == iMSCP_Plugin_Manager::ACTION_FAILURE)
					? tr('Action has failed.') : tr('Action has been stopped.');

				switch ($action) {
					case 'install':
						$message = tr(
							'Plugin Manager: Unable to install the %s plugin: %s',
							"<strong>$pluginName</strong>",
							$submessage
						);
						break;
					case 'enable':
						$message = tr(
							'Plugin Manager: Unable to activate the %s plugin: %s',
							"<strong>$pluginName</strong>",
							$submessage
						);
						break;
					case 'disable':
						$message = tr(
							'Plugin Manager: Unable to deactivate the %s plugin: %s',
							"<strong>$pluginName</strong>",
							$submessage
						);
						break;
					case 'change':
						$message = tr(
							'Plugin Manager: Unable to change the %s plugin: %s',
							"<strong>$pluginName</strong>",
							$submessage
						);
						break;
					case 'update':
						$message = tr(
							'Plugin Manager: Unable to update the %s plugin: %s',
							"<strong>$pluginName</strong>",
							$submessage
						);
						break;
					case 'uninstall':
						$message = tr(
							'Plugin Manager: Unable to uninstall the %s plugin: %s',
							"<strong>$pluginName</strong>",
							$submessage
						);
						break;
					case 'delete':
						$message = tr('Plugin Manager: Unable to delete the %s plugin: %s',
							"<strong>$pluginName</strong>",
							$submessage
						);
						break;
					case 'protect':
						$message = tr('Plugin Manager: Unable to protect the %s plugin: %s',
							"<strong>$pluginName</strong>",
							$submessage
						);
						break;
					default:
						$message = tr(
							'Plugin Manager: Unable to protect the %s plugin: %s',
							"<strong>$pluginName</strong>",
							$submessage
						);
				}

				set_page_message($message, 'error');
			} else {
				if ($pluginManager->hasPluginBackend($pluginName)) {
					switch ($action) {
						case 'install':
							$message = tr(
								'Plugin %s successfully scheduled for installation.', "<strong>$pluginName</strong>"
							);
							break;
						case 'enable':
							$message = tr(
								'Plugin %s successfully scheduled for activation.', "<strong>$pluginName</strong>"
							);
							break;
						case 'disable':
							$message = tr(
								'Plugin %s successfully scheduled for deactivation.', "<strong>$pluginName</strong>"
							);
							break;
						case 'change':
							$message = tr(
								'Plugin %s successfully scheduled for change.', "<strong>$pluginName</strong>"
							);
							break;
						case 'update':
							$message = tr(
								'Plugin %s successfully scheduled for update.', "<strong>$pluginName</strong>"
							);
							break;
						case 'uninstall':
							$message = tr(
								'Plugin %s successfully scheduled for uninstallation.', "<strong>$pluginName</strong>"
							);
							break;
						default:
							$message = tr('Plugin %s successfully deleted.', "<strong>$pluginName</strong>");
					}
					set_page_message($message, 'success');
				} else {
					switch ($action) {
						case 'install':
							$message = tr('Plugin %s successfully installed.', "<strong>$pluginName</strong>");
							break;
						case 'enable':
							$message = tr('Plugin %s successfully activated.', "<strong>$pluginName</strong>");
							break;
						case 'disable':
							$message = tr('Plugin %s successfully deactivated.', "<strong>$pluginName</strong>");
							break;
						case 'change':
							$message = tr('Plugin %s successfully changed.', "<strong>$pluginName</strong>");
							break;
						case 'update':
							$message = tr('Plugin %s successfully updated.', "<strong>$pluginName</strong>");
							break;
						case 'uninstall':
							$message = tr('Plugin %s successfully uninstalled.', "<strong>$pluginName</strong>");
							break;
						case 'protect':
							$message = tr('Plugin %s successfully protected.', "<strong>$pluginName</strong>");
							break;
						default:
							$message = tr('Plugin %s successfully deleted.', "<strong>$pluginName</strong>");
					}

					set_page_message($message, 'success');
				}
			}
		}
	} else {
		showBadRequestErrorPage();
	}
}

/**
 * Do bulk action (activate|deactivate|protect)
 *
 * @param iMSCP_Plugin_Manager $pluginManager
 * @return void
 */
function admin_pluginManagerDoBulkAction($pluginManager)
{
	$action = clean_input($_POST['bulk_actions']);

	if (!in_array($action, array('install', 'enable', 'disable', 'uninstall', 'delete', 'protect'))) {
		showBadRequestErrorPage();
	} elseif (isset($_POST['checked']) && is_array($_POST['checked']) && !empty($_POST['checked'])) {
		foreach ($_POST['checked'] as $pluginName) {
			admin_pluginManagerDoAction($pluginManager, clean_input($pluginName), $action);
		}
	} else {
		set_page_message(tr('You must select a plugin.'), 'error');
	}
}

/**
 * Update plugin list
 *
 * @param iMSCP_Plugin_Manager $pluginManager
 * @return void
 */
function admin_pluginManagerUpdatePluginList($pluginManager)
{
	$responses = iMSCP_Events_Manager::getInstance()->dispatch(
		iMSCP_Events::onBeforeUpdatePluginList, array('pluginManager' => $pluginManager)
	);

	if (!$responses->isStopped()) {
		$updateInfo = $pluginManager->updatePluginList();

		iMSCP_Events_Manager::getInstance()->dispatch(
			iMSCP_Events::onAfterUpdatePluginList, array('pluginManager' => $pluginManager)
		);

		set_page_message(
			tr('Plugin list successfully updated.') . '<br />' .
			tr(
				'%s new plugin(s) found, %s plugin(s) updated, %s plugin(s) changed, and %s plugin(s) deleted.',
				"<strong>{$updateInfo['new']}</strong>",
				"<strong>{$updateInfo['updated']}</strong>",
				"<strong>{$updateInfo['changed']}</strong>",
				"<strong>{$updateInfo['deleted']}</strong>"
			),
			'success'
		);
	}
}

/***********************************************************************************************************************
 * Main
 */

// Include core library
require 'imscp-lib.php';

iMSCP_Events_Manager::getInstance()->dispatch(iMSCP_Events::onAdminScriptStart);

check_login('admin');

/** @var iMSCP_Plugin_Manager $pluginManager */
$pluginManager = iMSCP_Registry::get('pluginManager');

// Dispatches the request
if (!empty($_REQUEST) || !empty($_FILES)) {
	if (isset($_GET['update_plugin_list'])) {
		admin_pluginManagerUpdatePluginList($pluginManager);
	} elseif (isset($_GET['install'])) {
		admin_pluginManagerDoAction($pluginManager, clean_input($_GET['install']), 'install');
	} elseif (isset($_GET['enable'])) {
		admin_pluginManagerDoAction($pluginManager, clean_input($_GET['enable']), 'enable');
	} elseif (isset($_GET['disable'])) {
		admin_pluginManagerDoAction($pluginManager, clean_input($_GET['disable']), 'disable');
	} elseif (isset($_GET['uninstall'])) {
		admin_pluginManagerDoAction($pluginManager, clean_input($_GET['uninstall']), 'uninstall');
	} elseif (isset($_GET['delete'])) {
		admin_pluginManagerDoAction($pluginManager, clean_input($_GET['delete']), 'delete');
	} elseif (isset($_GET['protect'])) {
		admin_pluginManagerDoAction($pluginManager, clean_input($_GET['protect']), 'protect');
	} elseif (isset($_GET['retry'])) {
		$pluginName = clean_input($_GET['retry']);

		if ($pluginManager->isPluginKnown($pluginName)) {
			switch ($pluginManager->getPluginStatus($pluginName)) {
				case 'toinstall':
					$action = 'install';
					break;
				case 'toenable':
					$action = 'enable';
					break;
				case 'todisable':
					$action = 'disable';
					break;
				case 'tochange':
					$action = 'change';
					break;
				case 'toupdate':
					$action = 'update';
					break;
				case 'touninstall':
					$action = 'uninstall';
					break;
				case 'todelete':
					$action = 'delete';
					break;
				default:
					showBadRequestErrorPage();
			}

			admin_pluginManagerDoAction($pluginManager, $pluginName, $action);
		} else {
			showBadRequestErrorPage();
		}
	} elseif (isset($_POST['bulk_actions'])) {
		admin_pluginManagerDoBulkAction($pluginManager);
	} elseif (!empty($_FILES)) {
		if (admin_pluginManagerUploadPlugin($pluginManager)) {
			set_page_message(tr('Plugin successfully uploaded.'), 'success');
			admin_pluginManagerUpdatePluginList($pluginManager);
		}
	}

	redirectTo('settings_plugins.php');
}

/** @var $cfg iMSCP_Config_Handler_File */
$cfg = iMSCP_Registry::get('config');

$tpl = new iMSCP_pTemplate();
$tpl->define_dynamic(
	array(
		'layout' => 'shared/layouts/ui.tpl',
		'page' => 'admin/settings_plugins.tpl',
		'page_message' => 'layout',
		'plugins_block' => 'page',
		'plugin_block' => 'plugins_block',
		'plugin_status_details_block' => 'plugin_block',
		'plugin_activate_link' => 'plugin_block',
		'plugin_deactivate_link' => 'plugin_block'
	)
);

$tpl->assign(
	array(
		'TR_PAGE_TITLE' => tr('Admin / Settings / Plugin Management'),
		'ISP_LOGO' => layout_getUserLogo(),
		'DATATABLE_TRANSLATIONS' => getDataTablesPluginTranslations(),
		'TR_BULK_ACTIONS' => tr('Bulk Actions'),
		'TR_PLUGIN' => tr('Plugin'),
		'TR_DESCRIPTION' => tr('Description'),
		'TR_STATUS' => tr('Status'),
		'TR_ACTIONS' => tr('Actions'),
		'TR_INSTALL' => tr('Install'),
		'TR_ACTIVATE' => tr('Activate'),
		'TR_DEACTIVATE_TOOLTIP' => tr('Deactivate this plugin'),
		'TR_DEACTIVATE' => tr('Deactivate'),
		'TR_UNINSTALL' => tr('Uninstall'),
		'TR_PROTECT' => tojs(tr('Protect', true)),
		'TR_DELETE' => tr('Delete'),
		'TR_PROTECT_TOOLTIP' => tr('Protect this plugin'),
		'TR_PLUGIN_CONFIRMATION_TITLE' => tojs(tr('Confirmation for plugin protection', true)),
		'TR_PROTECT_CONFIRMATION' => tr("If you protect a plugin, you'll no longer be able to deactivate it from the plugin management interface."),
		'TR_CANCEL' => tojs(tr('Cancel', true)),
		'TR_VERSION' => tr('Version'),
		'TR_BY' => tr('By'),
		'TR_VISIT_PLUGIN_SITE' => tr('Visit plugin site'),
		'TR_UPDATE_PLUGIN_LIST' => tr('Update plugin list'),
		'TR_APPLY' => tr('Apply'),
		'TR_PLUGIN_UPLOAD' => tr('Plugins Upload'),
		'TR_UPLOAD' => tr('Upload'),
		'TR_PLUGIN_ARCHIVE' => tr('Plugin archive'),
		'TR_PLUGIN_ARCHIVE_TOOLTIP' => tr('Only tar.gz, tar.bz2 and zip archives are accepted'),
		'TR_PLUGIN_HINT' => tr('Plugins hook into i-MSCP to extend its functionality with custom features. Plugins are developed independently from the core i-MSCP application by thousands of developers all over the world. You can find new plugins to install by browsing the %s.', true, '<u><a href="http://plugins.i-mscp.net" target="_blank">' . tr('i-MSCP plugin repository') . '</a></u>'),
		'TR_CLICK_FOR_MORE_DETAILS' => tr('Click here for more details'),
		'TR_ERROR_DETAILS' => tojs(tr('Error details', true)),
		'TR_FORCE_RETRY' => tojs(tr('Force retry', true)),
		'TR_CLOSE' => tojs(tr('Close', true))
	)
);

generateNavigation($tpl);
admin_pluginManagerGeneratePluginList($tpl, $pluginManager);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');

iMSCP_Events_Manager::getInstance()->dispatch(iMSCP_Events::onAdminScriptEnd, array('templateEngine' => $tpl));

$tpl->prnt();

unsetMessages();
