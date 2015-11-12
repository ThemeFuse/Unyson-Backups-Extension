<?php if (!defined('FW')) die('Forbidden');
/**
 * @var array $log
 */
?>

<?php if ($log): ?>
	<div id="fw-ext-backups-log-show-button">
		<a href="#" onclick="return false;" class="button-view"><?php esc_html_e('View Activity Log', 'fw'); ?></a>
		<a href="#" onclick="return false;" class="button-hide"><?php esc_html_e('Hide Activity Log', 'fw'); ?></a>
	</div>
	<div id="fw-ext-backups-log-list">
		<ul>
		<?php foreach ($log as $l): ?>
			<?php
			switch (isset($l['type']) ? $l['type'] : '') {
				case 'success': $class = 'fw-text-success'; break;
				case 'info':    $class = 'fw-text-info'; break;
				case 'warning': $class = 'fw-text-warning'; break;
				case 'error':   $class = 'fw-text-danger'; break;
				default:        $class = ''; break;
			}
			?>
			<li>
				<em><?php printf(esc_html__('%s ago', 'fw'), human_time_diff($l['time'])); ?></em>
				<span class="<?php echo esc_attr($class) ?>"><?php echo esc_html($l['title']); ?></span>
			</li>
		<?php endforeach ?>
		</ul>
	</div>
<?php endif; ?>



