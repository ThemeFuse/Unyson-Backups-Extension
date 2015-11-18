<?php if ( ! defined( 'FW' ) ) die( 'Forbidden' );
/**
 * @var FW_Ext_Backups_Demo[] $demos
 */

/**
 * @var FW_Extension_Backups $backups
 */
$backups = fw_ext('backups');

$confirm = esc_html__(
	'IMPORTANT: Installing this demo content will delete the content you currently have on your website.'
	.' However, we create a backup of your current content in (Tools > Backup).'
	.' You can restore the backup from there at any time in the future.',
	'fw'
);
?>
<h2><?php esc_html_e('Demo Content Install', 'fw') ?></h2>

<p></p>
<div class="theme-browser rendered" id="fw-ext-backups-demo-list">
<?php foreach ($demos as $demo): ?>
	<div class="theme fw-ext-backups-demo-item" id="demo-<?php echo esc_attr($demo->get_id()) ?>">
		<div class="theme-screenshot">
			<img src="<?php echo esc_attr($demo->get_screenshot()); ?>" alt="Screenshot" />
		</div>
		<?php if ($demo->get_preview_link()): ?>
			<a class="more-details" target="_blank" href="<?php echo esc_attr($demo->get_preview_link()) ?>">
				<?php esc_html_e('Live Preview', 'fw') ?>
			</a>
		<?php endif; ?>
		<h3 class="theme-name"><?php echo esc_html($demo->get_title()); ?></h3>
		<div class="theme-actions">
			<a class="button button-primary"
			   href="#" onclick="return false;"
			   data-confirm="<?php echo esc_attr($confirm); ?>"
			   data-install="<?php echo esc_attr($demo->get_id()) ?>"><?php esc_html_e('Install', 'fw'); ?></a>
		</div>
	</div>
<?php endforeach; ?>
</div>