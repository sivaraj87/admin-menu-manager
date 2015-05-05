var MenuItemOptionsView = require('views/menu-item-options');

var MenuItemView = Backbone.View.extend({
	tagName      : 'li',
	template     : require('templates/menu-item'),
	attributes   : function () {
		// Return model data
		return {
			class        : this.model.get(4),
			id           : this.model.get(5),
			'aria-hidden': this.model.get(4).indexOf('wp-menu-separator') > -1,
			'data-slug'  : this.model.get(2)
		};
	},
	isEditing    : false,
	optionsActive: false,

	initialize: function () {
		this.model.on('change', this.render, this);
	},

	render: function () {
		if (this.model.get(4).indexOf('wp-menu-separator') > -1) {
			this.template = _.template('<div class="separator"></div>');
		}

		this.$el.html(this.template(this.model.toJSON()));
		return this;
	},

	events: {
		'click': 'toggleOptions'
	},

	toggleOptions: function (e) {
		if (!this.isEditing) {
			return;
		}

		e.preventDefault();

		if (jQuery(e.target).parents('.amm-menu-item-options').length > 0) {
			return;
		}

		this.optionsActive = !this.optionsActive;

		var model, itemSlug = jQuery(e.target).parents('[data-slug]').first().attr('data-slug');

		if (this.model.get(2) === itemSlug) {
			model = this.model;
		} else {
			model = _.find(this.model.get('children').models, function (el) {
				return el.get(2) === itemSlug;
			});
		}

		this.optionsView = new MenuItemOptionsView({model: model});
		this.render();
		this.$el.toggleClass('amm-is-editing', this.optionsActive);

		if (this.optionsActive) {
			this.$el.append(this.optionsView.render().$el);
		}
	}

});

module.exports = MenuItemView;
