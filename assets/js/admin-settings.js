/* OEC Theme — admin-settings.js */
(function ($) {
  'use strict';

  var config = window.oecAdmin || {};

  /* ============================================================
     COLOR PICKERS
     ============================================================ */
  $('.oec-color-picker').wpColorPicker({
    change: function (event, ui) {
      var key   = $(this).data('key');
      var color = ui.color.toString();
      updateSwatch(key, color);
    },
    clear: function () {
      var $input    = $(this).closest('.wp-picker-container').find('.oec-color-picker');
      var key       = $input.data('key');
      var defColor  = $input.data('default-color');
      // After clear, WP sets the input back to default; wait a tick
      setTimeout(function () {
        updateSwatch(key, defColor);
      }, 10);
    },
  });

  function updateSwatch(key, color) {
    $('#swatch-' + key).css('background', color);
    $('#swatch-hex-' + key).text(color);
  }

  /* ============================================================
     RESET COLORS
     ============================================================ */
  $('#oec-reset-colors').on('click', function () {
    $('.oec-color-picker').each(function () {
      var $input = $(this);
      var def    = $input.data('default-color');
      // Update the WP color picker widget
      $input.wpColorPicker('color', def);
      updateSwatch($input.data('key'), def);
    });
  });

  /* ============================================================
     LOGO MEDIA UPLOADER
     ============================================================ */
  var mediaFrame;

  $('#oec-upload-logo').on('click', function (e) {
    e.preventDefault();

    if (mediaFrame) {
      mediaFrame.open();
      return;
    }

    mediaFrame = wp.media({
      title:    config.mediaTitle  || 'Seleccionar logo',
      button:   { text: config.mediaButton || 'Usar como logo' },
      library:  { type: 'image' },
      multiple: false,
    });

    mediaFrame.on('select', function () {
      var attachment = mediaFrame.state().get('selection').first().toJSON();
      var url        = attachment.url;

      $('#oec-logo-id').val(attachment.id);
      $('#oec-logo-url').val(url);

      var $preview = $('#oec-logo-preview');
      $preview.html(
        '<img src="' + url + '" alt="Logo" class="oec-logo-img" ' +
        'style="max-height:58px;max-width:220px;width:auto;display:block;">'
      );

      $('#oec-upload-logo').text(config.changeLabel || 'Cambiar logo');
      $('#oec-remove-logo').show();
    });

    mediaFrame.open();
  });

  /* Remove logo */
  $('#oec-remove-logo').on('click', function (e) {
    e.preventDefault();
    $('#oec-logo-id').val('');
    $('#oec-logo-url').val('');
    $('#oec-logo-preview').html(
      '<span class="oec-logo-placeholder">' + (config.noLogo || 'Sin logo cargado') + '</span>'
    );
    $('#oec-upload-logo').text(config.uploadLabel || 'Subir logo');
    $(this).hide();
  });

  /* ============================================================
     TRACKER BADGES (live update on input)
     ============================================================ */
  $('.oec-tracker-input').on('input', function () {
    var $input  = $(this);
    var tracker = $input.data('tracker');
    var val     = $.trim($input.val());
    var $badge  = $('#status-' + tracker);

    if (val) {
      $badge.html('<span class="oec-badge oec-badge--on">Activo</span>');
    } else {
      $badge.html('<span class="oec-badge oec-badge--off">Inactivo</span>');
    }
  });

})(jQuery);
