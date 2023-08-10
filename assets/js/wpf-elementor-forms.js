(function ($) {
  var wpfElementorFormsIntegration = {
    fields: wpfElementorObject.fields,

    getName() {
      return "wpfusion";
    },

    onElementChange(setting) {
      if (setting.indexOf("wpfusion") !== -1) {
        this.updateFieldsMap();
      }
    },

    onSectionActive() {
      this.updateFieldsMap();
    },

    updateFieldsMap() {
      this.getEditorControlView("wpfusion_fields_map").updateMap();
    },
  };

  /**
   * Gh Fields Map.
   */
  var FieldsMap = {
    onBeforeRender() {

      this.$el.hide();
    },

    updateMap() {
      const savedMapObject = {};
      this.collection.each((model) => {
        savedMapObject[model.get("local_id")] = model.get("remote_id");
      });

      this.collection.reset();

      var fields = this.elementSettingsModel.get("form_fields").models;

      _.each(fields, (field) => {
        const model = {
          local_id: field.get("custom_id"),
          local_label: field.get("field_label"),
          remote_id: savedMapObject[field.get("custom_id")]
            ? savedMapObject[field.get("custom_id")]
            : "",
        };

        this.collection.add(model);
      });

      this.render();
    },

    getFieldOptions() {
      return elementorPro.modules.forms.wpfusion.fields;
    },

    onRender() {
      this.children.each((view) => {
        var localFieldsControl = view.children.last(),
          options = {
            "": "- " + elementor.translate("None") + " -",
          },
          label = view.model.get("local_label");

        _.each(this.getFieldOptions(), (model, index) => {
          options[model.remote_id] =
            model.remote_label || "Field #" + (index + 1);
        });

        localFieldsControl.model.set("label", label);
        localFieldsControl.model.set("options", options);

        localFieldsControl.render();

        view.$el.find(".elementor-repeater-row-tools").hide();
        view.$el
          .find(".elementor-repeater-row-controls")
          .removeClass("elementor-repeater-row-controls")
          .find(".elementor-control")
          .css({
            padding: "10px 0",
          });
      });

      this.$el.find(".elementor-button-wrapper").remove();

      if (this.children.length) {
        this.$el.show();
      }
    },
  };

  var wpfElementorForms = {
    init: function () {

        elementor.addControlView(
          "wpf_fields_map",
          elementor.modules.controls.Fields_map.extend(FieldsMap)
        );

        elementorPro.modules.forms.wpfusion = {
          ...elementorPro.modules.forms.activecampaign,
          ...wpfElementorFormsIntegration,
        };

        elementorPro.modules.forms.wpfusion.addSectionListener(
          "section_wpfusion",
          () => {
            elementorPro.modules.forms.wpfusion.onSectionActive();
          }
        );

    },
  };

  setTimeout(() => {
    wpfElementorForms.init();
  }, 3000);
})(jQuery);
