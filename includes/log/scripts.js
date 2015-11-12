jQuery(function($){
	var inst = {
		localized: _fw_ext_backups_localized,
		$el: $('#fw-ext-backups-log'),
		onUpdating: function(){},
		onUpdate: function(data) {
			this.$el.html(data.log.html);
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

			this.$el.on('click', '#fw-ext-backups-log-show-button a', function() {
				inst.$el.toggleClass('show-list');
			});
		}
	};

	inst.init();
});