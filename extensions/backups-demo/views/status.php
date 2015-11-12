<?php if (!defined('FW')) die('Forbidden');
/**
 * @var bool $install_is_executing
 * @var bool $install_is_pending
 * @var null|FW_Ext_Backups_Task $executing_task
 * @var null|FW_Ext_Backups_Task $pending_task
 */

$backups = fw_ext( 'backups' ); /** @var FW_Extension_Backups $backups */
?>
<?php if ($install_is_executing): ?>
	<p><img src="<?php echo get_site_url() ?>/wp-admin/images/spinner.gif" alt="Loading"></p>
	<?php if ($executing_task): ?>
		<em class="fw-text-muted"><?php
			echo esc_html($backups->tasks()->get_task_type_title(
				$executing_task->get_type(),
				$executing_task->get_args(),
				$executing_task->get_result()
			));
		?></em>
	<?php elseif ($pending_task): ?>
		<em class="fw-text-muted"><?php
			echo esc_html($backups->tasks()->get_task_type_title(
				$pending_task->get_type(),
				$pending_task->get_args(),
				$pending_task->get_result()
			));
		?></em>
	<?php endif; ?>
<?php elseif ($install_is_pending): ?>
	<p><img src="<?php echo get_site_url() ?>/wp-admin/images/spinner.gif" alt="Loading"></p>
	<em class="fw-text-muted"><?php esc_html_e('Pending', 'fw') ?></em>
<?php endif; ?>
