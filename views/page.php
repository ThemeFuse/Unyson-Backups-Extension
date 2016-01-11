<?php if (!defined('FW')) die('Forbidden');
/**
 * @var string $archives_html
 */
?>

<?php
$backups = fw_ext( 'backups' ); /** @var FW_Extension_Backups $backups */
$page_url = $backups->get_page_url();
?>
<h2><?php esc_html_e('Backup', 'fw') ?> <span id="fw-ext-backups-status"></span></h2>

<div>
	<?php if ( !class_exists('ZipArchive') ): ?>
		<div class="error below-h2">
			<p>
				<strong><?php _e( 'Important', 'fw' ); ?></strong>:
				<?php printf(
					__( 'You need to activate %s.', 'fw' ),
					'<a href="http://php.net/manual/en/book.zip.php" target="_blank">'. __('zip extension', 'fw') .'</a>'
				); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ($http_loopback_warning = fw_ext_backups_loopback_test()) : ?>
		<div class="error">
			<p><strong><?php _e( 'Important', 'fw' ); ?>:</strong> <?php echo $http_loopback_warning; ?></p>
		</div>
		<script type="text/javascript">var fw_ext_backups_loopback_failed = true;</script>
	<?php endif; ?>

	<div class="fw-ext-backups-description">
		<p class="description"><?php esc_html_e( 'Here you can create a backup schedule for your website.', 'fw' ); ?></p>
		<ul>
			<?php if (fw_ext_backups_current_user_can_full()): ?>
			<li>
				<span class="description">
				<strong><?php esc_html_e('Full Backup', 'fw'); ?></strong>
				- <?php esc_html_e('will save your themes, plugins, uploads and full database.'); ?>
				</span>
			</li>
			<?php endif; ?>
			<li>
				<span class="description">
				<strong><?php esc_html_e('Content Backup', 'fw'); ?></strong>
				- <?php esc_html_e('will save your uploads and database without private data like users, admin email, etc.'); ?>
				</span>
			</li>
		</ul>
	</div>

	<div id="fw-ext-backups-schedule-status"></div>

	<div>
		<a href="#" onclick="return false;" id="fw-ext-backups-edit-schedule"
		   class="button button-primary"><?php esc_html_e( 'Edit Backup Schedule', 'fw' ) ?></a>
		&nbsp;
		<?php if (fw_ext_backups_current_user_can_full()): ?>
		<a href="#" onclick="return false;" id="fw-ext-backups-full-backup-now"
		   class="button fw-ext-backups-backup-now" data-full="1"><?php esc_html_e('Create Full Backup Now', 'fw') ?></a>
		&nbsp;
		<?php endif; ?>
		<a href="#" onclick="return false;" id="fw-ext-backups-content-backup-now"
		   class="button fw-ext-backups-backup-now" data-full=""><?php esc_html_e('Create Content Backup Now', 'fw'); ?></a>
	</div>
</div>

<br>
<h3><?php _e( 'Archives', 'fw' ) ?></h3>

<div id="fw-ext-backups-archives"><?php echo $archives_html; ?></div>

<br>
<?php do_action('fw_ext_backups_page_footer'); ?>