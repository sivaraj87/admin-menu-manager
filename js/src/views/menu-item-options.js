var MenuItemOptionsView = Backbone.View.extend({
	tagName : 'div',
	template: require('templates/menu-item-options'),
	className: 'wp-submenu wp-submenu-wrap sub-open amm-menu-item-options',

	render: function () {
		this.$el.html(this.template(this.model.toJSON()));
		return this;
	},

	events: {},

});

module.exports = MenuItemOptionsView;
