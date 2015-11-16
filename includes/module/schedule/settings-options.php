<?php if (!defined('FW')) die('Forbidden');

$limits = apply_filters('fw_ext_backups_schedule_lifetime_limits', array(
	'monthly' => 12,
	'weekly' => 4,
	'daily' => 7,
));

$sub_options = array(
	'type' => 'multi-picker',
	'label' => false,
	'desc'  => false,
	'show_borders' => true,
	'picker' => array(
		'interval' => array(
			'label'   => __('Interval', 'fw'),
			'type'    => 'radio',
			'inline'  => true,
			'choices' => array(
				''  => __('Disabled', 'fw'),
				'monthly' => __('Monthly', 'fw'),
				'weekly' => __('Weekly', 'fw'),
				'daily' => __('Daily', 'fw'),
			),
			'desc'    => __('Select how often do you want to backup your website.', 'fw'),
		)
	),
	'choices' => array(
		'monthly' => array(
			'lifetime' => array(
				'type' => 'short-slider',
				'label' => __('Age Limit', 'fw'),
				'desc' => __('Age limit of backups in months', 'fw'),
				'value' => $limits['monthly'],
				'properties' => array(
					'min' => 1,
					'max' => $limits['monthly'],
					'grid_snap' => true,
				),
			),
		),
		'weekly' => array(
			'lifetime' => array(
				'type' => 'short-slider',
				'label' => __('Age Limit', 'fw'),
				'desc' => __('Age limit of backups in weeks', 'fw'),
				'value' => $limits['weekly'],
				'properties' => array(
					'min' => 1,
					'max' => $limits['weekly'],
					'grid_snap' => true,
				),
			),
		),
		'daily' => array(
			'lifetime' => array(
				'type' => 'short-slider',
				'label' => __('Age Limit', 'fw'),
				'desc' => __('Age limit of backups in days', 'fw'),
				'value' => $limits['daily'],
				'properties' => array(
					'min' => 1,
					'max' => $limits['daily'],
					'grid_snap' => true,
				),
			),
		),
	),
);

$options = array();

if (fw_ext_backups_current_user_can_full()) {
	$options['full'] = array(
		'type'    => 'tab',
		'title'   => __( 'Full Backup', 'fw' ),
		'options' => array(
			'full' => $sub_options,
		),
	);
}

$options['content'] = array(
	'type' => 'tab',
	'title' => __('Content Backup', 'fw'),
	'options' => array(
		'content' => $sub_options,
	),
);
