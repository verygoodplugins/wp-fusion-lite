var addRuleType = BBLogic.api.addRuleType
var __ = BBLogic.i18n.__

addRuleType( 'wp-fusion/user-tags', {
  label: __( 'User\'s CRM Tags' ),
  category: 'user',
  form: {
    operator: {
      type: 'operator',
      operators: [
        'contains',
        'does_not_contain',
      ],
    },
	compare: {
		type: 'select',
		route: 'wp-fusion/available-tags',
	},
  },
} );