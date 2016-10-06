<?php if (!defined('FW')) die('Forbidden');
/**
 * @var bool $install_is_executing
 * @var bool $install_is_pending
 * @var null|FW_Ext_Backups_Task $executing_task
 * @var null|FW_Ext_Backups_Task $pending_task
 */

$backups = fw_ext( 'backups' ); /** @var FW_Extension_Backups $backups */
$active_collection = $backups->tasks()->get_active_task_collection();
?>
<div class="fw-ext-backups-demo-status-modal">
	<?php if ($install_is_executing): ?>
		<h2 class="fw-text-muted">
			<img src="<?php echo get_site_url() ?>/wp-admin/images/spinner.gif" alt="Loading" class="wp-spinner" />
			<?php esc_html_e('Installing', 'fw') ?>
		</h2>
		<p class="fw-text-muted"><em><?php esc_html_e('We are currently installing your content.') ?></em></p>

		<?php
		$step = 0;
		foreach ($active_collection->get_tasks() as $_task) {
			++$step;
			if (!$_task->result_is_finished()) {
				break;
			}
		}
		$percentage = $step / count($active_collection->get_tasks()) * 100;
		?>
		<div class="progress-percent"><div style="width: <?php echo $percentage; ?>%;"></div></div>

		<?php if ($executing_task): ?>
			<p class="fw-text-muted"><em><?php echo esc_html($backups->tasks()->get_task_type_title(
				$executing_task->get_type(),
				$executing_task->get_args(),
				$executing_task->get_result()
			)); ?></em></p>
		<?php elseif ($pending_task): ?>
			<p class="fw-text-muted"><em><?php echo esc_html($backups->tasks()->get_task_type_title(
				$pending_task->get_type(),
				$pending_task->get_args(),
				$pending_task->get_result()
			)); ?></em></p>
		<?php endif; ?>
	<?php elseif ($install_is_pending): ?>
		<p><img src="<?php echo get_site_url() ?>/wp-admin/images/spinner.gif" alt="Loading"></p>
		<em class="fw-text-muted"><?php esc_html_e('Pending', 'fw') ?></em>
	<?php endif; ?>

	<?php if ($active_collection && $active_collection->is_cancelable()): ?>
		<a href="#" onclick="fwEvents.trigger('fw:ext:backups-demo:cancel'); return false;"><em><?php
				esc_html_e('Abort', 'fw');
				?></em></a>
	<?php endif; ?>
</div>
