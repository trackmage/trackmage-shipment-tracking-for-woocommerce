(($, window, document, undefined) => {
  const params = {
    main: trackmageAdmin,
    statusManager: trackmageAdminStatusManager
  };
  /**
   * Edit status.
   */
  $(document).on("click", "#statusManager .row-actions .edit-status", function (
    e
  ) {
    let tbody = $(this).closest("tbody");
    let row = $(this).closest("tr");
    let isCustom = $(row).data("status-is-custom");
    let name = $(row).data("status-name");
    let currentSlug = $(row).data("status-slug");
    let slug = $(row).data("status-slug");
    let alias = $(row).data("status-alias");

    // Hide the selected row,
    // toggle hidden rows
    // and remove any other open edit row if any.
    $(tbody)
      .children("tr.hidden")
      .removeClass("hidden");
    $(row).addClass("hidden");
    $(tbody)
      .children(".adjust-odd-even")
      .remove();
    $(tbody)
      .children(".inline-edit-row")
      .remove();

    // Append edit row.
    let editRow = $(`
          <tr class="hidden adjust-odd-even"></tr>
          <tr id="edit-${slug}" class="inline-edit-row">
            <td colspan="4" class="colspanchange">
              <fieldset class="inline-edit-col-left">
                <legend class="inline-edit-legend">${
                  params.statusManager.i18n.edit
                }</legend>
                <div class="inline-edit-col">
                  <label>
                    <span class="title">${params.statusManager.i18n.name}</span>
                    <span class="input-text-wrap"><input type="text" name="status_name" value="" /></span>
                  </label>
                  <label>
                    <span class="title">${params.statusManager.i18n.slug}</span>
                    <span class="input-text-wrap"><input type="text" name="status_slug" value="" /></span>
                  </label>
                  <label>
                    <span class="title">${
                      params.statusManager.i18n.alias
                    }</span>
                    <select name="status_alias">
                      <option value="">${
                        params.main.i18n.noSelect
                      }</option>
                      ${Object.keys(params.statusManager.aliases)
                        .map(
                          key =>
                            `<option value="${key}">${
                              params.statusManager.aliases[key]
                            }</option>`
                        )
                        .join("")}
                    </select>
                  </label>
                </div>
              </fieldset>
              <div class="submit inline-edit-save">
                <button type="button" class="button cancel alignleft">${
                  params.statusManager.i18n.cancel
                }</button>
                <button type="button" class="button button-primary save alignright">${
                  params.statusManager.i18n.update
                }</button>
                <span class="spinner"></span>
                <br class="clear">
              </div>
            </div>
            </td>
          </tr>
        `);

    $(editRow).insertAfter(row);

    // Current values.
    $(editRow)
      .find('[name="status_name"]')
      .val(name);
    $(editRow)
      .find('[name="status_slug"]')
      .val(slug);
    $(editRow)
      .find('[name="status_alias"]')
      .val(alias);

    // Disable slug field if not a custom status.
    if (isCustom != 1) {
      $(editRow)
        .find('[name="status_slug"]')
        .prop("disabled", true);
    }

    // On cancel.
    $(editRow)
      .find("button.cancel")
      .on("click", function () {
        $(editRow).remove();
        $(row).removeClass("hidden");
      });

    // On save.
    $(editRow)
      .find("button.save")
      .on("click", function () {
        let save = $(this);
        let name = $(editRow).find('[name="status_name"]');
        let slug = $(editRow).find('[name="status_slug"]');
        let alias = $(editRow).find('[name="status_alias"]');

        // Request data.
        let data = {
          action: "trackmage_status_manager_save",
          name: $(name).val(),
          currentSlug: currentSlug,
          slug: $(slug).val(),
          alias: $(alias).val(),
          isCustom: isCustom
        };

        // Response message.
        let message = "";

        $.ajax({
          url: params.main.urls.ajax,
          method: "post",
          data: data,
          beforeSend: function () {
            trackmageToggleSpinner(save, "activate");
            trackmageToggleFormElement(save, "disable");
          },
          success: function (response) {
            trackmageToggleSpinner(save, "deactivate");
            trackmageToggleFormElement(save, "enable");

            if (response.data.status === "success") {
              updateRow(
                response.data.result.name,
                response.data.result.slug,
                response.data.result.alias
              );
              message = params.statusManager.i18n.successUpdateStatus;
              $(editRow).remove();
              $(row)
                .removeClass("hidden")
                .effect("highlight", {
                  color: "#c3f3d7"
                }, 500);
            } else if (response.data.errors) {
              message = response.data.errors.join(" ");
              $(editRow)
                .removeClass("hidden")
                .effect("highlight", {
                  color: "#ffe0e3"
                }, 500);
            } else {
              message = params.main.i18n.unknownError;
            }

            // Response notification.
            trackmageAlert(
              params.statusManager.i18n.updateStatus,
              message,
              response.data.status,
              true
            );
          },
          error: function () {
            trackmageToggleSpinner(save, "deactivate");
            trackmageToggleFormElement(save, "enable");

            message = params.main.i18n.unknownError;

            // Response notification.
            trackmageAlert(
              params.statusManager.i18n.updateStatus,
              message,
              response.data.status,
              true
            );
          }
        });
      });

    function updateRow(name, slug, alias) {
      $(row)
        .find("[data-update-status-name]")
        .html(name);
      $(row).data("status-name", name);

      $(row)
        .find("[data-update-status-slug]")
        .html(slug);
      $(row).data("status-slug", slug);

      $(row)
        .find("[data-update-status-alias]")
        .html(
          params.statusManager.aliases[alias] ?
          params.statusManager.aliases[alias] :
          ""
        );
      $(row).data("status-alias", alias);
    }
  });

  /**
   * Add status.
   */
  $("#statusManager .add-status #addStatus").on("click", function (e) {
    let add = $(this);

    let name = $('#statusManager .add-status [name="status_name"]');
    let slug = $('#statusManager .add-status [name="status_slug"]');
    let alias = $('#statusManager .add-status [name="status_alias"]');

    // Request data.
    let data = {
      action: "trackmage_status_manager_add",
      name: $(name).val(),
      slug: $(slug).val(),
      alias: $(alias).val()
    };

    // Response message.
    let message = "";

    $.ajax({
      url: params.main.urls.ajax,
      method: "post",
      data: data,
      beforeSend: function () {
        trackmageToggleSpinner(add, "activate");
        trackmageToggleFormElement(add, "disable");
      },
      success: function (response) {
        trackmageToggleSpinner(add, "deactivate");
        trackmageToggleFormElement(add, "enable");

        if (response.data.status === "success") {
          addRow(
            response.data.result.name,
            response.data.result.slug,
            response.data.result.alias
          );
          message = params.statusManager.i18n.successAddStatus;
        } else if (response.data.errors) {
          message = response.data.errors.join(" ");
        } else {
          message = params.main.i18n.unknownError;
        }

        // Response notification.
        trackmageAlert(
          params.statusManager.i18n.addStatus,
          message,
          response.data.status,
          true
        );
      },
      error: function () {
        trackmageToggleSpinner(add, "deactivate");
        trackmageToggleFormElement(add, "enable");

        message = params.main.i18n.unknownError;

        // Response notification.
        trackmageAlert(
          params.statusManager.i18n.addStatus,
          message,
          response.data.status,
          true
        );
      }
    });

    function addRow(name, slug, alias) {
      let statusManagerBody = $("#statusManager tbody");
      let row = `
            <tr id="status-${slug}" data-status-name="${name}" data-status-slug="${slug}" data-status-alias="${alias}" data-status-is-cusotm="1">
              <td>
                <span data-update-status-name>${name}</span>
                <div class="row-actions">
                  <span class="inline"><button type="button" class="button-link edit-status">${
                    params.statusManager.i18n.edit
                  }</button> | </span>
                  <span class="inline delete"><button type="button" class="button-link delete-status">${
                    params.statusManager.i18n.delete
                  }</button></span>
                </div>
              </td>
              <td><span data-update-status-slug>${slug}</span></td>
              <td colspan="2"><span data-update-status-alias>${
                params.statusManager.aliases.hasOwnProperty(alias) ? params.statusManager.aliases[alias] : ''
              }</span></td>
            </tr>
          `;

      $(row)
        .appendTo(statusManagerBody)
        .effect("highlight", {
          color: "#c3f3d7"
        }, 500);
    }
  });

  /**
   * Delete status.
   */
  $(document).on(
    "click",
    "#statusManager .row-actions .delete-status",
    function (e) {
      if (confirm(params.statusManager.i18n.confirmDeleteStatus)) {
        let row = $(this).closest("tr");
        let slug = $(row).data("status-slug");

        // Request data.
        let data = {
          action: "trackmage_status_manager_delete",
          slug: slug
        };

        // Response message.
        let message = "";

        $.ajax({
          url: params.main.urls.ajax,
          method: "post",
          data: data,
          beforeSend: function () {},
          success: function (response) {
            if (response.data.status === "success") {
              deleteRow(response.data.result.slug);
              message = params.statusManager.i18n.successDeleteStatus;
            } else if (response.data.errors) {
              message = response.data.errors.join(" ");
            } else {
              message = params.main.i18n.unknownError;
            }

            // Response notification.
            trackmageAlert(
              params.statusManager.i18n.deleteStatus,
              message,
              response.data.status,
              true
            );
          },
          error: function () {
            message = params.main.i18n.unknownError;

            // Response notification.
            trackmageAlert(
              params.statusManager.i18n.deleteStatus,
              message,
              response.data.status,
              true
            );
          }
        });

        function deleteRow(slug) {
          $(row).effect("highlight", {
            color: "#ffe0e3"
          }, 500);
          setTimeout(() => {
            $(row).remove();
          }, 500);
        }
      }
    }
  );

  $("select#trackmage_sync_statuses").selectWoo({
    width: "350px",
    ajax: {
      url: params.main.urls.ajax,
      method: "post",
      dataType: "json",
      delay: 250,
      data: function (params) {
        return {
          term: params.statusManager.term,
          action: "trackmage_wooselect_order_statuses"
        };
      },
      processResults: function (data, params) {
        return {
          results: data.filter((s, index) => {
            let term =
              typeof params.statusManager.term === "undefined" ?
              "" :
              params.statusManager.term;
            if (
              term === "" ||
              (s.id
                .toLowerCase()
                .includes(params.statusManager.term.toLowerCase()) ||
                s.text
                .toLowerCase()
                .includes(params.statusManager.term.toLowerCase()))
            ) {
              return true;
            }

            return false;
          })
        };
      }
    }
  });
})(jQuery, window, document);
