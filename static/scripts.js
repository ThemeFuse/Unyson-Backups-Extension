/**
 * Other scripts use this
 * @type {boolean}
 */
var fw_ext_backups_is_busy = false;

/**
 * Check current status
 */
jQuery(function ($) {
	var inst = {
		localized: _fw_ext_backups_localized,
		getEventName: function(name) {
			return 'fw:ext:backups:status:'+ name;
		},
		timeoutId: 0,
		timeoutTime: 3000,
		/**
		 * 0 - (false) not busy
		 * 1 - (true) busy
		 * 2 - (true) busy and a pending ajax
		 */
		isBusy: 0,
		doAjax: function() {
			if (this.isBusy) {
				this.isBusy = 2;
				return false;
			}

			clearTimeout(this.timeoutId);

			fwEvents.trigger(this.getEventName('updating'));

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: this.localized.ajax_action_status
				}
			})
				.done(_.bind(function(r){
					if (r.success) {
						fwEvents.trigger(this.getEventName('update'), r.data);
					} else {
						fwEvents.trigger(this.getEventName('update-fail'));
					}
				}, this))
				.fail(_.bind(function(jqXHR, textStatus, errorThrown){
					console.error('Ajax error', jqXHR, textStatus, errorThrown);
					fwEvents.trigger(this.getEventName('update-fail'));
				}, this))
				.always(_.bind(function(data_jqXHR, textStatus, jqXHR_errorThrown){
					fwEvents.trigger(this.getEventName('updated'));

					if (this.isBusy === 2) {
						this.isBusy = 0;
						this.doAjax();
					} else {
						this.isBusy = 0;
					}

					this.timeoutId = setTimeout(_.bind(this.doAjax, this), this.timeoutTime);
				}, this));

			return true;
		},
		onUpdate: function(data) {
			this.timeoutTime = data.is_busy ? 3000 : 10000;

			fw_ext_backups_is_busy = data.is_busy;
		},
		init: function(){
			this.init = function(){};

			fwEvents.on(this.getEventName('do-update'), _.bind(function(){ this.doAjax(); }, this));
			fwEvents.on(this.getEventName('update'), _.bind(function(data){ this.onUpdate(data); }, this));

			this.doAjax();
		}
	};

	// let other scripts to listen events
	setTimeout(function(){ inst.init(); }, 12);
});

/**
 * Current tasks status
 */
jQuery(function($){
	var inst = {
		$el: $('#fw-ext-backups-status'),
		failCount: 0,
		onUpdating: function(){},
		onUpdate: function(data) {
			this.$el.html(data.tasks_status.html);
			this.failCount = 0;
		},
		onUpdateFail: function() {
			if (this.failCount > 3) {
				this.$el.html(
					'<span class="fw-text-danger dashicons dashicons-warning" style="vertical-align: text-bottom;">' +
					'</span> <em>ajax errors</em>'
				);
			}
			++this.failCount;
		},
		onUpdated: function() {},
		init: function(){
			fwEvents.on({
				'fw:ext:backups:status:updating': _.bind(this.onUpdating, this),
				'fw:ext:backups:status:update': _.bind(this.onUpdate, this),
				'fw:ext:backups:status:update-fail': _.bind(this.onUpdateFail, this),
				'fw:ext:backups:status:updated': _.bind(this.onUpdated, this)
			});
		}
	};

	inst.init();
});

/**
 * 'Backup Now' buttons
 */
jQuery(function($){
	var inst = {
		localized: _fw_ext_backups_localized,
		$buttons: $('.fw-ext-backups-backup-now'),
		fwLoadingId: 'fw-ext-loading-backup-now',
		onUpdating: function(){
			this.$buttons.addClass('busy');
		},
		onUpdate: function(data) {
			if (!data.is_busy) {
				this.$buttons.removeClass('busy');
			}
		},
		onUpdateFail: function() {},
		onUpdated: function() {},
		init: function(){
			fwEvents.on({
				'fw:ext:backups:status:updating': _.bind(this.onUpdating, this),
				'fw:ext:backups:status:update': _.bind(this.onUpdate, this),
				'fw:ext:backups:status:update-fail': _.bind(this.onUpdateFail, this),
				'fw:ext:backups:status:updated': _.bind(this.onUpdated, this)
			});

			this.$buttons.on('click', function(){
				if ($(this).hasClass('busy')) {
					return;
				}

				fw.loading.show(inst.fwLoadingId);

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: inst.localized.ajax_action_backup,
						full: $(this).attr('data-full')
					}
				})
					.done(function(r){
						if (r.success) {
							fwEvents.trigger('fw:ext:backups:status:do-update');
							window.scrollTo(0, 0);
						} else {
							fw.soleModal.show(
								'fw-ext-backups-restore-error',
								'<h2>Error</h2>'
							);
						}
					})
					.fail(function(jqXHR, textStatus, errorThrown){
						fw.soleModal.show(
							'fw-ext-backups-backup-error',
							'<h2>Ajax error</h2>'+ '<p>'+ String(errorThrown) +'</p>'
						);
					})
					.always(function(data_jqXHR, textStatus, jqXHR_errorThrown){
						fw.loading.hide(inst.fwLoadingId);
					});
			});
		}
	};

	inst.init();
});

/**
 * Archives list
 */
jQuery(function($){
	var inst = {
		localized: _fw_ext_backups_localized,
		$el: $('#fw-ext-backups-archives'),
		fwLoadingId: 'fw-ext-backups-archives',
		modal: new fw.Modal({
			title: ' ',
			size: 'small'
		}),
		onUpdating: function(){},
		onUpdate: function(data) {
			var checkedRadio = inst.$el.find('input[type=radio][name=archive]:checked').val();

			this.$el.html(data.archives.html);

			if (checkedRadio) {
				inst.$el.find('input[type=radio][name=archive]')
					.filter(function(){ return $(this).val() === checkedRadio; })
					.first()
					.trigger('click');
			}

			this.$el[data.archives.count ? 'removeClass' : 'addClass']('no-archives');
		},
		onUpdateFail: function() {},
		onUpdated: function() {},
		init: function(){
			fwEvents.on({
				'fw:ext:backups:status:updating': _.bind(this.onUpdating, this),
				'fw:ext:backups:status:update': _.bind(this.onUpdate, this),
				'fw:ext:backups:status:update-fail': _.bind(this.onUpdateFail, this),
				'fw:ext:backups:status:updated': _.bind(this.onUpdated, this)
			});

			this.modal.on('closing', function(){
				$('#fw-ext-backups-filesystem-form form').append(
					// move the form html back in page, to prevent to be deleted
					$('#request-filesystem-credentials-form')
				);
			});

			this.modal.once('open', function(){
				inst.modal.content.$el.on('submit', function(){
					// click again on Restore button to make an ajax request
					inst.$el.find('.fw-ext-backups-archive-restore-button:first').trigger('click');
				});
			});

			this.$el.on('click', 'input[type=radio][name=archive]', function(){
				inst.$el.find('.fw-ext-backups-archive-restore-button').removeAttr('disabled');
			});

			this.$el.on('click', '.fw-ext-backups-archive-restore-button', function(){
				if (fw_ext_backups_is_busy) {
					window.scrollTo(0, 0);
					return;
				}

				var $radio = inst.$el.find('input[type=radio][name=archive]:checked'),
					file = $radio.val(),
					$button = $(this),
					confirm_message = $button.attr('data-confirm'),
					fs_args = undefined;

				if (!$radio.length && !file) {
					return;
				}

				if (confirm_message) {
					if (!confirm(confirm_message)) {
						return;
					}
				}

				if (inst.modal.content.$el.find('#request-filesystem-credentials-form')) {
					fs_args = {
						hostname: inst.modal.content.$el.find('input[name="hostname"]').val(),
						username: inst.modal.content.$el.find('input[name="username"]').val(),
						password: inst.modal.content.$el.find('input[name="password"]').val(),
						connection_type: inst.modal.content.$el.find('input[name="connection_type"]').val()
					};
				}

				fw.loading.show(inst.fwLoadingId);

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: inst.localized.ajax_action_restore,
						file: file,
						filesystem_args: fs_args
					}
				})
					.done(function(r){
						if (r.success) {
							inst.modal.close();
							fwEvents.trigger('fw:ext:backups:status:do-update');
							inst.$el.find('input[type=radio][name=archive]').prop('checked', false);
							window.scrollTo(0, 0);
						} else if (r.data.request_fs) {
							inst.modal.open(); // here the html is replaced with 'html' attribute value
							inst.modal.content.$el.html('').css('padding', '0 20px').append(
								$('#request-filesystem-credentials-form')
							);
						} else {
							fw.soleModal.show(
								'fw-ext-backups-restore-error',
								r.data.message ? r.data.message : 'Error'
							);
						}
					})
					.fail(function(jqXHR, textStatus, errorThrown){
						fw.soleModal.show(
							'fw-ext-backups-restore-error',
							'<h2>Ajax error</h2>'+ '<p>'+ String(errorThrown) +'</p>'
						);
					})
					.always(function(data_jqXHR, textStatus, jqXHR_errorThrown){
						fw.loading.hide(inst.fwLoadingId);
					});
			});

			this.$el.on('click', '[data-delete-file]', function(){
				var $this = $(this),
					confirm_message = $this.attr('data-confirm');

				if (fw_ext_backups_is_busy) {
					window.scrollTo(0, 0);
					return;
				}

				if (confirm_message) {
					if (!confirm(confirm_message)) {
						return;
					}
				}

				fw.loading.show(inst.fwLoadingId);

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: inst.localized.ajax_action_delete,
						file: $(this).attr('data-delete-file')
					}
				})
					.done(function(r){
						if (r.success) {
							fwEvents.trigger('fw:ext:backups:status:do-update');
						} else {
							fw.soleModal.show(
								'fw-ext-backups-delete-error',
								'<h2>Error</h2>'
							);
						}
					})
					.fail(function(jqXHR, textStatus, errorThrown){
						fw.soleModal.show(
							'fw-ext-backups-delete-error',
							'<h2>Ajax error</h2>'+ '<p>'+ String(errorThrown) +'</p>'
						);
					})
					.always(function(data_jqXHR, textStatus, jqXHR_errorThrown){
						fw.loading.hide(inst.fwLoadingId);
					});
			});

			this.$el.on('click', '[data-download-file]', function(e) {
				if (fw_ext_backups_is_busy) {
					e.preventDefault();
					window.scrollTo(0, 0);
					return;
				}
			});
		}
	};

	inst.init();
});

/**
 * Schedule settings
 */
jQuery(function($){
	var inst = {
		modal: new fw.OptionsModal(),
		localized: _fw_ext_backups_localized.schedule,
		isBusy: false,
		fwLoadingId: 'fw-ext-backups-schedule',
		openModal: function() {
			if (this.isBusy) {
				return false;
			}

			this.isBusy = true;
			fw.loading.show(inst.fwLoadingId);

			$.ajax({
					url: ajaxurl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: inst.localized.ajax_action.get_settings
					}
				})
				.done(function(r){
					if (r.success) {
						inst.modal.set('options', r.data.options);

						inst.modal.off('change:values', inst.onModalValuesChange);
						inst.modal.set('values', r.data.values);
						inst.modal.on('change:values', inst.onModalValuesChange);

						inst.modal.open();
					} else {
						fw.soleModal.show(inst.fwLoadingId, '<h2>Error</h2>');
					}
				})
				.fail(function(jqXHR, textStatus, errorThrown){
					fw.soleModal.show(
						inst.fwLoadingId,
						'<h2>Ajax error</h2>'+ '<p>'+ String(errorThrown) +'</p>'
					);
				})
				.always(function(data_jqXHR, textStatus, jqXHR_errorThrown){
					fw.loading.hide(inst.fwLoadingId);
					inst.isBusy = false;
				});
		},
		onModalValuesChange: function(){
			if (inst.isBusy) {
				console.error('The script is busy');
				return;
			}

			inst.isBusy = true;
			fw.loading.show(inst.fwLoadingId);

			$.ajax({
					url: ajaxurl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: inst.localized.ajax_action.set_settings,
						values: inst.modal.get('values')
					}
				})
				.done(function(r){
					if (r.success) {
						fwEvents.trigger('fw:ext:backups:status:do-update');
					} else {
						fw.soleModal.show(inst.fwLoadingId, '<h2>Error</h2>');
					}
				})
				.fail(function(jqXHR, textStatus, errorThrown){
					fw.soleModal.show(
						inst.fwLoadingId,
						'<h2>Ajax error</h2>'+ '<p>'+ String(errorThrown) +'</p>'
					);
				})
				.always(function(data_jqXHR, textStatus, jqXHR_errorThrown){
					fw.loading.hide(inst.fwLoadingId);
					inst.isBusy = false;
				});
		},
		init: function(){
			this.modal.set('title', this.localized.popup_title);

			$('#fw-ext-backups-edit-schedule').on('click', function(){
				inst.openModal();
			});

			this.modal.on('change:values', this.onModalValuesChange);
		}
	};

	inst.init();
});

/**
 * Schedule status
 */
jQuery(function($){
	var inst = {
		$el: $('#fw-ext-backups-schedule-status'),
		onUpdating: function(){},
		onUpdate: function(data) {
			this.$el.html(data.schedule.status_html);
		},
		onUpdateFail: function() {},
		onUpdated: function() {},
		init: function(){
			fwEvents.on({
				'fw:ext:backups:status:updating': _.bind(this.onUpdating, this),
				'fw:ext:backups:status:update': _.bind(this.onUpdate, this),
				'fw:ext:backups:status:update-fail': _.bind(this.onUpdateFail, this),
				'fw:ext:backups:status:updated': _.bind(this.onUpdated, this)
			});
		}
	};

	inst.init();
});

/**
 * "Cancel" functionality
 */
jQuery(function($){
	var inst = {
		localized: _fw_ext_backups_localized,
		isBusy: false,
		fwLoadingId: 'fw-ext-backups-cancel',
		doCancel: function(){
			if (this.isBusy) {
				return;
			} else {
				if (!confirm(this.localized.l10n.abort_confirm)) {
					return;
				}

				this.isBusy = true;
			}

			inst.isBusy = true;
			fw.loading.show(inst.fwLoadingId);

			$.ajax({
					url: ajaxurl,
					data: {
						action: inst.localized.ajax_action_cancel
					},
					type: 'POST',
					dataType: 'json'
				})
				.done(function(r){
					if (r.success) {
						fwEvents.trigger('fw:ext:backups:status:do-update');
					} else {
						console.warn('Cancel failed');
					}
				})
				.fail(function(jqXHR, textStatus, errorThrown){
					fw.soleModal.show(
						'fw-ext-backups-demo-install-error',
						'<h2>Ajax error</h2>'+ '<p>'+ String(errorThrown) +'</p>'
					);
				})
				.always(function(data_jqXHR, textStatus, jqXHR_errorThrown){
					inst.isBusy = false;
					fw.loading.hide(inst.fwLoadingId);
				});
		},
		init: function(){
			var that = this;

			fwEvents.on('fw:ext:backups:cancel', function(){
				that.doCancel();
			});
		}
	};

	inst.init();
});

/**
 * If loopback request failed, execute steps via ajax
 * @since 2.0.5
 */
jQuery(function($){
	if (typeof fw_ext_backups_loopback_failed == 'undefined') {
		return;
	}

	var inst = {
		running: false,
		isBusy: false,
		onUpdate: function(data) {
			this.running = data.is_busy;
			this.executeNextStep(data.ajax_steps.token, data.ajax_steps.active_tasks_hash);
		},
		executeNextStep: function(token, activeTasksHash){
			if (!this.running || this.isBusy) {
				return false;
			}

			this.isBusy = true;

			$.ajax({
				url: ajaxurl,
				data: {
					action: 'fw:ext:backups:continue-pending-task',
					token: token,
					active_tasks_hash: activeTasksHash
				},
				type: 'POST',
				dataType: 'json'
			})
				.done(function(r){ console.log(r);
					if (r.success) {
						fwEvents.trigger('fw:ext:backups:status:do-update');
					} else {
						console.error('Ajax execution failed');
						// execution will try to continue on next (auto) update
					}
				})
				.fail(_.bind(function(jqXHR, textStatus, errorThrown){
					console.error('Ajax error: '+ String(errorThrown));
				}, this))
				.always(_.bind(function(data_jqXHR, textStatus, jqXHR_errorThrown){
					this.isBusy = false;
				}, this));
		},
		init: function(){
			fwEvents.on('fw:ext:backups:status:update', _.bind(this.onUpdate, this));
		}
	};

	inst.init();
});
